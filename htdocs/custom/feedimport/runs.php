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
 * \file    feedimport/runs.php
 * \ingroup feedimport
 * \brief   Runs of the supplier feeds
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
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportfeed.class.php');

$langs->loadLangs(array('products', 'feedimport@feedimport'));

if (!isModEnabled('feedimport') || !$user->hasRight('feedimport', 'read')) {
	accessforbidden();
}

$search_feed = GETPOSTINT('search_feed');
$search_status = GETPOST('search_status', 'aZ09');
$page = max(0, GETPOSTINT('page'));
$limit = 50;


/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('FeedImportRuns');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-runs');

$morehtml = '';
if ($user->admin) {
	$morehtml = dolGetButtonTitle($langs->trans('FeedImportSetupMenu'), '', 'fa fa-cog', dol_buildpath('/feedimport/admin/setup.php', 1));
}
print load_fiche_titre($title, $morehtml, 'fa-file-import');

// Feeds and their state
$feeds = FeedImportFeed::listFeeds($db);
$waiting = 0;
$resql = $db->query("SELECT COUNT(i.rowid) as nb FROM ".MAIN_DB_PREFIX."feedimport_item as i INNER JOIN ".MAIN_DB_PREFIX."feedimport_feed as f ON f.rowid = i.fk_feed WHERE i.status = 0 AND f.entity = ".((int) $conf->entity));
if ($resql && ($obj = $db->fetch_object($resql))) {
	$waiting = (int) $obj->nb;
}
if ($waiting > 0) {
	print '<div class="warning">'.$langs->trans('FeedImportReviewWaiting', $waiting).' <a href="'.dol_buildpath('/feedimport/review.php', 1).'">'.$langs->trans('FeedImportReview').'</a></div>';
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginbottomonly">';
print $form->selectarray('search_feed', $feeds, $search_feed, $langs->trans('FeedImportAllFeeds'), 0, 0, '', 0, 0, 0, '', 'minwidth200');
$statuses = array();
foreach (array('ok', 'warning', 'error', 'unchanged', 'running') as $status) {
	$statuses[$status] = $langs->trans('FeedImportRunStatus_'.$status);
}
print ' '.$form->selectarray('search_status', $statuses, $search_status, $langs->trans('FeedImportAllStatuses'), 0, 0, '', 0, 0, 0, '', 'minwidth150');
print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print '</div>';
print '</form>';

print feedimportRunsTable($db, $search_feed, $limit, $search_status, $page * $limit);

// Paging
$param = '&search_feed='.$search_feed.'&search_status='.urlencode($search_status);
print '<div class="center margintoponly">';
if ($page > 0) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?page='.($page - 1).$param.'">'.$langs->trans('Previous').'</a>';
}
$resql = $db->query("SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."feedimport_run WHERE entity = ".((int) $conf->entity).($search_feed ? " AND fk_feed = ".((int) $search_feed) : "").($search_status !== '' ? " AND status = '".$db->escape($search_status)."'" : ""));
$total = ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->nb : 0;
if (($page + 1) * $limit < $total) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?page='.($page + 1).$param.'">'.$langs->trans('Next').'</a>';
}
print '</div>';

llxFooter();
$db->close();
