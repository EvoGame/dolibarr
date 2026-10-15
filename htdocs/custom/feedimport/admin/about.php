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
 * \file    feedimport/admin/about.php
 * \ingroup feedimport
 * \brief   About page of module Feed Import
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
dol_include_once('/feedimport/core/modules/modFeedImport.class.php');

$langs->loadLangs(array('admin', 'feedimport@feedimport'));

if (!$user->admin) {
	accessforbidden();
}


/*
 * View
 */

$module = new modFeedImport($db);

$title = $langs->trans('FeedImportSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-admin-about');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(feedimportAdminPrepareHead(), 'about', $title, -1, 'fa-file-import');

print '<p>'.$langs->trans('ModuleFeedImportDesc').'</p>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('Version').'</td><td>'.dolPrintLabel($module->version).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportModuleNumber').'</td><td>'.((int) $module->numero).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportDocumentation').'</td><td>'.$langs->trans('FeedImportDocumentationHelp').'</td></tr>';
print '</table>';
print '</div>';

print '<div class="info margintoponly">'.$langs->trans('FeedImportSupportNotice').'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
