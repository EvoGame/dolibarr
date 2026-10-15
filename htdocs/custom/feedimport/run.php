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
 * \file    feedimport/run.php
 * \ingroup feedimport
 * \brief   A run of a supplier feed: its counts, its log, its file
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportfeed.class.php');

$langs->loadLangs(array('products', 'feedimport@feedimport'));

if (!isModEnabled('feedimport') || !$user->hasRight('feedimport', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$run = new FeedImportRun($db);
if ($run->fetch($id) <= 0 || $run->entity != $conf->entity) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$feed = new FeedImportFeed($db);
$feed->fetch($run->fk_feed);
$file = ($run->filename !== '') ? feedimportFeedDir($feed->id, 'files').'/'.basename($run->filename) : '';
$fileexists = ($file !== '' && is_file($file));


/*
 * Actions
 */

if ($action == 'download' && $fileexists) {
	top_httphead('application/octet-stream');
	header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName($run->filename).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
}

if ($action == 'reimport' && $fileexists && $user->hasRight('feedimport', 'write')) {
	$newrun = $feed->run('manual', $user, array('file' => $file, 'remote_name' => $run->remote_name, 'force' => true));
	if (!$newrun) {
		setEventMessages(dol_escape_htmltag($feed->error), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$run->id);
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$newrun->id);
	}
	exit;
}


/*
 * View
 */

$title = $langs->trans('FeedImportRun').' '.$feed->label;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-run');

print load_fiche_titre($title, '<a href="'.dol_buildpath('/feedimport/runs.php', 1).'?search_feed='.$feed->id.'">'.$langs->trans('BackToList').'</a>', 'fa-file-import');

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('FeedImportFeed').'</td><td>'.($user->admin ? '<a href="'.dol_buildpath('/feedimport/admin/feed.php', 1).'?id='.$feed->id.'">'.dol_escape_htmltag($feed->label).'</a>' : dol_escape_htmltag($feed->label)).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.feedimportRunStatusBadge($run->status).'</td></tr>';
print '<tr><td>'.$langs->trans('FeedImportTrigger').'</td><td>'.$langs->trans('FeedImportTrigger_'.$run->trigger_type).'</td></tr>';
print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.dol_print_date($run->date_start, 'dayhoursec', 'tzuserrel').'</td></tr>';
print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.($run->date_end ? dol_print_date($run->date_end, 'dayhoursec', 'tzuserrel') : '').'</td></tr>';
if ($run->fk_user > 0) {
	$u = new User($db);
	if ($u->fetch($run->fk_user) > 0) {
		print '<tr><td>'.$langs->trans('User').'</td><td>'.$u->getNomUrl(-1).'</td></tr>';
	}
}
print '<tr><td>'.$langs->trans('File').'</td><td>'.dol_escape_htmltag($run->remote_name);
if ($fileexists) {
	print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$run->id.'&action=download&token='.newToken().'">'.img_picto($langs->trans('Download'), 'download', 'class="paddingleft"').'</a>';
} elseif ($run->filename !== '') {
	print ' <span class="opacitymedium">('.$langs->trans('FeedImportFileGone').')</span>';
}
print '</td></tr>';
print '</table>';
print '</div><div class="fichehalfright">';
print '<table class="border centpercent tableforfield">';
foreach (array('nb_rows' => 'FeedImportNbRows', 'nb_created' => 'FeedImportNbCreated', 'nb_updated' => 'FeedImportNbUpdated', 'nb_unchanged' => 'FeedImportNbUnchanged',
	'nb_review' => 'FeedImportNbReview', 'nb_skipped' => 'FeedImportNbSkipped', 'nb_zeroed' => 'FeedImportNbZeroed', 'nb_warnings' => 'FeedImportNbWarnings', 'nb_errors' => 'FeedImportNbErrors') as $counter => $key) {
	print '<tr><td class="titlefield">'.$langs->trans($key).'</td><td>'.((int) $run->$counter).'</td></tr>';
}
print '</table>';
print '</div></div>';
print '<div class="clearboth"></div>';

if ($fileexists && $user->hasRight('feedimport', 'write')) {
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans('FeedImportReimportHelp'), $langs->trans('FeedImportReimport'), 'default', $_SERVER['PHP_SELF'].'?id='.$run->id.'&action=reimport&token='.newToken());
	print '</div>';
}
if ($run->nb_review > 0) {
	print '<div class="info">'.$langs->trans('FeedImportRunReview', $run->nb_review).' <a href="'.dol_buildpath('/feedimport/review.php', 1).'?search_feed='.$feed->id.'">'.$langs->trans('FeedImportReview').'</a></div>';
}

print load_fiche_titre($langs->trans('FeedImportLog'), '', '');
$log = dol_escape_htmltag($run->log, 0, 1);
$log = preg_replace('/^(\S+ )(ERROR .*)$/m', '$1<span style="color: #b00020; font-weight: bold;">$2</span>', $log);
$log = preg_replace('/^(\S+ )(WARNING .*)$/m', '$1<span style="color: #a15c00;">$2</span>', (string) $log);
print '<pre class="small" style="white-space: pre-wrap; max-height: 600px; overflow: auto;">'.$log.'</pre>';

llxFooter();
$db->close();
