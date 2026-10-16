<?php
/* Copyright (C) 2026	Infomatical
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    feedimport/class/feedimportparser.class.php
 * \ingroup feedimport
 * \brief   Reading feed files (CSV, XML), XSD schemas and two-row mapping files
 */

/**
 * Reads the items of a feed file, one at a time, as arrays of field => text value.
 *
 * Fields are named by the CSV column titles, or, for XML, by their path relative to the item element:
 * "price", "stock/available", "@id" (attribute of the item), "image/@url". Only the first occurrence of a
 * repeated element is kept.
 *
 * Nothing here touches the database, so it can be tested on its own.
 */
class FeedImportParser
{
	/** @var string Last error */
	public $error = '';

	/** @var string 'csv' or 'xml' */
	public $filetype = 'csv';

	/** @var string CSV delimiter; '' to detect it (; , tab or |) */
	public $delimiter = '';
	/** @var string CSV enclosure */
	public $enclosure = '"';
	/** @var string Encoding of the file: UTF-8, ISO-8859-1, Windows-1252... */
	public $encoding = 'UTF-8';
	/** @var int Line of the CSV column titles (1 = first line); lines before it are ignored */
	public $headerline = 1;

	/** @var string XML item element: an absolute path (/catalog/products/product) or an element name (product) */
	public $itempath = '';

	/**
	 * Fields of the file: CSV column titles, or the XML fields of the first items
	 *
	 * @param	string		$file		Path of the file
	 * @param	int			$sample		XML: number of items to look at
	 * @return	string[]|null			Null on error
	 */
	public function fields($file, $sample = 20)
	{
		if ($this->filetype == 'csv') {
			$fh = $this->openCsv($file);
			if (!$fh) {
				return null;
			}
			$header = $this->readCsvHeader($fh);
			fclose($fh);
			return $header;
		}

		$fields = array();
		$n = 0;
		foreach ($this->rows($file) as $row) {
			foreach (array_keys($row['data']) as $field) {
				$fields[$field] = $field;
			}
			if (++$n >= $sample) {
				break;
			}
		}
		if ($this->error !== '') {
			return null;
		}
		return array_values($fields);
	}

	/**
	 * Items of the file. Each is array('line' => line or item number, 'data' => array(field => value)).
	 * On error the iteration stops and $this->error is set.
	 *
	 * @param	string		$file		Path of the file
	 * @return	Generator<int,array{line:int,data:array<string,string>,columns?:int,expected?:int}>
	 */
	public function rows($file)
	{
		$this->error = '';
		if ($this->filetype == 'xml') {
			return $this->xmlRows($file);
		}
		return $this->csvRows($file);
	}

	/**
	 * Validate an XML file against an XSD, reading it as a stream
	 *
	 * @param	string		$file		XML file
	 * @param	string		$xsd		XSD file
	 * @param	int			$max		Maximum number of errors returned
	 * @return	string[]				Errors (empty if the file is valid)
	 */
	public function validateXml($file, $xsd, $max = 10)
	{
		$errors = array();
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$reader = new XMLReader();
		if (!@$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
			libxml_use_internal_errors($previous);
			return array('Cannot open '.basename($file));
		}
		if (!@$reader->setSchema($xsd)) {
			$errors[] = 'Cannot load the XSD: '.$this->libxmlErrors(3);
			$reader->close();
			libxml_use_internal_errors($previous);
			return $errors;
		}
		while (@$reader->read()) {
			foreach (libxml_get_errors() as $e) {
				if (count($errors) < $max) {
					$errors[] = 'Line '.$e->line.': '.trim($e->message);
				}
			}
			libxml_clear_errors();
			if (count($errors) >= $max) {
				break;
			}
		}
		foreach (libxml_get_errors() as $e) {
			if (count($errors) < $max) {
				$errors[] = 'Line '.$e->line.': '.trim($e->message);
			}
		}
		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $errors;
	}

	/**
	 * What an XSD says about the feed: the repeating item element and its fields
	 *
	 * The item element is the first element allowed more than once (maxOccurs="unbounded" or > 1) that has child
	 * elements or attributes. A field's Dolibarr target is read from an attribute named "field" in a namespace
	 * containing "dolibarr" (e.g. xmlns:dolibarr="urn:dolibarr" ... dolibarr:field="buy_price") or from
	 * <xs:appinfo><dolibarr:field>buy_price</dolibarr:field></xs:appinfo>.
	 *
	 * @param	string		$xsdfile	Path of the XSD
	 * @return	array{itempath:string,fields:array<string,array{type:string,target:string}>}|null	Null on error
	 */
	public function readXsd($xsdfile)
	{
		$this->error = '';
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$ok = $dom->load($xsdfile, LIBXML_NONET);
		if (!$ok) {
			$this->error = 'Invalid XSD: '.$this->libxmlErrors(3);
			libxml_use_internal_errors($previous);
			return null;
		}
		libxml_use_internal_errors($previous);

		$xp = new DOMXPath($dom);
		$xp->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

		$types = array();
		foreach ($xp->query('/xs:schema/xs:complexType[@name]|/xs:schema/xs:simpleType[@name]') as $node) {
			/** @var DOMElement $node */
			$types[$node->getAttribute('name')] = $node;
		}
		$elements = array();
		foreach ($xp->query('/xs:schema/xs:element[@name]') as $node) {
			/** @var DOMElement $node */
			$elements[$node->getAttribute('name')] = $node;
		}
		if (empty($elements)) {
			$this->error = 'The XSD declares no element';
			return null;
		}

		foreach ($elements as $name => $element) {
			$found = $this->xsdFindItem($xp, $element, '/'.$name, $types, $elements, 0);
			if ($found) {
				$fields = array();
				$this->xsdCollectFields($xp, $found['node'], '', $types, $elements, $fields, 0);
				return array('itempath' => $found['path'], 'fields' => $fields);
			}
		}
		$this->error = 'The XSD has no repeating element (maxOccurs="unbounded") with fields';
		return null;
	}

	/**
	 * Read a two-row mapping file: first row the feed's fields, second row the Dolibarr field of each
	 *
	 * @param	string					$file		CSV file
	 * @param	array<string,string>	$targets	feedimportTargetFields()
	 * @param	string[]				$unknown	Filled with the second-row values that are no Dolibarr field
	 * @return	array<string,string>|null		feed field => Dolibarr field code; null on error
	 */
	public function readMappingFile($file, $targets, &$unknown = array())
	{
		$this->error = '';
		$unknown = array();
		$saved = array($this->delimiter, $this->headerline, $this->encoding);
		$this->delimiter = '';
		$this->headerline = 1;
		$this->encoding = 'UTF-8';
		$fh = $this->openCsv($file);
		$mapping = null;
		if ($fh) {
			$header = $this->readCsvHeader($fh);
			$second = $header === null ? false : fgetcsv($fh, 0, $this->delimiter, $this->enclosure, '');
			if ($header === null) {
				// error already set
			} elseif (!is_array($second)) {
				$this->error = 'The mapping file must have two rows: the feed\'s column titles, then the Dolibarr field of each';
			} else {
				$mapping = array();
				foreach ($header as $i => $feedfield) {
					$text = isset($second[$i]) ? trim($this->toUtf8((string) $second[$i])) : '';
					if ($feedfield === '' || $text === '') {
						continue;
					}
					$code = feedimportResolveTargetField($text, $targets);
					if ($code === '') {
						$unknown[] = $text;
						continue;
					}
					$mapping[$feedfield] = $code;
				}
			}
			fclose($fh);
		}
		list($this->delimiter, $this->headerline, $this->encoding) = $saved;
		return $mapping;
	}

	/**
	 * Two-row mapping file for a list of fields and their current mapping
	 *
	 * @param	string[]				$fields		Feed fields
	 * @param	array<string,string>	$mapping	feed field => Dolibarr field code
	 * @return	string						CSV content
	 */
	public static function buildMappingFile($fields, $mapping)
	{
		$fh = fopen('php://temp', 'w+');
		fputcsv($fh, array_values($fields), ';', '"', '');
		$second = array();
		foreach ($fields as $field) {
			$second[] = isset($mapping[$field]) ? $mapping[$field] : '';
		}
		fputcsv($fh, $second, ';', '"', '');
		rewind($fh);
		$content = (string) stream_get_contents($fh);
		fclose($fh);
		return $content;
	}


	/*
	 * CSV
	 */

	/**
	 * Open a CSV file, detecting the delimiter if needed
	 *
	 * @param	string		$file	Path
	 * @return	resource|false
	 */
	private function openCsv($file)
	{
		$fh = @fopen($file, 'r');
		if (!$fh) {
			$this->error = 'Cannot open '.basename($file);
			return false;
		}
		if ($this->delimiter === '') {
			$this->delimiter = $this->detectDelimiter($fh);
		} elseif ($this->delimiter == '\t') {
			$this->delimiter = "\t";
		}
		return $fh;
	}

	/**
	 * Delimiter used by the first lines of a CSV file
	 *
	 * @param	resource	$fh		File, rewound afterwards
	 * @return	string
	 */
	private function detectDelimiter($fh)
	{
		$sample = '';
		$lines = max(5, $this->headerline + 2);
		for ($i = 0; $i < $lines; $i++) {
			$line = fgets($fh);
			if ($line === false) {
				break;
			}
			$sample .= $line;
		}
		rewind($fh);
		$best = ',';
		$bestcount = 0;
		foreach (array(';', ',', "\t", '|') as $candidate) {
			$count = substr_count($sample, $candidate);
			if ($count > $bestcount) {
				$best = $candidate;
				$bestcount = $count;
			}
		}
		return $best;
	}

	/**
	 * Read up to and including the header line
	 *
	 * @param	resource		$fh		File
	 * @return	string[]|null			Column titles (trimmed, UTF-8), null if the file has no such line
	 */
	private function readCsvHeader($fh)
	{
		$headerline = max(1, $this->headerline);
		for ($i = 1; $i < $headerline; $i++) {
			if (fgets($fh) === false) {
				$this->error = 'The file ends before the header line '.$this->headerline;
				return null;
			}
		}
		$header = fgetcsv($fh, 0, $this->delimiter, $this->enclosure, '');
		if (!is_array($header) || (count($header) == 1 && $header[0] === null)) {
			$this->error = 'The file is empty';
			return null;
		}
		foreach ($header as $i => $title) {
			$title = $this->toUtf8((string) $title);
			if ($i == 0) {
				$title = preg_replace('/^\xEF\xBB\xBF/', '', $title);
			}
			$header[$i] = trim((string) $title);
		}
		return $header;
	}

	/**
	 * Items of a CSV file
	 *
	 * @param	string		$file	Path
	 * @return	Generator<int,array{line:int,data:array<string,string>,columns?:int,expected?:int}>
	 */
	private function csvRows($file)
	{
		$fh = $this->openCsv($file);
		if (!$fh) {
			return;
		}
		$header = $this->readCsvHeader($fh);
		if ($header === null) {
			fclose($fh);
			return;
		}
		$line = max(1, $this->headerline);
		$count = count($header);
		while (($values = fgetcsv($fh, 0, $this->delimiter, $this->enclosure, '')) !== false) {
			$line++;
			if (count($values) == 1 && ($values[0] === null || trim((string) $values[0]) === '')) {
				continue;	// blank line
			}
			$data = array();
			for ($i = 0; $i < $count; $i++) {
				if ($header[$i] === '') {
					continue;
				}
				$data[$header[$i]] = isset($values[$i]) ? trim($this->toUtf8((string) $values[$i])) : '';
			}
			yield array('line' => $line, 'data' => $data, 'columns' => count($values), 'expected' => $count);
		}
		fclose($fh);
	}

	/**
	 * Text in UTF-8 from the file's encoding
	 *
	 * @param	string	$text	Text
	 * @return	string
	 */
	private function toUtf8($text)
	{
		$encoding = strtoupper($this->encoding);
		if ($encoding === '' || $encoding == 'UTF-8' || $encoding == 'UTF8') {
			return $text;
		}
		$converted = @mb_convert_encoding($text, 'UTF-8', $encoding);
		return $converted === false ? $text : $converted;
	}


	/*
	 * XML
	 */

	/**
	 * Items of an XML file, read as a stream so large files do not need to fit in memory
	 *
	 * @param	string		$file	Path
	 * @return	Generator<int,array{line:int,data:array<string,string>,columns?:int,expected?:int}>
	 */
	private function xmlRows($file)
	{
		if (trim($this->itempath) === '') {
			$this->error = 'No item element is set for this XML feed';
			return;
		}
		$itempath = rtrim(trim($this->itempath), '/');
		$absolute = (strpos($itempath, '/') === 0);
		$itemname = $absolute ? substr($itempath, (int) strrpos($itempath, '/') + 1) : $itempath;

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = new XMLReader();
		if (!@$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
			$this->error = 'Cannot open '.basename($file);
			libxml_use_internal_errors($previous);
			return;
		}

		$stack = array();
		$n = 0;
		$ok = @$reader->read();
		while ($ok) {
			if ($reader->nodeType == XMLReader::ELEMENT) {
				$depth = $reader->depth;
				$stack = array_slice($stack, 0, $depth);
				$stack[$depth] = $reader->localName;
				$match = ($reader->localName === $itemname) && (!$absolute || '/'.implode('/', $stack) === $itempath);
				if ($match) {
					$node = @$reader->expand();
					$n++;
					if ($node instanceof DOMElement) {
						$data = array();
						$this->flattenXml($node, '', $data);
						yield array('line' => $n, 'data' => $data);
					}
					$ok = @$reader->next();
					continue;
				}
			}
			$ok = @$reader->read();
		}
		$errors = $this->libxmlErrors(3);
		if ($errors !== '') {
			$this->error = 'Invalid XML: '.$errors;
		} elseif ($n == 0) {
			$this->error = 'No element '.$itempath.' in the file';
		}
		$reader->close();
		libxml_use_internal_errors($previous);
	}

	/**
	 * Fields of an XML item: attributes as @name, child elements as their path, first occurrence only
	 *
	 * @param	DOMElement				$node		Element
	 * @param	string					$prefix		Path of the element relative to the item ('' for the item)
	 * @param	array<string,string>	$data		Filled
	 * @return	void
	 */
	private function flattenXml($node, $prefix, &$data)
	{
		foreach ($node->attributes as $attribute) {
			$key = ($prefix === '' ? '' : $prefix.'/').'@'.$attribute->localName;
			if (!isset($data[$key])) {
				$data[$key] = trim($attribute->value);
			}
		}
		$haschild = false;
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$haschild = true;
				$key = ($prefix === '' ? '' : $prefix.'/').$child->localName;
				if (array_key_exists($key, $data)) {
					continue;	// repeated element: first one only
				}
				$this->flattenXml($child, $key, $data);
			}
		}
		if (!$haschild && $prefix !== '') {
			$data[$prefix] = trim($node->textContent);
		}
	}

	/**
	 * Find the repeating item element under an XSD element
	 *
	 * @param	DOMXPath					$xp			XPath on the XSD
	 * @param	DOMElement					$element	xs:element
	 * @param	string						$path		Its path in the XML
	 * @param	array<string,DOMElement>	$types		Named types
	 * @param	array<string,DOMElement>	$elements	Top-level elements
	 * @param	int							$depth		Recursion depth
	 * @return	array{node:DOMElement,path:string}|null
	 */
	private function xsdFindItem($xp, $element, $path, $types, $elements, $depth)
	{
		if ($depth > 15) {
			return null;
		}
		// maxOccurs is on the reference when the element is declared elsewhere (ref="...")
		$max = $element->getAttribute('maxOccurs');
		// or on the sequence around it: <xs:sequence maxOccurs="unbounded"><xs:element name="product"...
		if ($max === '' && $element->parentNode instanceof DOMElement && in_array($element->parentNode->localName, array('sequence', 'choice'))) {
			$max = $element->parentNode->getAttribute('maxOccurs');
		}
		$element = $this->xsdResolveRef($element, $elements);
		if ($max === '') {
			$max = $element->getAttribute('maxOccurs');
		}
		$complex = $this->xsdComplexType($xp, $element, $types);
		if (!$complex) {
			return null;
		}
		if ($depth > 0 && ($max == 'unbounded' || (is_numeric($max) && $max > 1))) {
			return array('node' => $element, 'path' => $path);
		}
		foreach ($xp->query('.//xs:element', $complex) as $child) {
			/** @var DOMElement $child */
			if ($this->xsdOwnerType($child) !== $complex) {
				continue;	// nested in a deeper inline type: reached through recursion
			}
			$resolved = $this->xsdResolveRef($child, $elements);
			$found = $this->xsdFindItem($xp, $child, $path.'/'.$resolved->getAttribute('name'), $types, $elements, $depth + 1);
			if ($found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Fields of an XSD element (its attributes and child elements, recursively), as named by flattenXml()
	 *
	 * @param	DOMXPath					$xp			XPath on the XSD
	 * @param	DOMElement					$element	xs:element
	 * @param	string						$prefix		Path prefix ('' for the item)
	 * @param	array<string,DOMElement>	$types		Named types
	 * @param	array<string,DOMElement>	$elements	Top-level elements
	 * @param	array<string,array{type:string,target:string}>	$fields		Filled
	 * @param	int							$depth		Recursion depth
	 * @return	void
	 */
	private function xsdCollectFields($xp, $element, $prefix, $types, $elements, &$fields, $depth)
	{
		if ($depth > 10) {
			return;
		}
		$element = $this->xsdResolveRef($element, $elements);
		$complex = $this->xsdComplexType($xp, $element, $types);
		if (!$complex) {
			if ($prefix !== '') {
				$fields[$prefix] = array('type' => $this->xsdSimpleType($xp, $element, $types), 'target' => $this->xsdTarget($xp, $element));
			}
			return;
		}
		foreach ($xp->query('.//xs:attribute[@name]', $complex) as $attribute) {
			/** @var DOMElement $attribute */
			if ($this->xsdOwnerType($attribute) !== $complex) {
				continue;
			}
			$key = ($prefix === '' ? '' : $prefix.'/').'@'.$attribute->getAttribute('name');
			$fields[$key] = array('type' => $this->xsdSimpleType($xp, $attribute, $types), 'target' => $this->xsdTarget($xp, $attribute));
		}
		$haschild = false;
		foreach ($xp->query('.//xs:element', $complex) as $child) {
			/** @var DOMElement $child */
			if ($this->xsdOwnerType($child) !== $complex) {
				continue;
			}
			$haschild = true;
			$resolved = $this->xsdResolveRef($child, $elements);
			$key = ($prefix === '' ? '' : $prefix.'/').$resolved->getAttribute('name');
			$this->xsdCollectFields($xp, $child, $key, $types, $elements, $fields, $depth + 1);
		}
		// Element with attributes and text content (xs:simpleContent)
		if (!$haschild && $prefix !== '' && $xp->query('xs:simpleContent', $complex)->length) {
			$fields[$prefix] = array('type' => $this->xsdSimpleType($xp, $element, $types), 'target' => $this->xsdTarget($xp, $element));
		}
	}

	/**
	 * The element a ref="..." points to, or the element itself
	 *
	 * @param	DOMElement					$element	xs:element
	 * @param	array<string,DOMElement>	$elements	Top-level elements
	 * @return	DOMElement
	 */
	private function xsdResolveRef($element, $elements)
	{
		if ($element->hasAttribute('ref')) {
			$name = $this->localName($element->getAttribute('ref'));
			if (isset($elements[$name])) {
				return $elements[$name];
			}
		}
		return $element;
	}

	/**
	 * Complex type of an element (inline or named), if it has one
	 *
	 * @param	DOMXPath					$xp			XPath
	 * @param	DOMElement					$element	xs:element
	 * @param	array<string,DOMElement>	$types		Named types
	 * @return	DOMElement|null
	 */
	private function xsdComplexType($xp, $element, $types)
	{
		$inline = $xp->query('xs:complexType', $element);
		if ($inline->length) {
			$node = $inline->item(0);
			return $node instanceof DOMElement ? $node : null;
		}
		$type = $this->localName($element->getAttribute('type'));
		if ($type !== '' && isset($types[$type]) && $types[$type]->localName == 'complexType') {
			return $types[$type];
		}
		return null;
	}

	/**
	 * The xs:complexType a node belongs to (its nearest complexType ancestor)
	 *
	 * @param	DOMNode		$node	Node
	 * @return	DOMNode|null
	 */
	private function xsdOwnerType($node)
	{
		$parent = $node->parentNode;
		while ($parent && !($parent instanceof DOMElement && $parent->localName == 'complexType')) {
			$parent = $parent->parentNode;
		}
		return $parent;
	}

	/**
	 * XSD type of a simple element or attribute, e.g. "decimal", "string"
	 *
	 * @param	DOMXPath					$xp		XPath
	 * @param	DOMElement					$node	xs:element or xs:attribute
	 * @param	array<string,DOMElement>	$types	Named types
	 * @return	string
	 */
	private function xsdSimpleType($xp, $node, $types)
	{
		$type = $node->getAttribute('type');
		for ($i = 0; $i < 5; $i++) {
			$local = $this->localName($type);
			if ($local !== '' && isset($types[$local])) {
				$restriction = $xp->query('.//xs:restriction|.//xs:extension', $types[$local]);
				$type = $restriction->length && $restriction->item(0) instanceof DOMElement ? $restriction->item(0)->getAttribute('base') : '';
				continue;
			}
			break;
		}
		if ($type === '') {
			$restriction = $xp->query('.//xs:restriction|.//xs:extension', $node);
			$type = $restriction->length && $restriction->item(0) instanceof DOMElement ? $restriction->item(0)->getAttribute('base') : 'string';
		}
		return $this->localName($type);
	}

	/**
	 * Dolibarr field an XSD node is annotated with (dolibarr:field attribute or appinfo)
	 *
	 * @param	DOMXPath	$xp		XPath
	 * @param	DOMElement	$node	xs:element or xs:attribute
	 * @return	string
	 */
	private function xsdTarget($xp, $node)
	{
		foreach ($node->attributes as $attribute) {
			if ($attribute->localName == 'field' && stripos((string) $attribute->namespaceURI, 'dolibarr') !== false) {
				return trim($attribute->value);
			}
		}
		foreach ($xp->query('xs:annotation/xs:appinfo/*', $node) as $info) {
			if ($info instanceof DOMElement && $info->localName == 'field') {
				return trim($info->textContent);
			}
		}
		return '';
	}

	/**
	 * Name without its namespace prefix
	 *
	 * @param	string	$qname	e.g. xs:decimal
	 * @return	string			e.g. decimal
	 */
	private function localName($qname)
	{
		$pos = strrpos((string) $qname, ':');
		return $pos === false ? (string) $qname : substr((string) $qname, $pos + 1);
	}

	/**
	 * First libxml errors, as one line
	 *
	 * @param	int		$max	Number of errors
	 * @return	string
	 */
	private function libxmlErrors($max)
	{
		$messages = array();
		foreach (libxml_get_errors() as $e) {
			if (count($messages) < $max) {
				$messages[] = 'line '.$e->line.': '.trim($e->message);
			}
		}
		libxml_clear_errors();
		return implode('; ', $messages);
	}
}
