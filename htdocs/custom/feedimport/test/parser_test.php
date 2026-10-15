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
 * \file    feedimport/test/parser_test.php
 * \ingroup feedimport
 * \brief   Tests of the feed readers (CSV, XML, XSD, mapping file) and of the value helpers. No database change.
 *
 * Usage: php htdocs/custom/feedimport/test/parser_test.php
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOREQUIREMENU', 1);
$res = @include __DIR__.'/../../../master.inc.php';
if (!$res) {
	$res = @include __DIR__.'/../../../../master.inc.php';
}
if (!$res) {
	fwrite(STDERR, "Include of master.inc.php fails\n");
	exit(1);
}
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportparser.class.php');

$fails = 0;
/**
 * Report a check
 *
 * @param	bool	$cond	Result
 * @param	string	$msg	What is checked
 * @return	void
 */
function check($cond, $msg)
{
	global $fails;
	echo($cond ? '  ok   ' : '  FAIL ').$msg."\n";
	$fails += $cond ? 0 : 1;
}

$dir = sys_get_temp_dir().'/feedimport_test_'.getmypid();
dol_mkdir($dir);
file_put_contents($dir.'/feed.xsd', '<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:dolibarr="urn:dolibarr">
  <xs:simpleType name="Money"><xs:restriction base="xs:decimal"><xs:minInclusive value="0"/></xs:restriction></xs:simpleType>
  <xs:complexType name="ProductType">
    <xs:sequence>
      <xs:element name="sku" type="xs:string" dolibarr:field="sku"/>
      <xs:element name="name" type="xs:string" dolibarr:field="label"/>
      <xs:element name="ean" type="xs:string" minOccurs="0"/>
      <xs:element name="price" type="Money"><xs:annotation><xs:appinfo><dolibarr:field>buy_price</dolibarr:field></xs:appinfo></xs:annotation></xs:element>
      <xs:element name="stock"><xs:complexType><xs:sequence><xs:element name="available" type="xs:integer" dolibarr:field="stock"/></xs:sequence><xs:attribute name="warehouse" type="xs:string"/></xs:complexType></xs:element>
    </xs:sequence>
    <xs:attribute name="id" type="xs:string"/>
  </xs:complexType>
  <xs:element name="catalog"><xs:complexType><xs:sequence>
    <xs:element name="header" type="xs:string"/>
    <xs:element name="products"><xs:complexType><xs:sequence><xs:element name="product" type="ProductType" maxOccurs="unbounded"/></xs:sequence></xs:complexType></xs:element>
  </xs:sequence></xs:complexType></xs:element>
</xs:schema>');
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<catalog><header>x</header><products>
<product id="1"><sku>AB-0001</sku><name>Widget &amp; co</name><ean>5012345678900</ean><price>12.50</price><stock warehouse="W1"><available>5</available></stock></product>
<product id="2"><sku>AB-0002</sku><name>Gadget</name><price>3</price><stock warehouse="W1"><available>0</available></stock></product>
</products></catalog>';
file_put_contents($dir.'/feed.xml', $xml);
file_put_contents($dir.'/bad.xml', str_replace('<price>3</price>', '<price>-3</price>', $xml));
file_put_contents($dir.'/broken.xml', substr($xml, 0, 250));
file_put_contents($dir.'/feed.csv', "\xEF\xBB\xBFSKU;Name;Price;Qty\nAB-0001;Widget;12,50;>100\n\nAB-0002;\"Gad;get\";1.234,00;7\nAB-0003;Short\n");
file_put_contents($dir.'/latin1.csv', mb_convert_encoding("SKU,Name\nX1,Crème brûlée\n", 'ISO-8859-1', 'UTF-8'));
file_put_contents($dir.'/map.csv', "SKU;Name;Price;Qty;Junk\nsku;Label;buy_price;stock;nonsense\n");

echo "XSD\n";
$p = new FeedImportParser();
$info = $p->readXsd($dir.'/feed.xsd');
check($info !== null && $info['itempath'] == '/catalog/products/product', 'item element found through a named type: '.($info ? $info['itempath'] : $p->error));
check($info && array_keys($info['fields']) == array('@id', 'sku', 'name', 'ean', 'price', 'stock/@warehouse', 'stock/available'), 'fields with attributes and nested elements');
check($info && $info['fields']['price']['type'] == 'decimal' && $info['fields']['stock/available']['type'] == 'integer', 'types resolved through simple types');
check($info && $info['fields']['sku']['target'] == 'sku' && $info['fields']['price']['target'] == 'buy_price', 'dolibarr:field attribute and appinfo annotations');

echo "XML\n";
$p->filetype = 'xml';
$p->itempath = '/catalog/products/product';
$rows = iterator_to_array($p->rows($dir.'/feed.xml'), false);
check(count($rows) == 2 && $p->error === '', '2 items read');
check($rows[0]['data']['name'] == 'Widget & co' && $rows[0]['data']['stock/available'] == '5' && $rows[0]['data']['@id'] == '1', 'values, entities, attributes and nested fields');
check(!isset($rows[1]['data']['ean']), 'missing optional element absent');
$p->itempath = 'product';
check(count(iterator_to_array($p->rows($dir.'/feed.xml'), false)) == 2, 'item element by name only');
$p->itempath = '/nope';
check(count(iterator_to_array($p->rows($dir.'/feed.xml'), false)) == 0 && $p->error !== '', 'unknown item element is an error');
$p->itempath = '/catalog/products/product';
iterator_to_array($p->rows($dir.'/broken.xml'), false);
check($p->error !== '', 'truncated XML is an error: '.$p->error);
check($p->validateXml($dir.'/feed.xml', $dir.'/feed.xsd') == array(), 'valid file passes the XSD');
check(count($p->validateXml($dir.'/bad.xml', $dir.'/feed.xsd')) == 1, 'negative price fails the XSD');

echo "CSV\n";
$c = new FeedImportParser();
check($c->fields($dir.'/feed.csv') == array('SKU', 'Name', 'Price', 'Qty'), 'header with BOM, delimiter detected');
$rows = iterator_to_array($c->rows($dir.'/feed.csv'), false);
check(count($rows) == 3, 'blank line skipped');
check($rows[1]['data']['Name'] == 'Gad;get' && $rows[1]['line'] == 4, 'enclosed delimiter, line numbers');
check($rows[2]['columns'] == 2 && $rows[2]['expected'] == 4 && $rows[2]['data']['Qty'] === '', 'short row reported');
$l = new FeedImportParser();
$l->encoding = 'ISO-8859-1';
$rows = iterator_to_array($l->rows($dir.'/latin1.csv'), false);
check($rows[0]['data']['Name'] == 'Crème brûlée', 'ISO-8859-1 converted');

echo "Mapping file\n";
$unknown = array();
$mapping = $c->readMappingFile($dir.'/map.csv', feedimportTargetFields(false), $unknown);
check($mapping == array('SKU' => 'sku', 'Name' => 'label', 'Price' => 'buy_price', 'Qty' => 'stock'), 'codes and labels resolved');
check($unknown == array('nonsense'), 'unknown field reported');
check(FeedImportParser::buildMappingFile(array('SKU', 'Name'), array('SKU' => 'sku')) == "SKU;Name\nsku;\n", 'template built');

echo "Helpers\n";
$numbers = array(array('12.50', '.', 12.5), array('12,50', ',', 12.5), array('1 234,50', ',', 1234.5), array('1.234,00', ',', 1234.0), array('1,234.00', '.', 1234.0),
	array('£12.50', '.', 12.5), array('>100', '.', 100.0), array('12.5 kg', '.', 12.5), array('-3', '.', -3.0), array('abc', '.', null), array('', '.', null));
foreach ($numbers as $n) {
	check(feedimportParseNumber($n[0], $n[1]) === $n[2], 'number "'.$n[0].'" -> '.var_export($n[2], true));
}
foreach (array('AB-0123' => 'AB123', 'ab123 ' => 'AB123', '000123' => '123', '100' => '100', 'A.B/1' => 'AB1') as $in => $out) {
	check(feedimportNormaliseKey($in) === $out, 'normalised "'.$in.'" -> '.$out);
}
$time = dol_mktime(12, 0, 0, 3, 9, 2026, 'tzserver');
check(feedimportExpandDateTokens('s_{YYYYMMDD}_{YY}{MM}{DD}.csv', $time) == 's_20260309_260309.csv', 'date tokens');

dol_delete_dir_recursive($dir);
echo "\n".($fails ? $fails.' FAILED' : 'All passed')."\n";
exit($fails ? 1 : 0);
