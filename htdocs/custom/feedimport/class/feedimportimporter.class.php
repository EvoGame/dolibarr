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
 * \file    feedimport/class/feedimportimporter.class.php
 * \ingroup feedimport
 * \brief   Importing the items of a feed file into products, and the review queue's actions
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportrun.class.php');

/**
 * Imports the items of a feed file (see docs/design.md, "A run").
 *
 * For each item: find its product (item table, exact match on the matching key, normalised SKU), else check it is
 * not a probable duplicate (review queue) before creating a product. Then update what changed, set the stock in
 * the feed's warehouse, and at the end set to 0 the stock of the items no longer in the file.
 */
class FeedImportImporter
{
	const ITEM_REVIEW = 0;
	const ITEM_LINKED = 1;
	const ITEM_IGNORED = 2;
	const ITEM_NOT_CREATED = 3;

	/** Zeroing is skipped when more rows than this share have no usable SKU */
	const MAX_NO_SKU_RATIO = 0.02;

	/** Fields holding numbers */
	const NUMERIC_FIELDS = array('weight', 'sell_price', 'cost_price', 'buy_price', 'min_qty', 'vat_rate', 'stock');

	/** @var DoliDB */
	public $db;
	/** @var FeedImportFeed */
	public $feed;
	/** @var FeedImportRun */
	public $run;
	/** @var User */
	public $user;
	/** @var string */
	public $error = '';

	/** @var int Distinct SKUs in the file */
	public $nbItems = 0;
	/** @var bool A guard stopped the zeroing */
	public $guardTripped = false;
	/** @var bool The file has far fewer items than the last one: its count must not become the reference */
	public $countGuardTripped = false;

	/** @var array<string,string> Dolibarr field => feed field */
	private $fieldOf = array();
	/** @var array<string,object> sku => item row (id, fk_product, status, hash, sku_norm, label, product_exists) */
	private $items = array();
	/** @var array<string,string[]> normalised SKU => SKUs of the feed's items */
	private $itemsByNorm = array();
	/** @var array<string,int>|null supplier reference => product, for the feed's supplier */
	private $supplierRefs = null;
	/** @var array<string,string[]>|null normalised supplier reference => references */
	private $supplierRefsByNorm = null;
	/** @var array<string,string>|null folded SKU (see fold()) => one SKU or reference having it */
	private $folded = null;
	/** @var array<int,float> product => stock in the feed's warehouse */
	private $stock = array();
	/** @var array<string,bool> SKUs seen in this file */
	private $seen = array();
	/** @var array<int,bool> Products of the items seen in this file: never zeroed by this run */
	private $seenProducts = array();
	/** @var array<string,string>|null Known SKUs not seen yet in this file (see knownKeys()) */
	private $known = null;
	/** @var int[] Items seen and unchanged: their last-seen date is updated in bulk */
	private $touched = array();
	/** @var int Products created in this run */
	private $created = 0;
	/** @var bool The feed had items before this run (it is not its first import) */
	private $hadItems = false;
	/** @var int Database changes made (items saved, stock corrected): what pacing counts */
	private $changes = 0;
	/** @var int Rows that changed something since the last pause */
	private $blockRows = 0;
	/** @var int Blocks completed */
	private $blocks = 0;
	/** @var int Products whose feed selling price was not written because they have a price formula */
	private $formulaSkipped = 0;
	/** @var Societe|null */
	private $supplier = null;
	/** @var bool */
	private $stockEnabled = false;

	/**
	 * Constructor
	 *
	 * @param	DoliDB				$db		Database
	 * @param	FeedImportFeed		$feed	Feed
	 * @param	FeedImportRun		$run	Run (its counters and log are filled)
	 * @param	User				$user	User the products are changed as
	 */
	public function __construct($db, $feed, $run, $user)
	{
		$this->db = $db;
		$this->feed = $feed;
		$this->run = $run;
		$this->user = $user;
		foreach ($feed->mapping as $feedfield => $code) {
			if ($code !== '' && !isset($this->fieldOf[$code])) {
				$this->fieldOf[$code] = (string) $feedfield;
			}
		}
		if ($feed->fk_soc > 0) {
			$this->supplier = new Societe($db);
			if ($this->supplier->fetch($feed->fk_soc) <= 0) {
				$this->supplier = null;
			}
		}
		$this->stockEnabled = isModEnabled('stock') && $feed->fk_entrepot > 0;
	}

	/**
	 * Import a file
	 *
	 * @param	string	$file		Path
	 * @param	bool	$forcezero	Zero missing items even if the safety threshold trips
	 * @return	bool				True if the whole file was read (the run can be trusted for zeroing)
	 */
	public function import($file, $forcezero = false)
	{
		$run = $this->run;

		if (!isset($this->fieldOf['sku'])) {
			$run->log('No feed field is mapped to the SKU: set the mapping of the feed', 'error');
			return false;
		}
		if (isset($this->fieldOf['stock']) && !$this->stockEnabled) {
			$run->log(isModEnabled('stock') ? 'No warehouse is set for this feed: stock is not imported' : 'The Stocks module is disabled: stock is not imported', 'warning');
		}
		if (isset($this->fieldOf['buy_price']) && !$this->supplier) {
			$run->log('No supplier is set for this feed: buy prices are not imported', 'warning');
		}

		$parser = $this->feed->getParser();

		// Every mapped field must be in the file (a renamed column would silently empty a field)
		if ($this->feed->filetype == 'csv') {
			$fields = $parser->fields($file);
			if ($fields === null) {
				$run->log($parser->error, 'error');
				return false;
			}
			foreach ($this->feed->mapping as $feedfield => $code) {
				if ($code !== '' && !in_array((string) $feedfield, $fields, true)) {
					if ($code == 'sku') {
						$run->log('The column "'.$feedfield.'" (SKU) is not in the file. Columns found: '.implode(', ', $fields), 'error');
						return false;
					}
					$run->log('The column "'.$feedfield.'" ('.$code.') is not in the file', 'warning');
				}
			}
		}

		// XML: validate the whole file against the XSD before changing anything
		$xsd = $this->feed->getXsdPath();
		if ($xsd !== '') {
			$errors = $parser->validateXml($file, $xsd);
			if (!empty($errors)) {
				foreach ($errors as $message) {
					$run->log('XSD: '.$message, 'error');
				}
				$run->log('The file does not match the XSD: nothing imported');
				return false;
			}
			$run->log('The file matches the XSD');
		}

		$this->loadItems();
		$this->loadStock();

		$nosku = 0;
		foreach ($parser->rows($file) as $row) {
			$run->nb_rows++;
			if (isset($row['expected']) && $row['columns'] != $row['expected']) {
				$run->log('Line '.$row['line'].': '.$row['columns'].' columns instead of '.$row['expected'], 'warning');
			}
			$sku = trim(isset($row['data'][$this->fieldOf['sku']]) ? (string) $row['data'][$this->fieldOf['sku']] : '');
			if ($sku === '' || dol_strlen($sku) > 128) {
				$nosku++;
				$run->log('Line '.$row['line'].': '.($sku === '' ? 'no SKU' : 'SKU longer than 128 characters').', ignored', 'warning');
				continue;
			}
			if (isset($this->seen[$sku])) {
				$run->log('Line '.$row['line'].': SKU '.$sku.' already met in the file, ignored', 'warning');
				continue;
			}
			$this->seen[$sku] = true;
			$values = $this->rowValues($row['data'], $row['line']);
			$before = $this->changes;
			try {
				$this->importItem($sku, $values, $row['line']);
			} catch (Exception $e) {
				$run->log('Line '.$row['line'].' ('.$sku.'): '.$e->getMessage(), 'error');
			}
			$this->pace($this->changes > $before);
			if ($run->nb_rows % 500 == 0) {
				$this->flushTouched();
				$run->update();	// progress, for whoever looks at the run
			}
		}
		$this->flushTouched();
		if ($this->formulaSkipped > 0) {
			$run->log($this->formulaSkipped.' products have a price formula (Dynamic prices): their selling price comes from the formula, not from the feed');
		}
		$this->nbItems = count($this->seen);

		if ($parser->error !== '') {
			$run->log($parser->error, 'error');
			$run->log('The file was not read to the end: no item is considered gone');
			return false;
		}

		// Zero the items no longer in the file, unless something says the file is not the whole catalogue
		if (!empty($this->feed->params['zero']) && $this->stockEnabled && isset($this->fieldOf['stock'])) {
			$minratio = max(0, min(100, (int) $this->feed->params['minratio']));
			$previous = (int) $this->feed->last_count;
			if ($previous > 0 && $this->nbItems < $previous * $minratio / 100 && !$forcezero) {
				$this->guardTripped = true;
				$this->countGuardTripped = true;
				$run->log('The file has '.$this->nbItems.' items, fewer than '.$minratio.'% of the '.$previous.' of the last import: items missing from it are NOT set to 0. If the supplier really removed them, use Run now with "Ignore the safety threshold".', 'warning');
			} elseif ($run->nb_rows > 0 && $nosku / $run->nb_rows > self::MAX_NO_SKU_RATIO && !$forcezero) {
				$this->guardTripped = true;
				$run->log($nosku.' of '.$run->nb_rows.' rows have no usable SKU: items missing from the file are NOT set to 0', 'warning');
			} else {
				$this->zeroMissing();
			}
		}
		return true;
	}

	/**
	 * Spread the load of a large import: after every block of rows that changed something, save the progress, show
	 * the feed is still being imported, and pause. Rows that changed nothing cost almost nothing and are not counted.
	 *
	 * @param	bool	$changed	The row just processed changed something
	 * @return	void
	 */
	private function pace($changed)
	{
		if (!$changed) {
			return;
		}
		$this->blockRows++;
		$size = (int) $this->feed->params['blocksize'];
		if ($size <= 0 || $this->blockRows < $size) {
			return;
		}
		$this->blocks++;
		$this->blockRows = 0;
		$this->flushTouched();
		$this->run->update();
		$this->feed->heartbeat();
		$pause = max(0, (int) $this->feed->params['pause']);
		if ($pause > 0) {
			$this->run->log('Block '.$this->blocks.' done ('.$size.' rows changed): pausing '.$pause.' s');
			sleep($pause);
		}
	}

	/**
	 * Mapped values of a row, typed: numbers parsed, empty values left out
	 *
	 * @param	array<string,string>	$data	Row (feed field => text)
	 * @param	int						$line	Line, for the log
	 * @return	array<string,mixed>				Dolibarr field => value
	 */
	private function rowValues($data, $line)
	{
		$values = array();
		$decimal = (string) $this->feed->params['decimal'];
		foreach ($this->fieldOf as $code => $feedfield) {
			if (!isset($data[$feedfield])) {
				continue;
			}
			$text = trim((string) $data[$feedfield]);
			if ($text === '') {
				continue;
			}
			if (in_array($code, self::NUMERIC_FIELDS)) {
				$number = feedimportParseNumber($text, $decimal);
				if ($number === null) {
					$this->run->log('Line '.$line.': '.$feedfield.' "'.dol_trunc($text, 40).'" is not a number, ignored', 'warning');
					continue;
				}
				if ($number < 0) {
					if ($code == 'stock') {
						$number = 0;	// some suppliers send negative availability
					} else {
						$this->run->log('Line '.$line.': '.$feedfield.' is negative, ignored', 'warning');
						continue;
					}
				}
				$values[$code] = $number;
			} else {
				$values[$code] = $text;
			}
		}
		return $values;
	}

	/**
	 * Import one item
	 *
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values (rowValues())
	 * @param	int					$line		Line, for the log
	 * @return	void
	 */
	private function importItem($sku, $values, $line)
	{
		$hash = sha1((string) json_encode($values));
		$item = isset($this->items[$sku]) ? $this->items[$sku] : null;

		if ($item && $item->status == self::ITEM_LINKED && $item->product_exists) {
			if ($item->hash === $hash) {
				$this->run->nb_unchanged++;
				$this->touched[] = (int) $item->id;
				$this->seenProducts[(int) $item->fk_product] = true;
				$this->applyStock((int) $item->fk_product, $values, $sku);
				return;
			}
			$changed = $this->applyValues((int) $item->fk_product, $sku, $values, false);
			if ($changed < 0) {
				return;		// error logged; the hash is not saved, so it is tried again next time
			}
			$this->run->nb_updated += ($changed > 0 ? 1 : 0);
			$this->run->nb_unchanged += ($changed > 0 ? 0 : 1);
			$this->saveItem($sku, $values, $hash, self::ITEM_LINKED, (int) $item->fk_product);
			return;
		}
		if ($item && $item->status == self::ITEM_IGNORED) {
			$this->run->nb_skipped++;
			$this->touched[] = (int) $item->id;
			return;
		}
		if ($item && $item->status == self::ITEM_REVIEW) {
			$this->run->nb_review++;
			$this->saveItem($sku, $values, '', self::ITEM_REVIEW, 0);
			return;
		}
		if ($item && $item->status == self::ITEM_LINKED) {
			$this->run->log('Line '.$line.': the product of '.$sku.' was deleted, looking for it again', 'warning');
		}

		// Not linked yet: find its product
		$reason = '';
		$productid = $this->findProduct($sku, $values, $reason);
		if ($productid > 0) {
			$this->run->log('Line '.$line.': '.$sku.' linked to product '.$productid.' ('.$reason.')');
			$changed = $this->applyValues($productid, $sku, $values, false);
			if ($changed < 0) {
				$this->saveItem($sku, $values, '', self::ITEM_LINKED, $productid, $reason);
				return;
			}
			$this->run->nb_updated++;
			$this->saveItem($sku, $values, $hash, self::ITEM_LINKED, $productid, $reason);
			return;
		}

		// Not in Dolibarr: create it, unless it looks like a product we already have
		$candidate = 0;
		$doubt = $this->doubt($sku, $values, $candidate);
		if ($doubt !== '') {
			$this->run->nb_review++;
			$this->run->log('Line '.$line.': '.$sku.' sent to the review queue: '.$doubt);
			$this->saveItem($sku, $values, '', self::ITEM_REVIEW, 0, $doubt, $candidate);
			return;
		}
		if (empty($this->feed->params['create'])) {
			$this->run->nb_skipped++;
			$this->saveItem($sku, $values, '', self::ITEM_NOT_CREATED, 0, 'Not in Dolibarr');
			return;
		}
		$productid = $this->createProduct($sku, $values);
		if ($productid <= 0) {
			return;
		}
		$this->created++;
		$this->run->nb_created++;
		$this->run->log('Line '.$line.': '.$sku.' created as product '.$productid);
		$this->saveItem($sku, $values, $hash, self::ITEM_LINKED, $productid, 'Created');
	}

	/**
	 * Product of an SKU not linked yet: exact match on the feed's matching key, then on the normalised SKU
	 *
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values
	 * @param	string				$reason		Filled with how it was found
	 * @return	int								Product id, 0 if not found
	 */
	private function findProduct($sku, $values, &$reason)
	{
		$matchkey = (string) $this->feed->params['matchkey'];
		if ($matchkey == 'supplierref') {
			$this->loadSupplierRefs();
			if (isset($this->supplierRefs[$sku])) {
				$reason = 'supplier reference';
				return $this->supplierRefs[$sku];
			}
		} elseif ($matchkey == 'ref') {
			$ref = isset($values['ref']) ? (string) $values['ref'] : $this->newRef($sku);
			$id = $this->productIdBy('ref', $ref);
			if ($id > 0) {
				$reason = 'product ref';
				return $id;
			}
		} elseif ($matchkey == 'barcode' && !empty($values['barcode'])) {
			$id = $this->productIdBy('barcode', (string) $values['barcode']);
			if ($id > 0) {
				$reason = 'barcode';
				return $id;
			}
		}

		// Same SKU but for case, spaces, punctuation or leading zeros: one product only, else it is a doubt
		$norm = feedimportNormaliseKey($sku);
		if ($norm === '') {
			return 0;
		}
		$products = array();
		if (isset($this->itemsByNorm[$norm])) {
			foreach ($this->itemsByNorm[$norm] as $other) {
				$o = $this->items[$other];
				if ($other !== $sku && $o->status == self::ITEM_LINKED && $o->product_exists && !$this->isSeenElsewhere($other)) {
					$products[(int) $o->fk_product] = $other;
				}
			}
		}
		$this->loadSupplierRefs();
		if (isset($this->supplierRefsByNorm[$norm])) {
			foreach ($this->supplierRefsByNorm[$norm] as $ref) {
				if ($ref !== $sku && !$this->isSeenElsewhere($ref)) {
					$products[$this->supplierRefs[$ref]] = $ref;
				}
			}
		}
		if (count($products) == 1) {
			$reason = 'same SKU as '.reset($products).' once normalised';
			return (int) key($products);
		}
		return 0;
	}

	/**
	 * Whether another SKU is itself in this file: then it is a different item, not a variant spelling
	 *
	 * @param	string	$other	Other SKU
	 * @return	bool
	 */
	private function isSeenElsewhere($other)
	{
		return isset($this->seen[$other]);
	}

	/**
	 * Why a new item should be looked at by someone before a product is created, if it should
	 *
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values
	 * @param	int					$candidate	Filled with the product it probably is
	 * @return	string							Reason, '' if it can be created
	 */
	private function doubt($sku, $values, &$candidate)
	{
		$candidate = 0;

		if (empty($values['label'])) {
			return 'No label';
		}

		$ref = isset($values['ref']) ? (string) $values['ref'] : $this->newRef($sku);
		if ($ref === '') {
			return 'No usable product ref';
		}
		$id = $this->productIdBy('ref', $ref);
		if ($id > 0) {
			$candidate = $id;
			return 'The ref '.$ref.' is already used by another product';
		}

		if (!empty($values['barcode'])) {
			$id = $this->productIdBy('barcode', (string) $values['barcode']);
			if ($id > 0) {
				$candidate = $id;
				return 'Same barcode as an existing product';
			}
		}

		// Typos: O/0 and I/L/1 confused, two neighbouring characters swapped. Sequential SKUs (AB1234, AB1235) are
		// not typos, so a one-character difference only counts when the label is the same too.
		$this->loadFolded();
		$folded = $this->fold($sku);
		if ($folded !== '' && isset($this->folded[$folded]) && !$this->isSeenElsewhere($this->folded[$folded])) {
			$candidate = $this->productOfKey($this->folded[$folded]);
			return 'SKU looks like '.$this->folded[$folded].' (O/0 or I/1 confused)';
		}
		$norm = feedimportNormaliseKey($sku);
		if (dol_strlen($norm) >= 4) {
			$label = $this->normaliseLabel((string) $values['label']);
			foreach ($this->knownKeys() as $other => $othernorm) {
				if ($this->isSeenElsewhere($other) || abs(strlen($othernorm) - strlen($norm)) > 1) {
					continue;
				}
				if (strlen($othernorm) == strlen($norm) && $this->isTransposition($norm, $othernorm)) {
					$candidate = $this->productOfKey($other);
					return 'SKU looks like '.$other.' (two characters swapped)';
				}
				if (levenshtein($norm, $othernorm) == 1) {
					$otherproduct = $this->productOfKey($other);
					if ($otherproduct > 0 && $label !== '' && $this->normaliseLabel($this->productLabel($otherproduct)) === $label) {
						$candidate = $otherproduct;
						return 'SKU one character away from '.$other.', with the same label';
					}
				}
			}
		}

		// Many new items at once: probably SKUs reformatted by the supplier, not new products
		$max = (int) $this->feed->params['maxnew'];
		if ($max > 0 && $this->hadItems && $this->created >= $max) {
			return 'More than '.$max.' new products in one run';
		}

		return '';
	}

	/**
	 * Create the product of a new item
	 *
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values
	 * @return	int								Product id, <=0 on error (logged)
	 */
	public function createProduct($sku, $values)
	{
		$product = new Product($this->db);
		$product->ref = isset($values['ref']) ? (string) $values['ref'] : $this->newRef($sku);
		$product->label = dol_trunc((string) (isset($values['label']) ? $values['label'] : $sku), 255, 'right', 'UTF-8', 1);
		$product->description = isset($values['description']) ? (string) $values['description'] : '';
		$product->type = Product::TYPE_PRODUCT;
		$product->status = 1;
		$product->status_buy = 1;
		$product->tva_tx = $this->vatRate($values);
		$product->price_base_type = ($this->feed->params['pricebase'] == 'TTC') ? 'TTC' : 'HT';
		if (isset($values['sell_price'])) {
			if ($product->price_base_type == 'TTC') {
				$product->price_ttc = $values['sell_price'];
			} else {
				$product->price = $values['sell_price'];
			}
		}
		if (isset($values['cost_price'])) {
			$product->cost_price = $values['cost_price'];
		}
		if (isset($values['weight'])) {
			$product->weight = $values['weight'];
			$product->weight_units = 0;	// kg
		}
		if (!empty($values['barcode'])) {
			$product->barcode = (string) $values['barcode'];
			$product->barcode_type = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE');
			$product->fetch_barcode();
		}
		foreach ($values as $code => $value) {
			if (strpos($code, 'options_') === 0) {
				$product->array_options[$code] = $value;
			}
		}

		$id = $product->create($this->user);
		if ($id <= 0) {
			$this->run->log('Cannot create the product of '.$sku.': '.$this->objectError($product), 'error');
			return -1;
		}

		if (isset($values['buy_price'])) {
			$this->applyBuyPrice($product, $sku, $values);
		}
		$category = (int) $this->feed->params['fk_category'];
		if ($category > 0) {
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$cat = new Categorie($this->db);
			if ($cat->fetch($category) > 0 && $cat->add_type($product, Categorie::TYPE_PRODUCT) < 0) {
				$this->run->log('Cannot put '.$sku.' in the category: '.$cat->error, 'warning');
			}
		}
		$this->applyStock($id, $values, $sku);
		return $id;
	}

	/**
	 * Update a product from the values of its item, only what differs
	 *
	 * @param	int					$productid	Product
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values
	 * @param	bool				$quiet		Do not log each change
	 * @return	int								1 if something changed, 0 if nothing, <0 on error (logged)
	 */
	public function applyValues($productid, $sku, $values, $quiet = false)
	{
		$product = new Product($this->db);
		// The stored price, not a price formula's result: evaluating it also runs the Dynamic prices updaters
		if ($product->fetch($productid, '', '', '', 1) <= 0) {
			$this->run->log('Product '.$productid.' of '.$sku.' cannot be read: '.$this->objectError($product), 'error');
			return -1;
		}
		$changes = array();

		// Product fields. The ref is never changed: other records and documents use it.
		$fields = array('label' => 'label', 'description' => 'description', 'cost_price' => 'cost_price', 'weight' => 'weight');
		foreach ($fields as $code => $property) {
			if (!isset($values[$code])) {
				continue;
			}
			$new = $values[$code];
			$old = $product->$property;
			$differs = in_array($code, self::NUMERIC_FIELDS) ? (round((float) $old, 6) != round((float) $new, 6) || ($old === null || $old === '')) : ((string) $old !== (string) $new);
			if ($differs) {
				$changes[] = $code;
				$product->$property = $new;
				if ($code == 'weight') {
					$product->weight_units = 0;
				}
			}
		}
		if (!empty($values['barcode']) && (string) $product->barcode !== (string) $values['barcode']) {
			$changes[] = 'barcode';
			$product->barcode = (string) $values['barcode'];
			if (empty($product->barcode_type)) {
				$product->barcode_type = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE');
			}
			$product->fetch_barcode();
		}
		foreach ($values as $code => $value) {
			if (strpos($code, 'options_') === 0 && (string) (isset($product->array_options[$code]) ? $product->array_options[$code] : '') !== (string) $value) {
				$changes[] = $code;
				$product->array_options[$code] = $value;
			}
		}
		if (!empty($changes)) {
			if ($product->update($product->id, $this->user) < 0) {
				$this->run->log('Cannot update '.$sku.' ('.implode(', ', $changes).'): '.$this->objectError($product), 'error');
				return -1;
			}
		}

		// Selling price, unless a price formula (Dynamic prices) makes it
		if (isset($values['sell_price']) && isModEnabled('dynamicprices') && !empty($product->fk_price_expression)) {
			$this->formulaSkipped++;
		} elseif (isset($values['sell_price'])) {
			$base = ($this->feed->params['pricebase'] == 'TTC') ? 'TTC' : 'HT';
			$current = ($base == 'TTC') ? (float) $product->price_ttc : (float) $product->price;
			$vat = isset($values['vat_rate']) ? (float) $values['vat_rate'] : (float) $product->tva_tx;
			if (round($current, 6) != round((float) $values['sell_price'], 6) || $product->price_base_type != $base || round((float) $product->tva_tx, 4) != round($vat, 4)) {
				if ($product->updatePrice((float) $values['sell_price'], $base, $this->user, $vat) < 0) {
					$this->run->log('Cannot update the selling price of '.$sku.': '.$this->objectError($product), 'error');
					return -1;
				}
				$changes[] = 'sell_price';
			}
		}

		// Buy price
		if (isset($values['buy_price'])) {
			$res = $this->applyBuyPrice($product, $sku, $values);
			if ($res < 0) {
				return -1;
			}
			if ($res > 0) {
				$changes[] = 'buy_price';
			}
		}

		// Stock
		if ($this->applyStock($productid, $values, $sku) > 0) {
			$changes[] = 'stock';
		}

		if (!empty($changes) && !$quiet) {
			$this->run->log($sku.': '.implode(', ', $changes));
		}
		return empty($changes) ? 0 : 1;
	}

	/**
	 * Create or update the feed's supplier price of a product, with the SKU as supplier reference
	 *
	 * @param	Product				$product	Product
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values (buy_price, min_qty, vat_rate)
	 * @return	int								1 if changed, 0 if not, <0 on error (logged)
	 */
	private function applyBuyPrice($product, $sku, $values)
	{
		global $conf;

		if (!$this->supplier || !isModEnabled('supplier_order') && !isModEnabled('supplier_invoice') && !isModEnabled('fournisseur')) {
			return 0;
		}
		$qty = isset($values['min_qty']) && $values['min_qty'] > 0 ? (float) $values['min_qty'] : 1;
		$price = (float) $values['buy_price'] * $qty;	// update_buyprice() wants the price of the quantity
		$vat = $this->vatRate($values, $product);

		// The supplier price of this supplier for this product: the one with this reference, else the one for this quantity
		$existing = null;
		$sql = "SELECT rowid, ref_fourn, price, quantity, tva_tx, desc_fourn, remise_percent, remise, fk_availability, delivery_time_days,";
		$sql .= " supplier_reputation, default_vat_code, info_bits, barcode, fk_barcode_type";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sql .= " WHERE fk_product = ".((int) $product->id)." AND fk_soc = ".((int) $this->supplier->id)." AND entity IN (".getEntity('productsupplierprice').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->run->log('Cannot read the supplier prices of '.$sku.': '.$this->db->lasterror(), 'error');
			return -1;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			if ((string) $obj->ref_fourn === $sku) {
				$existing = $obj;
			}
		}
		if (!$existing) {
			foreach ($rows as $obj) {
				if (round((float) $obj->quantity, 4) == round($qty, 4)) {
					$existing = $obj;
				}
			}
		}
		if ($existing && (string) $existing->ref_fourn === $sku && round((float) $existing->price, 6) == round($price, 6)
			&& round((float) $existing->quantity, 4) == round($qty, 4) && round((float) $existing->tva_tx, 4) == round($vat, 4)) {
			return 0;
		}

		$pf = new ProductFournisseur($this->db);
		$pf->id = $product->id;
		$pf->product_fourn_price_id = $existing ? (int) $existing->rowid : 0;
		// What the feed does not give is kept as it is: update_buyprice() rewrites every column
		$e = $existing ? $existing : (object) array('desc_fourn' => '', 'remise_percent' => 0, 'remise' => 0, 'fk_availability' => 0, 'delivery_time_days' => '',
			'supplier_reputation' => '', 'default_vat_code' => '', 'info_bits' => 0, 'barcode' => '', 'fk_barcode_type' => 0);
		$res = $pf->update_buyprice(
			$qty,
			$price,
			$this->user,
			'HT',
			$this->supplier,
			(int) $e->fk_availability,
			$sku,
			$vat,
			0,
			(float) $e->remise_percent,
			(float) $e->remise,
			(int) $e->info_bits,
			($e->delivery_time_days === null ? '' : $e->delivery_time_days),
			(string) $e->supplier_reputation,
			array(),
			(string) $e->default_vat_code,
			$price,
			'HT',
			1,
			$conf->currency,
			(string) $e->desc_fourn,
			(string) $e->barcode,
			(int) $e->fk_barcode_type
		);
		if ($res < 0) {
			$this->run->log('Cannot update the buy price of '.$sku.': '.$this->objectError($pf), 'error');
			return -1;
		}
		if ($this->supplierRefs !== null) {
			$this->supplierRefs[$sku] = (int) $product->id;
		}
		return 1;
	}

	/**
	 * Set the stock of a product in the feed's warehouse to the feed's quantity
	 *
	 * @param	int					$productid	Product
	 * @param	array<string,mixed>	$values		Values
	 * @param	string				$sku		SKU, for the log
	 * @return	int								1 if changed, 0 if not, <0 on error (logged)
	 */
	private function applyStock($productid, $values, $sku)
	{
		if (!$this->stockEnabled || !isset($values['stock'])) {
			return 0;
		}
		return $this->setStock($productid, (float) $values['stock'], $sku);
	}

	/**
	 * Correct the stock of a product in the feed's warehouse
	 *
	 * @param	int		$productid	Product
	 * @param	float	$qty		Quantity wanted
	 * @param	string	$sku		SKU, for the log
	 * @return	int					1 if changed, 0 if not, <0 on error (logged)
	 */
	private function setStock($productid, $qty, $sku)
	{
		$current = isset($this->stock[$productid]) ? $this->stock[$productid] : 0.0;
		$diff = round($qty - $current, 6);
		if ($diff == 0) {
			return 0;
		}
		$product = new Product($this->db);
		$product->id = $productid;
		$label = 'Supplier feed '.$this->feed->label.' ('.$sku.')';
		$res = $product->correct_stock($this->user, $this->feed->fk_entrepot, abs($diff), $diff > 0 ? 0 : 1, $label, 0, 'FEEDIMPORT-'.((int) $this->run->id));
		if ($res <= 0) {
			$this->run->log('Cannot set the stock of '.$sku.' to '.$qty.': '.$this->objectError($product), 'error');
			return -1;
		}
		$this->stock[$productid] = $qty;
		$this->changes++;
		return 1;
	}

	/**
	 * Set to 0 the stock of the linked items that were not in the file
	 *
	 * @return	void
	 */
	private function zeroMissing()
	{
		$sql = "SELECT rowid, sku, fk_product FROM ".MAIN_DB_PREFIX."feedimport_item";
		$sql .= " WHERE fk_feed = ".((int) $this->feed->id)." AND status = ".self::ITEM_LINKED." AND zeroed = 0";
		$sql .= " AND (fk_run_last_seen IS NULL OR fk_run_last_seen <> ".((int) $this->run->id).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->run->log('Cannot list the items to set to 0: '.$this->db->lasterror(), 'error');
			return;
		}
		$gone = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$gone[] = $obj;
		}
		$done = array();
		foreach ($gone as $obj) {
			if (isset($this->seen[$obj->sku])) {
				continue;	// seen, but its row could not be saved
			}
			if (isset($this->seenProducts[(int) $obj->fk_product])) {
				$done[] = (int) $obj->rowid;	// its product is now the product of another SKU of the file
				continue;
			}
			$res = 0;
			if (!empty($this->stock[(int) $obj->fk_product])) {
				$res = $this->setStock((int) $obj->fk_product, 0, $obj->sku);
				if ($res > 0) {
					$this->run->nb_zeroed++;
					$this->run->log($obj->sku.': no longer in the feed, stock set to 0');
				}
				$this->pace($res > 0);
			}
			if ($res >= 0) {
				$done[] = (int) $obj->rowid;
			}
		}
		foreach (array_chunk($done, 500) as $chunk) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."feedimport_item SET zeroed = 1 WHERE rowid IN (".implode(',', $chunk).")");
		}
	}

	/**
	 * Insert or update the item of an SKU, as seen in this run
	 *
	 * @param	string				$sku		SKU
	 * @param	array<string,mixed>	$values		Values
	 * @param	string				$hash		Hash of the values once applied ('' to apply them again next time)
	 * @param	int					$status		ITEM_*
	 * @param	int					$productid	Product linked
	 * @param	?string				$reason		How it was matched, or why it is to review (null: unchanged)
	 * @param	int					$candidate	To review: product it probably is
	 * @return	void
	 */
	private function saveItem($sku, $values, $hash, $status, $productid, $reason = null, $candidate = 0)
	{
		$now = $this->db->idate(dol_now());
		$label = isset($values['label']) ? dol_trunc((string) $values['label'], 250, 'right', 'UTF-8', 1) : '';
		$data = (string) json_encode($values);
		$item = isset($this->items[$sku]) ? $this->items[$sku] : null;

		if ($item) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_item SET";
			$sql .= " status = ".((int) $status);
			$sql .= ", fk_product = ".($productid > 0 ? (int) $productid : 'NULL');
			if ($reason !== null) {
				$sql .= ", reason = '".$this->db->escape(dol_trunc($reason, 250, 'right', 'UTF-8', 1))."'";
				$sql .= ", fk_product_candidate = ".($candidate > 0 ? (int) $candidate : 'NULL');
			}
			$sql .= ", label = '".$this->db->escape($label)."'";
			$sql .= ", data = '".$this->db->escape($data)."'";
			$sql .= ", hash = '".$this->db->escape($hash)."'";
			$sql .= ", date_last_seen = '".$now."', fk_run_last_seen = ".((int) $this->run->id).", zeroed = 0";
			$sql .= " WHERE rowid = ".((int) $item->id);
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."feedimport_item (fk_feed, sku, sku_norm, status, fk_product, fk_product_candidate, reason, label, data, hash,";
			$sql .= " date_first_seen, date_last_seen, fk_run_last_seen, zeroed)";
			$sql .= " VALUES (".((int) $this->feed->id).", '".$this->db->escape($sku)."', '".$this->db->escape(dol_trunc(feedimportNormaliseKey($sku), 128, 'right', 'UTF-8', 1))."',";
			$sql .= " ".((int) $status).", ".($productid > 0 ? (int) $productid : 'NULL').", ".($candidate > 0 ? (int) $candidate : 'NULL').",";
			$sql .= " ".($reason !== null ? "'".$this->db->escape(dol_trunc($reason, 250, 'right', 'UTF-8', 1))."'" : 'NULL').",";
			$sql .= " '".$this->db->escape($label)."', '".$this->db->escape($data)."', '".$this->db->escape($hash)."',";
			$sql .= " '".$now."', '".$now."', ".((int) $this->run->id).", 0)";
		}
		if (!$this->db->query($sql)) {
			$this->run->log('Cannot save the item '.$sku.': '.$this->db->lasterror(), 'error');
			return;
		}
		$this->changes++;
		if (!$item) {
			$item = new stdClass();
			$item->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'feedimport_item');
			$item->sku_norm = feedimportNormaliseKey($sku);
			$this->items[$sku] = $item;
			$this->itemsByNorm[$item->sku_norm][] = $sku;
		}
		if ($status == self::ITEM_LINKED && $productid > 0) {
			$this->seenProducts[$productid] = true;
		}
		$item->status = $status;
		$item->fk_product = $productid;
		$item->hash = $hash;
		$item->product_exists = ($productid > 0);
		$item->label = $label;
	}

	/**
	 * Mark the unchanged items seen in this run
	 *
	 * @return	void
	 */
	private function flushTouched()
	{
		if (empty($this->touched)) {
			return;
		}
		$now = $this->db->idate(dol_now());
		foreach (array_chunk($this->touched, 500) as $chunk) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_item SET date_last_seen = '".$now."', fk_run_last_seen = ".((int) $this->run->id).", zeroed = 0";
			$sql .= " WHERE rowid IN (".implode(',', array_map('intval', $chunk)).")";
			if (!$this->db->query($sql)) {
				$this->run->log('Cannot mark items as seen: '.$this->db->lasterror(), 'error');
			}
		}
		$this->touched = array();
	}

	/**
	 * Load the feed's items
	 *
	 * @return	void
	 */
	private function loadItems()
	{
		$sql = "SELECT i.rowid, i.sku, i.sku_norm, i.status, i.fk_product, i.hash, i.label, p.rowid as pid";
		$sql .= " FROM ".MAIN_DB_PREFIX."feedimport_item as i";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = i.fk_product";
		$sql .= " WHERE i.fk_feed = ".((int) $this->feed->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Cannot read the items of the feed: '.$this->db->lasterror());
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new stdClass();
			$item->id = (int) $obj->rowid;
			$item->sku_norm = (string) $obj->sku_norm;
			$item->status = (int) $obj->status;
			$item->fk_product = (int) $obj->fk_product;
			$item->hash = (string) $obj->hash;
			$item->label = (string) $obj->label;
			$item->product_exists = ((int) $obj->pid > 0);
			$this->items[(string) $obj->sku] = $item;
			$this->itemsByNorm[$item->sku_norm][] = (string) $obj->sku;
		}
		$this->hadItems = !empty($this->items);
	}

	/**
	 * Load the stock of the feed's warehouse
	 *
	 * @return	void
	 */
	private function loadStock()
	{
		if (!$this->stockEnabled) {
			return;
		}
		$sql = "SELECT fk_product, reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_entrepot = ".((int) $this->feed->fk_entrepot);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Cannot read the stock of the warehouse: '.$this->db->lasterror());
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$this->stock[(int) $obj->fk_product] = (float) $obj->reel;
		}
	}

	/**
	 * Load the supplier references of the feed's supplier (once)
	 *
	 * @return	void
	 */
	private function loadSupplierRefs()
	{
		if ($this->supplierRefs !== null) {
			return;
		}
		$this->supplierRefs = array();
		$this->supplierRefsByNorm = array();
		if (!$this->supplier) {
			return;
		}
		$sql = "SELECT pfp.ref_fourn, pfp.fk_product FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pfp.fk_product";
		$sql .= " WHERE pfp.fk_soc = ".((int) $this->supplier->id)." AND pfp.entity IN (".getEntity('productsupplierprice').")";
		$sql .= " AND pfp.ref_fourn IS NOT NULL AND pfp.ref_fourn <> ''";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Cannot read the supplier references: '.$this->db->lasterror());
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$ref = (string) $obj->ref_fourn;
			$this->supplierRefs[$ref] = (int) $obj->fk_product;
			$this->supplierRefsByNorm[feedimportNormaliseKey($ref)][] = $ref;
		}
	}

	/**
	 * Known SKUs (feed's items and supplier references) by their folded form, for the typo check (once)
	 *
	 * @return	void
	 */
	private function loadFolded()
	{
		if ($this->folded !== null) {
			return;
		}
		$this->folded = array();
		$this->loadSupplierRefs();
		foreach ($this->knownKeys() as $sku => $norm) {
			$this->folded[$this->fold($sku)] = $sku;
		}
	}

	/**
	 * Known SKUs with their normalised form: the feed's items linked to a product, and the supplier's references
	 *
	 * @return	array<string,string>	SKU => normalised SKU
	 */
	private function knownKeys()
	{
		if ($this->known !== null) {
			foreach ($this->known as $sku => $norm) {
				if (isset($this->seen[$sku])) {
					unset($this->known[$sku]);	// seen in the file: a distinct item, never a misspelling
				}
			}
			return $this->known;
		}
		$keys = array();
		foreach ($this->items as $sku => $item) {
			if ($item->status == self::ITEM_LINKED && $item->fk_product > 0) {
				$keys[(string) $sku] = $item->sku_norm;
			}
		}
		foreach ((array) $this->supplierRefsByNorm as $norm => $refs) {
			foreach ($refs as $ref) {
				$keys[(string) $ref] = (string) $norm;
			}
		}
		$this->known = $keys;
		return $keys;
	}

	/**
	 * Normalised SKU with the characters people confuse made the same: O = 0, I = L = 1
	 *
	 * @param	string	$sku	SKU
	 * @return	string
	 */
	private function fold($sku)
	{
		return feedimportNormaliseKey(strtr(dol_strtoupper((string) $sku), array('O' => '0', 'I' => '1', 'L' => '1')));
	}

	/**
	 * Whether two strings of the same length differ only by two neighbouring characters swapped
	 *
	 * @param	string	$a	String
	 * @param	string	$b	String
	 * @return	bool
	 */
	private function isTransposition($a, $b)
	{
		$len = strlen($a);
		if ($len != strlen($b) || $a === $b) {
			return false;
		}
		$i = 0;
		while ($i < $len && $a[$i] === $b[$i]) {
			$i++;	// up to the first difference
		}
		return $i < $len - 1 && $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i] && substr($a, $i + 2) === substr($b, $i + 2);
	}

	/**
	 * Product of a known SKU (feed item or supplier reference)
	 *
	 * @param	string	$key	SKU
	 * @return	int
	 */
	private function productOfKey($key)
	{
		if (isset($this->items[$key]) && $this->items[$key]->fk_product > 0) {
			return (int) $this->items[$key]->fk_product;
		}
		return isset($this->supplierRefs[$key]) ? (int) $this->supplierRefs[$key] : 0;
	}

	/**
	 * Label of a product
	 *
	 * @param	int		$productid	Product
	 * @return	string
	 */
	private function productLabel($productid)
	{
		$resql = $this->db->query("SELECT label FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $productid));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (string) $obj->label : '';
	}

	/**
	 * Label compared without case, spaces and punctuation
	 *
	 * @param	string	$label	Label
	 * @return	string
	 */
	private function normaliseLabel($label)
	{
		return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', dol_strtolower($label));
	}

	/**
	 * Product with this ref or barcode
	 *
	 * @param	string	$field	ref or barcode
	 * @param	string	$value	Value
	 * @return	int				Product id, 0 if none
	 */
	private function productIdBy($field, $value)
	{
		if ($value === '') {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ".($field == 'barcode' ? 'barcode' : 'ref')." = '".$this->db->escape($value)."'";
		$sql .= " AND entity IN (".getEntity('product').")";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Ref of a new product: the feed's prefix and the SKU, cleaned as Product::create() cleans it
	 *
	 * @param	string	$sku	SKU
	 * @return	string
	 */
	private function newRef($sku)
	{
		$ref = trim((string) $this->feed->params['refprefix']).$sku;
		if (!getDolGlobalInt('MAIN_SECURITY_ALLOW_UNSECURED_REF_LABELS')) {
			$ref = dol_sanitizeFileName(dol_string_nospecial(trim($ref)));
		}
		return dol_trunc($ref, 128, 'right', 'UTF-8', 1);
	}

	/**
	 * VAT rate of an item: from the feed, else the feed's default, else the product's
	 *
	 * @param	array<string,mixed>	$values		Values
	 * @param	?Product			$product	Product, if it exists
	 * @return	float
	 */
	private function vatRate($values, $product = null)
	{
		if (isset($values['vat_rate'])) {
			return (float) $values['vat_rate'];
		}
		if ($product && $product->id > 0) {
			return (float) $product->tva_tx;
		}
		return (float) price2num((string) $this->feed->params['vat']);
	}

	/**
	 * Error of a Dolibarr object, as one line
	 *
	 * @param	CommonObject	$object		Object
	 * @return	string
	 */
	private function objectError($object)
	{
		global $langs;

		$messages = array();
		if (!empty($object->error)) {
			$messages[] = $langs->transnoentitiesnoconv($object->error);
		}
		if (!empty($object->errors)) {
			foreach ($object->errors as $e) {
				$messages[] = $langs->transnoentitiesnoconv($e);
			}
		}
		$messages = array_unique($messages);
		return empty($messages) ? 'unknown error' : strip_tags(implode('; ', $messages));
	}


	/*
	 * Review queue
	 */

	/**
	 * Load an item of the feed (review queue actions)
	 *
	 * @param	int		$itemid		Item
	 * @param	int[]	$statuses	Statuses the item may have for the action
	 * @return	object|null			Row of llx_feedimport_item, null if not found or not in one of these statuses
	 */
	public function fetchItem($itemid, $statuses = array(self::ITEM_REVIEW, self::ITEM_NOT_CREATED))
	{
		$resql = $this->db->query("SELECT * FROM ".MAIN_DB_PREFIX."feedimport_item WHERE rowid = ".((int) $itemid)." AND fk_feed = ".((int) $this->feed->id));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->run->log('Item '.((int) $itemid).' not found', 'error');
			return null;
		}
		if (!in_array((int) $obj->status, $statuses)) {
			$this->run->log('Item '.$obj->sku.' is not waiting for this (already handled?)', 'error');
			return null;
		}
		return $obj;
	}

	/**
	 * Review queue: link an item to a product, and apply its last values to it
	 *
	 * @param	int		$itemid		Item
	 * @param	int		$productid	Product
	 * @return	int					>0 if OK, <0 on error (see the run's log)
	 */
	public function reviewLink($itemid, $productid)
	{
		$obj = $this->fetchItem($itemid);
		if (!$obj || $productid <= 0) {
			return -1;
		}
		$values = json_decode((string) $obj->data, true);
		$values = is_array($values) ? $values : array();
		$this->loadStock();
		$res = $this->applyValues($productid, (string) $obj->sku, $values, true);
		return $this->reviewSave($obj, self::ITEM_LINKED, $productid, 'Linked by hand', $res >= 0 ? sha1((string) json_encode($values)) : '');
	}

	/**
	 * Review queue: create the product of an item
	 *
	 * @param	int		$itemid		Item
	 * @return	int					Product id, <0 on error (see the run's log)
	 */
	public function reviewCreate($itemid)
	{
		$obj = $this->fetchItem($itemid);
		if (!$obj) {
			return -1;
		}
		$values = json_decode((string) $obj->data, true);
		$values = is_array($values) ? $values : array();
		$this->loadStock();
		$productid = $this->createProduct((string) $obj->sku, $values);
		if ($productid <= 0) {
			return -1;
		}
		$this->reviewSave($obj, self::ITEM_LINKED, $productid, 'Created by hand', sha1((string) json_encode($values)));
		return $productid;
	}

	/**
	 * Review queue: ignore an item for good (or put an ignored item back in the queue)
	 *
	 * @param	int		$itemid		Item
	 * @param	bool	$ignore		True to ignore, false to put back
	 * @return	int					>0 if OK, <0 on error
	 */
	public function reviewIgnore($itemid, $ignore = true)
	{
		$obj = $this->fetchItem($itemid, $ignore ? array(self::ITEM_REVIEW, self::ITEM_NOT_CREATED) : array(self::ITEM_IGNORED));
		if (!$obj) {
			return -1;
		}
		return $this->reviewSave($obj, $ignore ? self::ITEM_IGNORED : self::ITEM_REVIEW, 0, $ignore ? 'Ignored by hand' : 'Put back in the queue', '');
	}

	/**
	 * Save the outcome of a review action
	 *
	 * @param	object	$obj		Item row
	 * @param	int		$status		ITEM_*
	 * @param	int		$productid	Product
	 * @param	string	$reason		Reason
	 * @param	string	$hash		Hash of the values applied
	 * @return	int					>0 if OK, <0 on error
	 */
	private function reviewSave($obj, $status, $productid, $reason, $hash)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_item SET status = ".((int) $status);
		$sql .= ", fk_product = ".($productid > 0 ? (int) $productid : 'NULL');
		$sql .= ", reason = '".$this->db->escape($reason)."', hash = '".$this->db->escape($hash)."'";
		$sql .= " WHERE rowid = ".((int) $obj->rowid);
		if (!$this->db->query($sql)) {
			$this->run->log($this->db->lasterror(), 'error');
			return -1;
		}
		return 1;
	}
}
