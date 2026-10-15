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
 * \file    feedimport/admin/setup.php
 * \ingroup feedimport
 * \brief   Setup of module Feed Import: the list of feeds, and the button to add one
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportfeed.class.php');

$langs->loadLangs(array('admin', 'products', 'stocks', 'feedimport@feedimport'));

if (!$user->admin) {
	accessforbidden();
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('FeedImportSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(feedimportAdminPrepareHead(), 'feeds', $title, -1, 'fa-file-import');

print '<span class="opacitymedium">'.$langs->trans('FeedImportSetupHelp').'</span><br><br>';

$newbutton = dolGetButtonTitle($langs->trans('FeedImportAddFeed'), '', 'fa fa-plus-circle', dol_buildpath('/feedimport/admin/feed.php', 1).'?action=create');
print load_fiche_titre($langs->trans('FeedImportFeeds'), $newbutton, '');

$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE entity = ".((int) $conf->entity)." ORDER BY rowid";
$resql = $db->query($sql);
$ids = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$ids[] = (int) $obj->rowid;
	}
} else {
	dol_print_error($db);
}

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Label').'</td>';
print '<td>'.$langs->trans('FeedImportMethod').'</td>';
print '<td>'.$langs->trans('FeedImportFileType').'</td>';
print '<td>'.$langs->trans('Supplier').'</td>';
print '<td>'.$langs->trans('Warehouse').'</td>';
print '<td>'.$langs->trans('FeedImportSchedule').'</td>';
print '<td>'.$langs->trans('FeedImportNextRun').'</td>';
print '<td>'.$langs->trans('FeedImportLastRun').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '</tr>';

$soc = new Societe($db);
$warehouse = new Entrepot($db);
foreach ($ids as $id) {
	$feed = new FeedImportFeed($db);
	if ($feed->fetch($id) <= 0) {
		continue;
	}
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/feedimport/admin/feed.php', 1).'?id='.$feed->id.'">'.img_picto('', 'fa-file-import', 'class="pictofixedwidth"').dol_escape_htmltag($feed->label).'</a></td>';
	print '<td>'.$langs->trans('FeedImportMethod_'.$feed->method).'</td>';
	print '<td>'.strtoupper($feed->filetype).'</td>';
	print '<td>'.($feed->fk_soc > 0 && $soc->fetch($feed->fk_soc) > 0 ? $soc->getNomUrl(1) : '').'</td>';
	print '<td>'.($feed->fk_entrepot > 0 && $warehouse->fetch($feed->fk_entrepot) > 0 ? $warehouse->getNomUrl(1) : '').'</td>';
	print '<td>'.dol_escape_htmltag(feedimportScheduleLabel($feed)).'</td>';
	print '<td>'.($feed->status && $feed->next_run ? dol_print_date($feed->next_run, 'dayhour', 'tzuserrel') : '').'</td>';
	print '<td>'.($feed->last_run ? dol_print_date($feed->last_run, 'dayhour', 'tzuserrel').' '.feedimportRunStatusBadge($feed->last_status) : '').'</td>';
	print '<td class="center">'.($feed->status ? dolGetStatus($langs->trans('Enabled'), '', '', 'status4', 3) : dolGetStatus($langs->trans('Disabled'), '', '', 'status5', 3)).'</td>';
	print '</tr>';
}
if (empty($ids)) {
	print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('FeedImportNoFeed').'</span></td></tr>';
}
print '</table>';
print '</div>';

// What the module needs from the rest of Dolibarr
print load_fiche_titre($langs->trans('Status'), '', '');
$problems = feedimportSetupProblems();
foreach ($problems as $problem) {
	print '<div class="warning">'.$problem.'</div>';
}
if (empty($problems)) {
	print '<div class="ok">'.$langs->trans('FeedImportReady').'</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
