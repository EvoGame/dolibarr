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
 * \file    feedimport/test/import_test.php
 * \ingroup feedimport
 * \brief   End-to-end tests of imports on a Dolibarr test database: matching, review queue, zeroing and its guards,
 *          XSD validation, the Email Collector hook and the scheduled job.
 *
 * WRITES TO THE DATABASE. Run it on a test installation only, with the modules Products, Stocks, Vendors, Scheduled jobs
 * and Feed Import enabled. It only creates and then deletes records whose names start with FITEST.
 *
 * Usage: php htdocs/custom/feedimport/test/import_test.php --test-database
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
if (!in_array('--test-database', $argv)) {
	fwrite(STDERR, "This test writes to the database. Run it on a test installation with --test-database.\n");
	exit(1);
}
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/feedimport/class/feedimportfeed.class.php');
dol_include_once('/feedimport/class/actions_feedimport.class.php');

$langs->loadLangs(array('main', 'feedimport@feedimport'));
$user = new User($db);
$user->fetch(0, '', '', 0, $conf->entity);
$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1");
$obj = $resql ? $db->fetch_object($resql) : null;
if (!$obj || $user->fetch((int) $obj->rowid) <= 0) {
	fwrite(STDERR, "No administrator user found\n");
	exit(1);
}
$user->loadRights();
foreach (array('product', 'stock', 'feedimport') as $module) {
	if (!isModEnabled($module)) {
		fwrite(STDERR, "Module $module is not enabled\n");
		exit(1);
	}
}

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

/**
 * First column of the first row of a query
 *
 * @param	string	$sql	Query (llx_ is replaced by the table prefix)
 * @return	mixed
 */
function q1($sql)
{
	global $db;
	$resql = $db->query(str_replace('llx_', MAIN_DB_PREFIX, $sql));
	$row = $resql ? $db->fetch_row($resql) : null;
	return $row ? $row[0] : null;
}

/**
 * Stock of a product (by ref) in a warehouse
 *
 * @param	string	$ref	Product ref
 * @param	int		$wid	Warehouse
 * @return	float
 */
function stockOf($ref, $wid)
{
	return (float) q1("SELECT ps.reel FROM llx_product_stock as ps INNER JOIN llx_product as p ON p.rowid = ps.fk_product WHERE p.ref = '".$ref."' AND ps.fk_entrepot = ".((int) $wid));
}

/**
 * Import a file as if uploaded
 *
 * @param	FeedImportFeed		$feed		Feed
 * @param	string				$content	File content
 * @param	array<string,mixed>	$options	Run options
 * @return	FeedImportRun|null
 */
function runFile($feed, $content, $options = array())
{
	global $user;
	$tmp = sys_get_temp_dir().'/feedimport_test_'.getmypid().'.csv';
	file_put_contents($tmp, $content);
	$run = $feed->run('upload', $user, array_merge(array('file' => $tmp, 'remote_name' => 'feed.csv'), $options));
	@unlink($tmp);
	$feed->fetch($feed->id);
	echo '  -> '.($run ? $run->status.': '.$run->summary() : 'no run: '.$feed->error)."\n";
	return $run;
}

/**
 * Delete everything the test creates (FITEST...)
 *
 * @return	void
 */
function cleanup()
{
	global $db;
	$feeds = "SELECT rowid FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE label LIKE 'FITEST%'";
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET fk_price_expression = NULL WHERE ref LIKE 'FITEST%'");
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."c_price_expression WHERE title LIKE 'FITEST%'");
	$products = "SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ref LIKE 'FITEST%'";
	$warehouses = "SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot WHERE ref LIKE 'FITEST%'";
	$supplier = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom LIKE 'FITEST%'";
	$resql = $db->query($feeds);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		dol_delete_dir_recursive(dirname(feedimportFeedDir((int) $obj->rowid, 'files')));
	}
	$queries = array(
		"DELETE FROM llx_feedimport_item WHERE fk_feed IN ($feeds)",
		"DELETE FROM llx_feedimport_run WHERE fk_feed IN ($feeds)",
		"DELETE FROM llx_feedimport_feed WHERE label LIKE 'FITEST%'",
		"DELETE FROM llx_product_fournisseur_price_log WHERE fk_product_fournisseur IN (SELECT rowid FROM llx_product_fournisseur_price WHERE fk_product IN ($products))",
		"DELETE FROM llx_product_fournisseur_price WHERE fk_product IN ($products)",
		"DELETE FROM llx_stock_mouvement WHERE fk_product IN ($products)",
		"DELETE FROM llx_product_stock WHERE fk_product IN ($products)",
		"DELETE FROM llx_product_price WHERE fk_product IN ($products)",
		"DELETE FROM llx_product_lang WHERE fk_product IN ($products)",
		"DELETE FROM llx_product_perentity WHERE fk_product IN ($products)",
		"DELETE FROM llx_product_extrafields WHERE fk_object IN ($products)",
		"DELETE FROM llx_product WHERE ref LIKE 'FITEST%'",
		"DELETE FROM llx_entrepot WHERE ref LIKE 'FITEST%'",
		"DELETE FROM llx_societe WHERE nom LIKE 'FITEST%'",
	);
	foreach ($queries as $sql) {
		// MySQL refuses a subquery on the table being deleted from: materialise the ids first
		if (preg_match('/^DELETE FROM llx_product_fournisseur_price_log WHERE fk_product_fournisseur IN \((.*)\)$/', $sql, $m)) {
			$ids = array(0);
			$res = $db->query(str_replace('llx_', MAIN_DB_PREFIX, $m[1]));
			while ($res && ($o = $db->fetch_row($res))) {
				$ids[] = (int) $o[0];
			}
			$sql = "DELETE FROM llx_product_fournisseur_price_log WHERE fk_product_fournisseur IN (".implode(',', $ids).")";
		}
		$db->query(str_replace('llx_', MAIN_DB_PREFIX, $sql));
	}
	unset($supplier, $warehouses);
}

cleanup();

$soc = new Societe($db);
$soc->name = 'FITEST supplier';
$soc->fournisseur = 1;
$soc->code_fournisseur = '-1';
$sid = $soc->create($user);
$soc->fetch($sid);
$warehouse = new Entrepot($db);
$warehouse->label = 'FITEST-CSV';
$warehouse->statut = 1;
$wid = $warehouse->create($user);
$warehouse = new Entrepot($db);
$warehouse->label = 'FITEST-XML';
$warehouse->statut = 1;
$wid2 = $warehouse->create($user);
check($sid > 0 && $wid > 0 && $wid2 > 0, 'supplier and warehouses created');

// A product the supplier already sells us, with its supplier reference
$existing = new Product($db);
$existing->ref = 'FITEST-OLD';
$existing->label = 'Existing gizmo';
$existing->type = 0;
$existing->status = 1;
$existing->status_buy = 1;
$pid = $existing->create($user);
$pf = new ProductFournisseur($db);
$pf->id = $pid;
$pf->update_buyprice(1, 5, $user, 'HT', $soc, 0, 'AC-900', 20, 0, 0, 0, 0, '', '', array(), '', 5, 'HT', 1, $conf->currency);

$feed = new FeedImportFeed($db);
$feed->label = 'FITEST CSV';
$feed->method = 'upload';
$feed->filetype = 'csv';
$feed->fk_soc = $sid;
$feed->fk_entrepot = $wid;
$feed->params['refprefix'] = 'FITEST-';
$feed->params['vat'] = '20';
$feed->params['decimal'] = ',';
$feed->params['maxnew'] = 2;
$feed->params['pause'] = 0;
$feed->mapping = array('SKU' => 'sku', 'Name' => 'label', 'Cost' => 'buy_price', 'RRP' => 'sell_price', 'Qty' => 'stock', 'EAN' => 'barcode');
$feed->create($user);

echo "Run 1: first import\n";
$csv1 = "SKU;Name;Cost;RRP;Qty;EAN\nAC-100;Widget;12,50;29,99;5;\nAC-200;Gadget;3,00;9,99;>100;\nAC-300;Doohickey;1.234,00;2000;0;\nAC-900;Gizmo;5,50;12;7;\n;No sku;1;1;1;\nAC-100;Dup row;1;1;1;\n";
$run = runFile($feed, $csv1);
check($run && $run->status == 'warning' && $run->nb_created == 3, '3 products created; warning for the row without SKU and the repeated SKU');
check(q1("SELECT label FROM llx_product WHERE ref = 'FITEST-AC-100'") == 'Widget', 'product created with the prefix and its label');
check(abs((float) q1("SELECT price FROM llx_product WHERE ref = 'FITEST-AC-100'") - 29.99) < 0.001, 'selling price');
check(abs((float) q1("SELECT unitprice FROM llx_product_fournisseur_price WHERE ref_fourn = 'AC-300' AND fk_soc = ".$sid) - 1234) < 0.001, 'buy price with thousands separator');
check(stockOf('FITEST-AC-200', $wid) == 100 && stockOf('FITEST-AC-100', $wid) == 5, 'stock, ">100" read as 100');
check((int) q1("SELECT fk_product FROM llx_feedimport_item WHERE sku = 'AC-900' AND fk_feed = ".$feed->id) == $pid, 'existing product found by its supplier reference');
check(stockOf('FITEST-OLD', $wid) == 7 && (int) q1("SELECT COUNT(*) FROM llx_product_fournisseur_price WHERE fk_product = ".$pid) == 1, 'its stock set, its supplier price updated, not duplicated');

echo "Run 2: same file\n";
$run = runFile($feed, $csv1);
check($run && $run->status == 'unchanged', 'same file skipped');

echo "Run 3: changes, an item gone, typos, new items\n";
$csv3 = "SKU;Name;Cost;RRP;Qty;EAN\nAC-100;Widget;13,00;29,99;4;\nAC-3OO;Doohickey;1.234,00;2000;3;\nAC-900;Gizmo;5,50;12;7;\nAC-400;New one;1;2;1;\nAC-500;New two;1;2;1;\nAC-600;New three;1;2;1;\nAC-0300;Doohickey;1;1;1;\n";
$run = runFile($feed, $csv3);
check(abs((float) q1("SELECT unitprice FROM llx_product_fournisseur_price WHERE ref_fourn = 'AC-100' AND fk_soc = ".$sid) - 13) < 0.001 && stockOf('FITEST-AC-100', $wid) == 4, 'changed price and stock updated');
check(stockOf('FITEST-AC-200', $wid) == 0 && $run->nb_zeroed == 1, 'item gone from the feed: stock set to 0');
check(q1("SELECT status FROM llx_feedimport_item WHERE sku = 'AC-3OO' AND fk_feed = ".$feed->id) == '0', 'O typed for 0: sent to review ('.q1("SELECT reason FROM llx_feedimport_item WHERE sku = 'AC-3OO' AND fk_feed = ".$feed->id).')');
check(q1("SELECT status FROM llx_feedimport_item WHERE sku = 'AC-600' AND fk_feed = ".$feed->id) == '0', 'third new product of the run: sent to review (limit 2)');
check(q1("SELECT fk_product FROM llx_feedimport_item WHERE sku = 'AC-0300' AND fk_feed = ".$feed->id) == q1("SELECT rowid FROM llx_product WHERE ref = 'FITEST-AC-300'"), 'AC-0300 linked to the product of AC-300 (normalised SKU)');
check(stockOf('FITEST-AC-300', $wid) == 1, 'and that product is not zeroed although AC-300 left the feed');

echo "Run 4: truncated file\n";
$csv4 = "SKU;Name;Cost;RRP;Qty;EAN\nAC-100;Widget;13,00;29,99;4;\n";
$run = runFile($feed, $csv4);
check($run && $run->status == 'warning' && stockOf('FITEST-OLD', $wid) == 7, 'safety threshold: nothing zeroed');
$run = runFile($feed, $csv4, array('force' => true, 'forcezero' => true));
check(stockOf('FITEST-OLD', $wid) == 0, 'threshold ignored on request: zeroed');

echo "Run 5: SKU column missing\n";
$run = runFile($feed, "Ref;Name\nX;Y\n");
check($run && $run->status == 'error', 'run fails');

echo "Review queue\n";
$review = new FeedImportRun($db);
$review->fk_feed = $feed->id;
$review->trigger_type = 'review';
$review->create($user);
$importer = new FeedImportImporter($db, $feed, $review, $user);
$item = (int) q1("SELECT rowid FROM llx_feedimport_item WHERE sku = 'AC-3OO' AND fk_feed = ".$feed->id);
check($importer->reviewLink($item, (int) q1("SELECT fk_product_candidate FROM llx_feedimport_item WHERE rowid = ".$item)) > 0, 'link to the suggested product');
check($importer->reviewLink($item, $pid) < 0, 'an item already linked cannot be linked again');
$newpid = $importer->reviewCreate((int) q1("SELECT rowid FROM llx_feedimport_item WHERE sku = 'AC-600' AND fk_feed = ".$feed->id));
check($newpid > 0 && q1("SELECT ref FROM llx_product WHERE rowid = ".((int) $newpid)) == 'FITEST-AC-600', 'create the product of an item');

echo "XML feed received by email\n";
$dir = sys_get_temp_dir().'/feedimport_test_'.getmypid();
dol_mkdir($dir);
$xsd = '<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:dolibarr="urn:dolibarr">
  <xs:element name="catalog"><xs:complexType><xs:sequence><xs:element name="product" maxOccurs="unbounded"><xs:complexType><xs:sequence>
    <xs:element name="sku" type="xs:string" dolibarr:field="sku"/><xs:element name="name" type="xs:string" dolibarr:field="label"/>
    <xs:element name="price" dolibarr:field="buy_price"><xs:simpleType><xs:restriction base="xs:decimal"><xs:minInclusive value="0"/></xs:restriction></xs:simpleType></xs:element>
    <xs:element name="available" type="xs:integer" dolibarr:field="stock"/>
  </xs:sequence></xs:complexType></xs:element></xs:sequence></xs:complexType></xs:element>
</xs:schema>';
$good = '<?xml version="1.0"?><catalog><product><sku>BX-1</sku><name>Bolt &amp; nut</name><price>0.20</price><available>500</available></product><product><sku>BX-2</sku><name>Washer</name><price>0.05</price><available>0</available></product></catalog>';
$xmlfeed = new FeedImportFeed($db);
$xmlfeed->label = 'FITEST XML';
$xmlfeed->method = 'email';
$xmlfeed->filetype = 'xml';
$xmlfeed->fk_soc = $sid;
$xmlfeed->fk_entrepot = $wid2;
$xmlfeed->params['refprefix'] = 'FITEST-';
$xmlfeed->params['pattern'] = 'catalog_*.xml';
$xmlfeed->params['pause'] = 0;
$xmlfeed->create($user);
file_put_contents(feedimportFeedDir($xmlfeed->id, 'schema').'/feed.xsd', $xsd);
$info = $xmlfeed->getParser()->readXsd(feedimportFeedDir($xmlfeed->id, 'schema').'/feed.xsd');
$xmlfeed->params['schema_type'] = 'xsd';
$xmlfeed->params['schema_file'] = 'feed.xsd';
$xmlfeed->params['itempath'] = $info['itempath'];
foreach ($info['fields'] as $field => $def) {
	$xmlfeed->mapping[$field] = feedimportResolveTargetField($def['target'], feedimportTargetFields());
}
$xmlfeed->update($user);
check($xmlfeed->mapping == array('sku' => 'sku', 'name' => 'label', 'price' => 'buy_price', 'available' => 'stock'), 'mapping from the XSD annotations');

$hook = new ActionsFeedImport($db);
$collector = new stdClass();
$operation = ActionsFeedImport::OPERATION;
check($hook->doCollectImapOneCollector(array('actionparam' => '999999', 'attachments' => array()), $collector, $operation, null) < 0, 'hook: unknown feed refused');
$other = 'hookother';
check($hook->doCollectImapOneCollector(array(), $collector, $other, null) == 0 && empty($hook->errors), 'hook: other operations ignored, previous error cleared');
check($hook->doCollectImapOneCollector(array('actionparam' => (string) $xmlfeed->id, 'attachments' => array('price.pdf' => 'x')), $collector, $operation, null) < 0, 'hook: no matching attachment refused');
$bad = str_replace('<price>0.05</price>', '<price>-1</price>', $good);
check($hook->doCollectImapOneCollector(array('actionparam' => 'feed='.$xmlfeed->id, 'attachments' => array('notes.txt' => 'x', 'catalog_1.xml' => $bad)), $collector, $operation, null) == 0, 'hook: invalid file queued');
$cron = new FeedImportFeed($db);
$cron->cronRunDue();
$xmlfeed->fetch($xmlfeed->id);
check($xmlfeed->last_status == 'error' && $xmlfeed->params['inbox'] === '' && (int) q1("SELECT COUNT(*) FROM llx_product WHERE ref LIKE 'FITEST-BX%'") == 0, 'scheduled job: file rejected by the XSD, nothing imported');
$hook->doCollectImapOneCollector(array('actionparam' => (string) $xmlfeed->id, 'attachments' => array('catalog_2.xml' => $good)), $collector, $operation, null);
$cron = new FeedImportFeed($db);
$cron->cronRunDue();
$xmlfeed->fetch($xmlfeed->id);
check($xmlfeed->last_status == 'ok' && q1("SELECT label FROM llx_product WHERE ref = 'FITEST-BX-1'") == 'Bolt & nut' && stockOf('FITEST-BX-1', $wid2) == 500, 'scheduled job: valid file imported');

echo "Pacing\n";
$paced = new FeedImportFeed($db);
$paced->label = 'FITEST paced';
$paced->method = 'upload';
$paced->filetype = 'csv';
$paced->fk_soc = $sid;
$paced->fk_entrepot = $wid2;
$paced->params['refprefix'] = 'FITEST-';
$paced->params['blocksize'] = 2;
$paced->params['pause'] = 1;
$paced->mapping = array('SKU' => 'sku', 'Name' => 'label', 'Qty' => 'stock');
$paced->create($user);
$start = time();
$run = runFile($paced, "SKU;Name;Qty\nPC-1;One;1\nPC-2;Two;1\nPC-3;Three;1\nPC-4;Four;1\nPC-5;Five;1\n");
$took = time() - $start;
check($run && $run->nb_created == 5 && substr_count($run->log, 'pausing 1 s') == 2 && $took >= 2, '5 new rows in blocks of 2: paused twice ('.$took.' s)');
$start = time();
$run = runFile($paced, "SKU;Name;Qty\nPC-1;One;1\nPC-2;Two;1\nPC-3;Three;1\nPC-4;Four;1\nPC-5;Five;1\n", array('force' => true));
check($run && $run->nb_unchanged == 5 && strpos($run->log, 'pausing') === false, 'unchanged rows are not counted: no pause');

if (isModEnabled('dynamicprices')) {
	echo "Dynamic prices\n";
	require_once DOL_DOCUMENT_ROOT.'/product/dynamic_price/class/price_expression.class.php';
	$expression = new PriceExpression($db);
	$expression->title = 'FITEST margin';
	$expression->expression = '#supplier_min_price# * 2';
	$eid = $expression->create($user);
	$formula = new Product($db);
	$formula->fetch(0, 'FITEST-AC-100');
	$formula->setPriceExpression($eid);
	$stored = (float) q1("SELECT price FROM llx_product WHERE ref = 'FITEST-AC-100'");
	$run = runFile($feed, "SKU;Name;Cost;RRP;Qty;EAN\nAC-100;Widget;14,00;99,99;4;\n", array('force' => true, 'forcezero' => false));
	check(abs((float) q1("SELECT price FROM llx_product WHERE ref = 'FITEST-AC-100'") - $stored) < 0.001 && strpos($run->log, 'price formula') !== false, 'feed selling price not written over a price formula');
	$check = new Product($db);
	$check->fetch(0, 'FITEST-AC-100');
	check(abs((float) $check->price - 28) < 0.001, 'the formula uses the new buy price: '.$check->price);
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."c_price_expression WHERE rowid = ".((int) $eid));
}

echo "Schedule and lock\n";
$scheduled = new FeedImportFeed($db);
$scheduled->method = 'sftp';
$scheduled->schedule_type = 'daily';
$scheduled->schedule_time = '06:30';
$from = dol_mktime(7, 0, 0, 10, 15, 2026, 'tzserver');
check(dol_print_date($scheduled->computeNextRun($from), '%Y-%m-%d %H:%M', 'tzserver') == '2026-10-16 06:30', 'daily, time passed: tomorrow');
$from = dol_mktime(5, 0, 0, 10, 15, 2026, 'tzserver');
check(dol_print_date($scheduled->computeNextRun($from), '%Y-%m-%d %H:%M', 'tzserver') == '2026-10-15 06:30', 'daily, time to come: today');
$scheduled->schedule_type = 'hours';
$scheduled->schedule_hours = 6;
check($scheduled->computeNextRun($from) == $from + 6 * 3600, 'every 6 hours');
check($xmlfeed->claim(), 'feed claimed');
$second = new FeedImportFeed($db);
$second->fetch($xmlfeed->id);
check(!$second->claim() && $second->run('manual', $user) === null, 'a second run is refused while the first holds the feed');

dol_delete_dir_recursive($dir);
cleanup();
check((int) q1("SELECT COUNT(*) FROM llx_product WHERE ref LIKE 'FITEST%'") == 0, 'test records deleted');
echo "\n".($fails ? $fails.' FAILED' : 'All passed')."\n";
exit($fails ? 1 : 0);
