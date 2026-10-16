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
 * \file    feedimport/review.php
 * \ingroup feedimport
 * \brief   Review queue: feed items not created because they may be a product Dolibarr already has
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportfeed.class.php');

$langs->loadLangs(array('products', 'feedimport@feedimport'));

if (!isModEnabled('feedimport') || !$user->hasRight('feedimport', 'read')) {
	accessforbidden();
}
$canwrite = $user->hasRight('feedimport', 'write');

$action = GETPOST('action', 'aZ09');
$itemid = GETPOSTINT('itemid');
$search_feed = GETPOSTINT('search_feed');
$search_status = GETPOST('search_status', 'intcomma') === '' ? '0' : (string) GETPOSTINT('search_status');
$page = max(0, GETPOSTINT('page'));
$limit = 50;
$param = '&search_feed='.$search_feed.'&search_status='.$search_status.'&page='.$page;


/*
 * Actions
 */

if (in_array($action, array('link', 'linkcandidate', 'create', 'ignore', 'putback')) && $canwrite && $itemid > 0) {
	$resql = $db->query("SELECT i.fk_feed, i.fk_product_candidate FROM ".MAIN_DB_PREFIX."feedimport_item as i INNER JOIN ".MAIN_DB_PREFIX."feedimport_feed as f ON f.rowid = i.fk_feed WHERE i.rowid = ".((int) $itemid)." AND f.entity = ".((int) $conf->entity));
	$obj = $resql ? $db->fetch_object($resql) : null;
	$feed = new FeedImportFeed($db);
	if (!$obj || $feed->fetch((int) $obj->fk_feed) <= 0) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
	// Actions are logged in a run of their own, so what they changed can be seen
	$run = new FeedImportRun($db);
	$run->fk_feed = $feed->id;
	$run->trigger_type = 'review';
	$run->create($user);
	$importer = new FeedImportImporter($db, $feed, $run, $user);

	$res = -1;
	if ($action == 'link' || $action == 'linkcandidate') {
		$productid = ($action == 'linkcandidate') ? (int) $obj->fk_product_candidate : GETPOSTINT('productid'.$itemid);
		if ($productid <= 0) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Product')), null, 'errors');
		} else {
			$res = $importer->reviewLink($itemid, $productid);
			$run->nb_updated = ($res > 0 ? 1 : 0);
		}
	} elseif ($action == 'create') {
		$res = $importer->reviewCreate($itemid);
		$run->nb_created = ($res > 0 ? 1 : 0);
	} else {
		$res = $importer->reviewIgnore($itemid, $action == 'ignore');
		$run->log($action == 'ignore' ? 'Item ignored' : 'Item put back in the review queue');
	}
	$run->status = ($res > 0 && $run->nb_errors == 0) ? FeedImportRun::STATUS_OK : FeedImportRun::STATUS_ERROR;
	$run->date_end = dol_now();
	$run->update();
	if ($res > 0) {
		setEventMessages($langs->trans('FeedImportReviewDone'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('FeedImportReviewFailed'), array(dol_escape_htmltag(trim($run->log))), 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&'));
	exit;
}


/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('FeedImportReview');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-review');
print load_fiche_titre($title, '', 'fa-file-import');

print '<span class="opacitymedium">'.$langs->trans('FeedImportReviewHelp').'</span><br><br>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginbottomonly">';
print $form->selectarray('search_feed', FeedImportFeed::listFeeds($db), $search_feed, $langs->trans('FeedImportAllFeeds'), 0, 0, '', 0, 0, 0, '', 'minwidth200');
$statuses = array(0 => $langs->trans('FeedImportItemStatus0'), 2 => $langs->trans('FeedImportItemStatus2'), 3 => $langs->trans('FeedImportItemStatus3'));
print ' '.$form->selectarray('search_status', $statuses, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print '</div>';
print '</form>';

$sql = "SELECT i.rowid, i.fk_feed, i.sku, i.label, i.data, i.reason, i.fk_product_candidate, i.date_first_seen, i.date_last_seen, f.label as feedlabel";
$sql .= " FROM ".MAIN_DB_PREFIX."feedimport_item as i INNER JOIN ".MAIN_DB_PREFIX."feedimport_feed as f ON f.rowid = i.fk_feed";
$sql .= " WHERE f.entity = ".((int) $conf->entity)." AND i.status = ".((int) $search_status);
if ($search_feed > 0) {
	$sql .= " AND i.fk_feed = ".((int) $search_feed);
}
$sqlcount = preg_replace('/^SELECT .* FROM /U', 'SELECT COUNT(i.rowid) as nb FROM ', $sql);
$sql .= $db->order('i.date_last_seen,i.rowid', 'DESC,DESC').$db->plimit($limit, $page * $limit);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
}
$rows = array();
while ($resql && ($obj = $db->fetch_object($resql))) {
	$rows[] = $obj;
}
$resqlcount = $db->query($sqlcount);
$total = ($resqlcount && ($o = $db->fetch_object($resqlcount))) ? (int) $o->nb : count($rows);

$targets = feedimportTargetFields();
$product = new Product($db);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('FeedImportFeed').'</td><td>'.$langs->trans('FeedImportFieldSku').'</td><td>'.$langs->trans('Label').'</td>';
print '<td>'.$langs->trans('FeedImportValues').'</td><td>'.$langs->trans('FeedImportReason').'</td><td>'.$langs->trans('FeedImportCandidate').'</td>';
print '<td>'.$langs->trans('FeedImportLastSeen').'</td>';
if ($canwrite) {
	print '<td>'.$langs->trans('FeedImportActions').'</td>';
}
print '</tr>';

foreach ($rows as $obj) {
	$values = json_decode((string) $obj->data, true);
	$summary = array();
	foreach ((array) $values as $code => $value) {
		if (in_array($code, array('sku', 'label', 'description'))) {
			continue;
		}
		$summary[] = '<span class="opacitymedium">'.dol_escape_htmltag(isset($targets[$code]) ? $targets[$code] : $code).':</span> '.dol_escape_htmltag(dol_trunc((string) $value, 40));
	}
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($obj->feedlabel).'</td>';
	print '<td class="nowraponall"><strong>'.dol_escape_htmltag($obj->sku).'</strong></td>';
	print '<td class="tdoverflowmax250" title="'.dol_escape_htmltag($obj->label).'">'.dol_escape_htmltag($obj->label).'</td>';
	print '<td class="small minwidth200">'.implode('<br>', $summary).'</td>';
	print '<td class="small">'.dol_escape_htmltag((string) $obj->reason).'</td>';
	print '<td>';
	if ($obj->fk_product_candidate > 0 && $product->fetch((int) $obj->fk_product_candidate) > 0) {
		print $product->getNomUrl(1).'<br><span class="small opacitymedium">'.dol_escape_htmltag(dol_trunc($product->label, 50)).'</span>';
	}
	print '</td>';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_last_seen), 'day', 'tzuserrel').'</td>';
	if ($canwrite) {
		print '<td class="nowraponall">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&').'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="itemid" value="'.((int) $obj->rowid).'">';
		if ($search_status == '2') {
			print '<button type="submit" name="action" value="putback" class="button smallpaddingimp">'.$langs->trans('FeedImportPutBack').'</button>';
		} else {
			if ($obj->fk_product_candidate > 0) {
				print '<button type="submit" name="action" value="linkcandidate" class="button smallpaddingimp">'.$langs->trans('FeedImportLinkCandidate').'</button> ';
			}
			print '<button type="submit" name="action" value="create" class="button smallpaddingimp">'.$langs->trans('FeedImportCreateProduct').'</button> ';
			print '<button type="submit" name="action" value="ignore" class="button smallpaddingimp button-cancel">'.$langs->trans('FeedImportIgnore').'</button>';
			print '<div class="margintoponly">';
			print $form->select_produits(0, 'productid'.((int) $obj->rowid), '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'minwidth200 maxwidth300', 1, '', null, 1);
			print ' <button type="submit" name="action" value="link" class="button smallpaddingimp">'.$langs->trans('FeedImportLink').'</button>';
			print '</div>';
		}
		print '</form>';
		print '</td>';
	}
	print '</tr>';
}
if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('FeedImportReviewEmpty').'</span></td></tr>';
}
print '</table>';
print '</div>';

print '<div class="center margintoponly">';
$base = $_SERVER['PHP_SELF'].'?search_feed='.$search_feed.'&search_status='.$search_status;
if ($page > 0) {
	print '<a class="butAction" href="'.$base.'&page='.($page - 1).'">'.$langs->trans('Previous').'</a>';
}
if (($page + 1) * $limit < $total) {
	print '<a class="butAction" href="'.$base.'&page='.($page + 1).'">'.$langs->trans('Next').'</a>';
}
print '</div>';

llxFooter();
$db->close();
