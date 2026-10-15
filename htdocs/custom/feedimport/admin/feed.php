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
 * \file    feedimport/admin/feed.php
 * \ingroup feedimport
 * \brief   One feed of module Feed Import: its settings, in the order a feed is set up, and its actions
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportfeed.class.php');

$langs->loadLangs(array('admin', 'products', 'stocks', 'companies', 'other', 'feedimport@feedimport'));

if (!$user->admin) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$feed = new FeedImportFeed($db);
if ($id > 0 && $feed->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
if ($id <= 0 && !in_array($action, array('create', 'add'))) {
	header('Location: '.dol_buildpath('/feedimport/admin/setup.php', 1));
	exit;
}

$self = $_SERVER['PHP_SELF'].'?id='.$feed->id;
$preview = null;	// rows shown after "Fetch & preview"
$previewfields = array();
$previewerror = '';


/*
 * Actions
 */

/**
 * Read the settings form into the feed
 *
 * @param	FeedImportFeed	$feed	Feed
 * @return	string[]				Errors (translated)
 */
function feedimportReadForm($feed)
{
	global $langs;

	$errors = array();
	$feed->label = trim(GETPOST('label', 'alphanohtml'));
	$feed->status = GETPOSTINT('status') ? 1 : 0;
	$method = GETPOST('method', 'aZ09');
	$feed->method = in_array($method, FeedImportFeed::METHODS) ? $method : 'ftp';
	$feed->filetype = GETPOST('filetype', 'aZ09') == 'xml' ? 'xml' : 'csv';
	$feed->fk_soc = max(0, GETPOSTINT('fk_soc'));
	$feed->fk_entrepot = max(0, GETPOSTINT('fk_entrepot'));
	$schedule = GETPOST('schedule_type', 'aZ09');
	$feed->schedule_type = in_array($schedule, array('manual', 'daily', 'hours')) ? $schedule : 'manual';
	$feed->schedule_time = '';
	if (preg_match('/^(\d{1,2}):(\d{2})$/', trim(GETPOST('schedule_time', 'alphanohtml')), $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
		$feed->schedule_time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
	}
	$feed->schedule_hours = max(0, GETPOSTINT('schedule_hours'));

	$p = &$feed->params;
	foreach (array('host', 'login', 'directory', 'pattern', 'hostkey', 'itempath', 'refprefix', 'notify') as $key) {
		$p[$key] = trim(GETPOST($key, 'alphanohtml'));
	}
	$p['url'] = trim(GETPOST('url', 'url'));
	$p['port'] = max(0, GETPOSTINT('port'));
	$p['passive'] = GETPOSTINT('passive') ? 1 : 0;
	$p['insecure'] = GETPOSTINT('insecure') ? 1 : 0;
	$password = GETPOST('password', 'password');
	if ($password !== '') {
		$feed->setPassword($password);
	} elseif (GETPOSTINT('clearpassword')) {
		$feed->setPassword('');
	}
	$delimiter = GETPOST('delimiter', 'nohtml');
	$p['delimiter'] = in_array($delimiter, array('', ';', ',', '\t', '|')) ? $delimiter : '';
	$p['enclosure'] = GETPOST('enclosure', 'nohtml') === "'" ? "'" : '"';
	$encoding = GETPOST('encoding', 'alphanohtml');
	$p['encoding'] = in_array($encoding, array('UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15', 'UTF-16')) ? $encoding : 'UTF-8';
	$p['headerline'] = max(1, GETPOSTINT('headerline'));
	$p['decimal'] = GETPOST('decimal', 'nohtml') == ',' ? ',' : '.';
	$matchkey = GETPOST('matchkey', 'aZ09');
	$p['matchkey'] = in_array($matchkey, array('supplierref', 'ref', 'barcode')) ? $matchkey : 'supplierref';
	$p['create'] = GETPOSTINT('create') ? 1 : 0;
	$p['fk_category'] = max(0, GETPOSTINT('fk_category'));
	$p['vat'] = GETPOST('vat', 'alphanohtml');
	$p['pricebase'] = GETPOST('pricebase', 'aZ09') == 'TTC' ? 'TTC' : 'HT';
	$p['zero'] = GETPOSTINT('zero') ? 1 : 0;
	$p['minratio'] = max(0, min(100, GETPOSTINT('minratio')));
	$p['maxnew'] = max(0, GETPOSTINT('maxnew'));
	$p['keep'] = max(1, GETPOSTINT('keep'));
	$p['blocksize'] = max(0, GETPOSTINT('blocksize'));
	$p['pause'] = max(0, min(300, GETPOSTINT('pause')));

	// Mapping table
	$fields = GETPOST('map_field', 'array');
	$targets = GETPOST('map_target', 'array');
	$known = feedimportTargetFields();
	$mapping = array();
	$feedfields = array();
	foreach ((array) $fields as $i => $field) {
		$field = trim((string) $field);
		if ($field === '') {
			continue;
		}
		$feedfields[$field] = $field;
		$target = isset($targets[$i]) ? (string) $targets[$i] : '';
		if ($target !== '' && isset($known[$target])) {
			if (in_array($target, $mapping, true)) {
				$errors[] = dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportErrorTwice', $known[$target]));
				continue;
			}
			$mapping[$field] = $target;
		}
	}
	$feed->mapping = $mapping;
	$p['fields'] = array_values($feedfields);

	if ($feed->label === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Label'));
	}
	if ($feed->schedule_type == 'daily' && $feed->schedule_time === '' && in_array($feed->method, FeedImportFeed::REMOTE_METHODS)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('FeedImportScheduleTime'));
	}
	if ($feed->schedule_type == 'hours' && $feed->schedule_hours <= 0 && in_array($feed->method, FeedImportFeed::REMOTE_METHODS)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('FeedImportScheduleHours'));
	}
	if ($feed->fk_soc <= 0 && $p['matchkey'] == 'supplierref') {
		$errors[] = $langs->trans('FeedImportErrorNoSupplier');
	}
	if ($feed->method == 'sftp' && $p['hostkey'] !== '' && FeedImportTransport::md5Fingerprint($p['hostkey']) === '' && !FeedImportTransport::canCheckSha256()) {
		$errors[] = $langs->trans('FeedImportErrorHostKeyMd5');
	}
	if ($p['notify'] !== '') {
		foreach (preg_split('/\s*,\s*/', $p['notify']) as $email) {
			if (!isValidEmail($email)) {
				$errors[] = dol_escape_htmltag($langs->transnoentitiesnoconv('ErrorBadEMail', $email));
			}
		}
	}
	return $errors;
}

/**
 * Read a schema uploaded with the form (XSD, or two-row mapping CSV) into the feed
 *
 * @param	FeedImportFeed	$feed	Feed (saved, so it has an id)
 * @return	string[]				Errors (translated); messages are set as event messages
 */
function feedimportReadSchemaUpload($feed)
{
	global $langs;

	if (empty($_FILES['schemafile']['tmp_name']) || !is_uploaded_file($_FILES['schemafile']['tmp_name'])) {
		return array();
	}
	$name = dol_sanitizeFileName(basename((string) $_FILES['schemafile']['name']));
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (!in_array($ext, array('xsd', 'csv', 'txt'))) {
		return array($langs->trans('FeedImportErrorSchemaType'));
	}
	$dir = feedimportFeedDir($feed->id, 'schema');
	foreach (dol_dir_list($dir, 'files') as $old) {
		dol_delete_file($old['fullname'], 0, 0, 0, null, false, 0);
	}
	$res = dol_move_uploaded_file($_FILES['schemafile']['tmp_name'], $dir.'/'.$name, 1, 0, $_FILES['schemafile']['error']);
	if (!is_numeric($res) || $res <= 0) {
		return array($langs->trans(is_string($res) ? $res : 'ErrorFailToCreateFile'));
	}

	$parser = $feed->getParser();
	$known = feedimportTargetFields();
	if ($ext == 'xsd') {
		$info = $parser->readXsd($dir.'/'.$name);
		if ($info === null) {
			return array(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportErrorXsd', $parser->error)));
		}
		$feed->filetype = 'xml';
		$feed->params['schema_type'] = 'xsd';
		$feed->params['itempath'] = $info['itempath'];
		$feed->params['fields'] = array_keys($info['fields']);
		$feed->params['fieldtypes'] = array();
		$annotated = 0;
		foreach ($info['fields'] as $field => $def) {
			$feed->params['fieldtypes'][$field] = $def['type'];
			$code = feedimportResolveTargetField($def['target'], $known);
			if ($code !== '' && !in_array($code, $feed->mapping, true)) {
				$feed->mapping[$field] = $code;
				$annotated++;
			}
		}
		// fields the XSD does not have cannot be in the files
		$feed->mapping = array_intersect_key($feed->mapping, $info['fields']);
		setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportXsdRead', count($info['fields']), $info['itempath'], $annotated)), null, 'mesgs');
	} else {
		$unknown = array();
		$mapping = $parser->readMappingFile($dir.'/'.$name, $known, $unknown);
		if ($mapping === null) {
			return array(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportErrorMappingFile', $parser->error)));
		}
		$feed->params['schema_type'] = 'mapping';
		$feed->params['fieldtypes'] = array();
		$feed->mapping = $mapping;
		$header = new FeedImportParser();
		$header->filetype = 'csv';
		$feed->params['fields'] = (array) $header->fields($dir.'/'.$name);
		setEventMessages($langs->trans('FeedImportMappingRead', count($mapping)), null, 'mesgs');
		if (!empty($unknown)) {
			setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportMappingUnknown', implode(', ', $unknown))), null, 'warnings');
		}
	}
	$feed->params['schema_file'] = $name;
	return array();
}

if (GETPOST('cancel', 'alpha')) {
	header('Location: '.($feed->id > 0 ? $self : dol_buildpath('/feedimport/admin/setup.php', 1)));
	exit;
}

if ($action == 'add' || $action == 'update') {
	$errors = feedimportReadForm($feed);
	if (empty($errors)) {
		$db->begin();
		$res = ($action == 'add') ? $feed->create($user) : $feed->update($user);
		if ($res > 0) {
			$errors = feedimportReadSchemaUpload($feed);
			if (empty($errors) && !empty($_FILES['schemafile']['tmp_name'])) {
				$res = $feed->update($user);
			}
		}
		if ($res > 0 && empty($errors)) {
			$db->commit();
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$feed->id);
			exit;
		}
		$db->rollback();
		if ($res <= 0) {
			$errors[] = dol_escape_htmltag($feed->error);
		}
	}
	setEventMessages('', $errors, 'errors');
	$action = ($action == 'add') ? 'create' : '';
}

if ($action == 'removeschema' && $feed->id > 0) {
	foreach (dol_dir_list(feedimportFeedDir($feed->id, 'schema'), 'files') as $old) {
		dol_delete_file($old['fullname'], 0, 0, 0, null, false, 0);
	}
	$feed->params['schema_file'] = '';
	$feed->params['schema_type'] = '';
	$feed->params['fieldtypes'] = array();
	$feed->update($user);
	header('Location: '.$self);
	exit;
}

if ($action == 'template' && $feed->id > 0) {
	$fields = array_unique(array_merge((array) $feed->params['fields'], array_keys($feed->mapping)));
	if (empty($fields)) {
		$fields = array('SKU', 'Name', 'Price', 'Stock');
	}
	$content = FeedImportParser::buildMappingFile($fields, $feed->mapping);
	top_httphead('text/csv');
	header('Content-Disposition: attachment; filename="mapping_'.dol_sanitizeFileName($feed->label).'.csv"');
	print $content;
	exit;
}

if ($action == 'test' && $feed->id > 0) {
	if (!in_array($feed->method, FeedImportFeed::REMOTE_METHODS)) {
		setEventMessages($langs->trans('FeedImportTestNotRemote'), null, 'warnings');
	} else {
		$transport = $feed->getTransport();
		$files = $transport->test();
		if ($files === null) {
			setEventMessages($langs->trans('FeedImportTestFailed'), array(dol_escape_htmltag($transport->error)), 'errors');
		} else {
			$selected = $transport->selectFile(dol_now());
			$list = dol_escape_htmltag(implode(', ', array_slice($files, 0, 30)).(count($files) > 30 ? '…' : ''));
			setEventMessages($langs->trans('FeedImportTestOk', count($files)), array($list), 'mesgs');
			if ($feed->method == 'sftp' && trim((string) $feed->params['hostkey']) === '') {
				$md5 = $transport->probeHostKeyMd5();
				if ($md5 !== '') {
					setEventMessages($langs->trans('FeedImportHostKeyLearnt', $md5), null, 'warnings');
				}
			}
			if ($selected === null) {
				setEventMessages(dol_escape_htmltag($transport->error), null, 'warnings');
			} else {
				setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportTestSelected', preg_replace('/\?.*$/', '', $selected))), null, 'mesgs');
			}
		}
	}
	$action = '';
}

if ($action == 'preview' && $feed->id > 0) {
	// The file to look at: fetched now for a remote feed, else the waiting or the last file kept
	$file = '';
	if (in_array($feed->method, FeedImportFeed::REMOTE_METHODS)) {
		$transport = $feed->getTransport();
		$remote = $transport->selectFile(dol_now());
		if ($remote !== null) {
			$file = DOL_DATA_ROOT.'/feedimport/temp/preview_'.$feed->id.'.'.$feed->filetype;
			dol_mkdir(dirname($file));
			if ($transport->download($remote, $file) < 0) {
				$file = '';
			}
		}
		if ($file === '') {
			$previewerror = $transport->error;
		}
	} else {
		$files = dol_dir_list(feedimportFeedDir($feed->id, 'files'), 'files', 0, '', '', 'name', SORT_DESC);
		if (!empty($files)) {
			$file = $files[0]['fullname'];
		} else {
			$previewerror = $langs->transnoentitiesnoconv('FeedImportPreviewNoFile');
		}
	}
	if ($file !== '') {
		$parser = $feed->getParser();
		$fields = $parser->fields($file);
		if ($fields === null) {
			$previewerror = $parser->error;
		} else {
			$previewfields = $fields;
			$preview = array();
			foreach ($parser->rows($file) as $row) {
				$preview[] = $row;
				if (count($preview) >= 15) {
					break;
				}
			}
			if ($parser->error !== '') {
				$previewerror = $parser->error;
			}
			// Remember the fields, so they can be mapped
			$known = (array) $feed->params['fields'];
			$new = array_diff($fields, $known);
			if (!empty($new)) {
				$feed->params['fields'] = array_values(array_unique(array_merge($known, $fields)));
				$feed->update($user);
				setEventMessages($langs->trans('FeedImportPreviewNewFields', count($new)), null, 'mesgs');
			}
		}
	}
	$action = '';
}

if (($action == 'run' || $action == 'upload') && $feed->id > 0) {
	$options = array('force' => GETPOSTINT('force') ? true : false, 'forcezero' => GETPOSTINT('forcezero') ? true : false);
	if ($action == 'upload') {
		if (empty($_FILES['feedfile']['tmp_name']) || !is_uploaded_file($_FILES['feedfile']['tmp_name'])) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('File')), null, 'errors');
			header('Location: '.$self);
			exit;
		}
		$tmp = DOL_DATA_ROOT.'/feedimport/temp/upload_'.$feed->id.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt');
		dol_mkdir(dirname($tmp));
		$res = dol_move_uploaded_file($_FILES['feedfile']['tmp_name'], $tmp, 1, 0, $_FILES['feedfile']['error']);
		if (!is_numeric($res) || $res <= 0) {
			setEventMessages($langs->trans(is_string($res) ? $res : 'ErrorFailToCreateFile'), null, 'errors');
			header('Location: '.$self);
			exit;
		}
		$options['file'] = $tmp;
		$options['remote_name'] = basename((string) $_FILES['feedfile']['name']);
		$options['force'] = true;
	}
	$run = $feed->run($action == 'upload' ? 'upload' : 'manual', $user, $options);
	if (!empty($tmp)) {
		@unlink($tmp);
	}
	if (!$run) {
		setEventMessages(dol_escape_htmltag($feed->error), null, 'errors');
		header('Location: '.$self);
		exit;
	}
	header('Location: '.dol_buildpath('/feedimport/run.php', 1).'?id='.$run->id);
	exit;
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $feed->id > 0) {
	if ($feed->delete() > 0) {
		setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportDeleted', $feed->label)), null, 'mesgs');
		header('Location: '.dol_buildpath('/feedimport/admin/setup.php', 1));
		exit;
	}
	setEventMessages($feed->error, null, 'errors');
}


/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);

$title = $langs->trans('FeedImportSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-feedimport page-admin-feed');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(feedimportAdminPrepareHead(), $feed->id > 0 ? 'feed'.$feed->id : 'create', $title, -1, 'fa-file-import');

if ($action == 'delete') {
	print $form->formconfirm($self, $langs->trans('FeedImportDeleteFeed'), dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportConfirmDelete', $feed->label)), 'confirm_delete', '', 0, 1);
}

$p = $feed->params;
$isnew = ($feed->id <= 0);

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($isnew ? '' : '?id='.$feed->id).'" enctype="multipart/form-data" id="feedform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($isnew ? 'add' : 'update').'">';

/**
 * Title row of a section of the form
 *
 * @param	string	$title	Translated title
 * @param	string	$help	Translated help (HTML)
 * @return	void
 */
function feedimportSection($title, $help = '')
{
	print '<tr class="liste_titre"><td colspan="2">'.$title.($help !== '' ? ' <span class="opacitymedium small">'.$help.'</span>' : '').'</td></tr>';
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// General
print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" name="label" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag($feed->label).'" placeholder="'.dol_escape_htmltag($langs->trans('FeedImportLabelPlaceholder')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('Enabled'), $langs->trans('FeedImportEnabledHelp')).'</td><td><input type="checkbox" name="status" value="1"'.($feed->status ? ' checked' : '').'></td></tr>';

// 1. Retrieval method
feedimportSection('1. '.$langs->trans('FeedImportStepMethod'));
$methods = array();
foreach (FeedImportFeed::METHODS as $method) {
	$methods[$method] = $langs->trans('FeedImportMethod_'.$method);
}
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportMethod').'</td><td>';
print $form->selectarray('method', $methods, $feed->method, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// 2. File type
feedimportSection('2. '.$langs->trans('FeedImportStepFileType'));
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportFileType').'</td><td>';
print $form->selectarray('filetype', array('csv' => 'CSV', 'xml' => 'XML'), $feed->filetype, 0, 0, 0, '', 0, 0, 0, '', 'width100');
print '</td></tr>';
print '<tr class="oddeven fi-t fi-t-csv"><td>'.$langs->trans('FeedImportDelimiter').'</td><td>';
print $form->selectarray('delimiter', array('' => $langs->trans('FeedImportDetect'), ';' => '; '.$langs->trans('FeedImportSemicolon'), ',' => ', '.$langs->trans('FeedImportComma'), '\t' => $langs->trans('FeedImportTab'), '|' => '|'), $p['delimiter'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print ' &nbsp; '.$langs->trans('FeedImportEnclosure').' ';
print $form->selectarray('enclosure', array('"' => '"', "'" => "'"), $p['enclosure'], 0, 0, 0, '', 0, 0, 0, '', 'width50');
print '</td></tr>';
print '<tr class="oddeven fi-t fi-t-csv"><td>'.$form->textwithpicto($langs->trans('FeedImportHeaderLine'), $langs->trans('FeedImportHeaderLineHelp')).'</td><td><input type="number" min="1" name="headerline" class="width50" value="'.((int) $p['headerline']).'"></td></tr>';
print '<tr class="oddeven fi-t fi-t-csv"><td>'.$langs->trans('FeedImportEncoding').'</td><td>';
$encodings = array('UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252 (Excel)', 'ISO-8859-1' => 'ISO-8859-1', 'ISO-8859-15' => 'ISO-8859-15', 'UTF-16' => 'UTF-16');
print $form->selectarray('encoding', $encodings, $p['encoding'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td></tr>';
print '<tr class="oddeven fi-t fi-t-xml"><td>'.$form->textwithpicto($langs->trans('FeedImportItemPath'), $langs->trans('FeedImportItemPathHelp')).'</td><td><input type="text" name="itempath" class="minwidth300" value="'.dol_escape_htmltag((string) $p['itempath']).'" placeholder="/catalog/products/product"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportDecimal').'</td><td>';
print $form->selectarray('decimal', array('.' => $langs->trans('FeedImportDecimalDot'), ',' => $langs->trans('FeedImportDecimalComma')), $p['decimal'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';

// 3. Schema and mapping
feedimportSection('3. '.$langs->trans('FeedImportStepMapping'), $langs->trans('FeedImportStepMappingHelp'));
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportSchema'), $langs->trans('FeedImportSchemaHelp')).'</td><td>';
if ($p['schema_file'] !== '') {
	print img_picto('', $p['schema_type'] == 'xsd' ? 'fa-code' : 'fa-file-csv', 'class="pictofixedwidth"').dol_escape_htmltag($p['schema_file']);
	print ' <span class="opacitymedium">('.$langs->trans($p['schema_type'] == 'xsd' ? 'FeedImportSchemaXsd' : 'FeedImportSchemaMapping').')</span>';
	print ' <a class="reposition" href="'.$self.'&action=removeschema&token='.newToken().'">'.img_delete($langs->trans('Delete')).'</a><br>';
}
print '<input type="file" name="schemafile" accept=".xsd,.csv,.txt">';
if (!$isnew) {
	print ' &nbsp; <a href="'.$self.'&action=template&token='.newToken().'">'.img_picto('', 'download', 'class="pictofixedwidth"').$langs->trans('FeedImportDownloadTemplate').'</a>';
}
print '</td></tr>';

$targets = feedimportTargetFields();
$fields = array_values(array_unique(array_merge((array) $p['fields'], array_keys($feed->mapping))));
print '<tr class="oddeven"><td class="tdtop">'.$langs->trans('FeedImportMapping').'</td><td>';
print '<table class="noborder nohover" id="mappingtable">';
print '<tr class="liste_titre"><td>'.$langs->trans('FeedImportFeedField').'</td><td>'.$langs->trans('FeedImportFieldTypeXsd').'</td><td>'.$langs->trans('FeedImportDolibarrField').'</td></tr>';
$i = 0;
foreach (array_merge($fields, array('', '')) as $field) {
	$type = isset($p['fieldtypes'][$field]) ? $p['fieldtypes'][$field] : '';
	print '<tr>';
	if ($field === '') {
		print '<td><input type="text" name="map_field['.$i.']" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('FeedImportAddField')).'"></td>';
	} else {
		print '<td><input type="hidden" name="map_field['.$i.']" value="'.dol_escape_htmltag($field).'"><span class="fieldname">'.dol_escape_htmltag($field).'</span></td>';
	}
	print '<td class="opacitymedium">'.dol_escape_htmltag($type).'</td>';
	print '<td>'.$form->selectarray('map_target['.$i.']', $targets, isset($feed->mapping[$field]) ? $feed->mapping[$field] : '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth250').'</td>';
	print '</tr>';
	$i++;
}
print '</table>';
if (!isset(array_flip($feed->mapping)['sku'])) {
	print '<div class="warning">'.$langs->trans('FeedImportWarningNoSku').'</div>';
} elseif (!empty($p['create']) && !isset(array_flip($feed->mapping)['label'])) {
	print '<div class="warning">'.$langs->trans('FeedImportWarningNoLabel').'</div>';
}
print '</td></tr>';

// 4. Access
feedimportSection('4. '.$langs->trans('FeedImportStepAccess'));
$remote = 'fi-m fi-m-ftp fi-m-ftpes fi-m-ftps fi-m-sftp';
print '<tr class="oddeven '.$remote.'"><td class="fieldrequired">'.$langs->trans('FeedImportHost').'</td><td><input type="text" name="host" class="minwidth300" value="'.dol_escape_htmltag((string) $p['host']).'" placeholder="ftp.supplier.com">';
print ' &nbsp; '.$langs->trans('Port').' <input type="number" min="0" max="65535" name="port" class="width75" value="'.((int) $p['port'] ?: '').'" placeholder="'.$langs->trans('Default').'"></td></tr>';
print '<tr class="oddeven fi-m fi-m-ftp fi-m-ftpes fi-m-ftps fi-m-sftp fi-m-http"><td>'.$langs->trans('Login').'</td><td><input type="text" name="login" class="minwidth200" autocomplete="off" value="'.dol_escape_htmltag((string) $p['login']).'"></td></tr>';
print '<tr class="oddeven fi-m fi-m-ftp fi-m-ftpes fi-m-ftps fi-m-sftp fi-m-http"><td>'.$langs->trans('Password').'</td><td><input type="password" name="password" class="minwidth200" autocomplete="new-password" value="" placeholder="'.dol_escape_htmltag($p['password'] !== '' ? $langs->trans('FeedImportPasswordUnchanged') : '').'">';
if ($p['password'] !== '') {
	print ' &nbsp; <label><input type="checkbox" name="clearpassword" value="1"> '.$langs->trans('FeedImportClearPassword').'</label>';
}
print '</td></tr>';
print '<tr class="oddeven '.$remote.'"><td>'.$form->textwithpicto($langs->trans('FeedImportDirectory'), $langs->trans('FeedImportDirectoryHelp')).'</td><td><input type="text" name="directory" class="minwidth300" value="'.dol_escape_htmltag((string) $p['directory']).'" placeholder="/outgoing"></td></tr>';
print '<tr class="oddeven fi-m fi-m-ftp fi-m-ftpes fi-m-ftps fi-m-sftp fi-m-email"><td class="fieldrequired">'.$form->textwithpicto($langs->trans('FeedImportPattern'), $langs->trans('FeedImportPatternHelp')).'</td><td><input type="text" name="pattern" class="minwidth300" value="'.dol_escape_htmltag((string) $p['pattern']).'" placeholder="stock_{YYYYMMDD}.csv, *.csv"></td></tr>';
print '<tr class="oddeven fi-m fi-m-ftp fi-m-ftpes fi-m-ftps"><td>'.$langs->trans('FeedImportPassive').'</td><td><input type="checkbox" name="passive" value="1"'.(!empty($p['passive']) ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven fi-m fi-m-ftpes fi-m-ftps fi-m-http"><td>'.$form->textwithpicto($langs->trans('FeedImportInsecure'), $langs->trans('FeedImportInsecureHelp')).'</td><td><input type="checkbox" name="insecure" value="1"'.(!empty($p['insecure']) ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven fi-m fi-m-sftp"><td>'.$form->textwithpicto($langs->trans('FeedImportHostKey'), $langs->trans('FeedImportHostKeyHelp')).'</td><td><input type="text" name="hostkey" class="minwidth400" value="'.dol_escape_htmltag((string) $p['hostkey']).'" placeholder="79:ea:b1:ce:…">';
if (!$isnew && $feed->method == 'sftp' && trim((string) $p['hostkey']) === '') {
	print '<div class="warning">'.$langs->trans('FeedImportHostKeyMissing').'</div>';
}
print '</td></tr>';
print '<tr class="oddeven fi-m fi-m-http"><td class="fieldrequired">'.$form->textwithpicto($langs->trans('URL'), $langs->trans('FeedImportUrlHelp')).'</td><td><input type="text" name="url" class="minwidth500" value="'.dol_escape_htmltag((string) $p['url']).'" placeholder="https://supplier.com/feed.csv?key=…"></td></tr>';
foreach (array('ftp', 'ftpes', 'ftps', 'sftp', 'http') as $method) {
	if (!FeedImportTransport::isSupported($method)) {
		print '<tr class="oddeven fi-m fi-m-'.$method.'"><td></td><td><div class="warning">'.$langs->trans('FeedImportProblemProtocol', $langs->transnoentitiesnoconv('FeedImportMethod_'.$method)).'</div></td></tr>';
	}
}
print '<tr class="oddeven fi-m fi-m-email"><td class="tdtop">'.$langs->trans('FeedImportEmailSetup').'</td><td>';
if ($isnew) {
	print '<span class="opacitymedium">'.$langs->trans('FeedImportEmailSaveFirst').'</span>';
} else {
	print $langs->trans('FeedImportEmailHelp', $langs->transnoentitiesnoconv('FeedImportEmailOperation'), '<strong>'.$feed->id.'</strong>');
	print '<br><a href="'.DOL_URL_ROOT.'/admin/emailcollector_list.php">'.img_picto('', 'email', 'class="pictofixedwidth"').$langs->trans('FeedImportEmailCollectors').'</a>';
	if (!isModEnabled('emailcollector')) {
		print '<div class="warning">'.$langs->trans('FeedImportProblemEmailCollector').'</div>';
	}
	if ($p['inbox'] !== '') {
		print '<div class="info">'.dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportEmailWaiting', preg_replace('/^\d{8}-\d{6}_/', '', $p['inbox']), dol_print_date((int) $p['inbox_date'], 'dayhour', 'tzuserrel'))).'</div>';
	}
}
print '</td></tr>';
print '<tr class="oddeven fi-m fi-m-upload"><td></td><td><span class="opacitymedium">'.$langs->trans('FeedImportUploadHelp').'</span></td></tr>';

// 5. Schedule
feedimportSection('5. '.$langs->trans('FeedImportStepSchedule'));
print '<tr class="oddeven fi-m fi-m-ftp fi-m-ftpes fi-m-ftps fi-m-sftp fi-m-http"><td>'.$langs->trans('FeedImportSchedule').'</td><td>';
print $form->selectarray('schedule_type', array('manual' => $langs->trans('FeedImportScheduleManual'), 'daily' => $langs->trans('FeedImportScheduleDaily'), 'hours' => $langs->trans('FeedImportScheduleHours')), $feed->schedule_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print ' <span class="fi-s fi-s-daily">'.$langs->trans('FeedImportScheduleTime').' <input type="time" name="schedule_time" value="'.dol_escape_htmltag($feed->schedule_time).'"></span>';
print ' <span class="fi-s fi-s-hours">'.$langs->trans('FeedImportScheduleHours').' <input type="number" min="1" max="168" name="schedule_hours" class="width50" value="'.((int) $feed->schedule_hours ?: '').'"></span>';
if (!$isnew && $feed->status && $feed->next_run) {
	print '<br><span class="opacitymedium">'.$langs->trans('FeedImportNextRun').': '.dol_print_date($feed->next_run, 'dayhour', 'tzuserrel').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven fi-m fi-m-email fi-m-upload"><td>'.$langs->trans('FeedImportSchedule').'</td><td><span class="opacitymedium fi-m fi-m-email">'.$langs->trans('FeedImportScheduleEmailHelp').'</span><span class="opacitymedium fi-m fi-m-upload">'.$langs->trans('FeedImportScheduleUpload').'</span></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportPacing'), $langs->trans('FeedImportPacingHelp')).'</td><td>';
print $langs->transnoentitiesnoconv('FeedImportPacingBlocks', '<input type="number" min="0" name="blocksize" class="width75" value="'.((int) $p['blocksize']).'">', '<input type="number" min="0" max="300" name="pause" class="width50" value="'.((int) $p['pause']).'">');
print '</td></tr>';

// 6. Other settings
feedimportSection('6. '.$langs->trans('FeedImportStepOther'));
print '<tr class="oddeven"><td class="'.($p['matchkey'] == 'supplierref' ? 'fieldrequired' : '').'">'.$form->textwithpicto($langs->trans('Supplier'), $langs->trans('FeedImportSupplierHelp')).'</td><td>';
print img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($feed->fk_soc, 'fk_soc', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('Warehouse'), $langs->trans('FeedImportWarehouseHelp')).'</td><td>';
if (isModEnabled('stock')) {
	print img_picto('', 'stock', 'class="pictofixedwidth"').$formproduct->selectWarehouses($feed->fk_entrepot, 'fk_entrepot', 'warehouseopen,warehouseinternal,warehouseclosed', 1, 0, 0, '', 0, 0, array(), 'minwidth300');
	if ($feed->fk_entrepot > 0) {
		$resql = $db->query("SELECT label FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE fk_entrepot = ".((int) $feed->fk_entrepot)." AND rowid <> ".((int) $feed->id)." AND entity = ".((int) $conf->entity));
		$others = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$others[] = $obj->label;
		}
		if (!empty($others)) {
			print '<div class="warning">'.dol_escape_htmltag($langs->transnoentitiesnoconv('FeedImportWarehouseShared', implode(', ', $others))).'</div>';
		}
	}
} else {
	print '<span class="opacitymedium">'.$langs->trans('FeedImportProblemStock').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportMatchKey'), $langs->trans('FeedImportMatchKeyHelp')).'</td><td>';
print $form->selectarray('matchkey', array('supplierref' => $langs->trans('FeedImportMatchSupplierRef'), 'ref' => $langs->trans('FeedImportMatchRef'), 'barcode' => $langs->trans('FeedImportMatchBarcode')), $p['matchkey'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportCreate'), $langs->trans('FeedImportCreateHelp')).'</td><td><input type="checkbox" name="create" value="1"'.(!empty($p['create']) ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportRefPrefix'), $langs->trans('FeedImportRefPrefixHelp')).'</td><td><input type="text" name="refprefix" class="width100" maxlength="20" value="'.dol_escape_htmltag((string) $p['refprefix']).'" placeholder="SUP-"></td></tr>';
if (isModEnabled('category')) {
	print '<tr class="oddeven"><td>'.$langs->trans('FeedImportCategory').'</td><td>';
	print img_picto('', 'category', 'class="pictofixedwidth"').$form->select_all_categories(Categorie::TYPE_PRODUCT, (int) $p['fk_category'], 'fk_category', 64, 0, 0, 0, 'minwidth300');
	print '</td></tr>';
}
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportVat'), $langs->trans('FeedImportVatHelp')).'</td><td>';
print $form->load_tva('vat', ($p['vat'] === '' ? -1 : $p['vat']), $mysoc, $mysoc, 0, 0, '', false, 0);
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportPriceBase').'</td><td>';
print $form->selectarray('pricebase', array('HT' => $langs->trans('HT'), 'TTC' => $langs->trans('TTC')), $p['pricebase'], 0, 0, 0, '', 0, 0, 0, '', 'width100');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportZero'), $langs->trans('FeedImportZeroHelp')).'</td><td><input type="checkbox" name="zero" value="1"'.(!empty($p['zero']) ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportMinRatio'), $langs->trans('FeedImportMinRatioHelp')).'</td><td><input type="number" min="0" max="100" name="minratio" class="width50" value="'.((int) $p['minratio']).'"> %</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportMaxNew'), $langs->trans('FeedImportMaxNewHelp')).'</td><td><input type="number" min="0" name="maxnew" class="width75" value="'.((int) $p['maxnew']).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('FeedImportKeep').'</td><td><input type="number" min="1" max="365" name="keep" class="width50" value="'.((int) $p['keep']).'"></td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('FeedImportNotify'), $langs->trans('FeedImportNotifyHelp')).'</td><td><input type="text" name="notify" class="minwidth300" value="'.dol_escape_htmltag((string) $p['notify']).'" placeholder="purchasing@example.com"></td></tr>';

print '</table>';
print '</div>';

print $form->buttonsSaveCancel($isnew ? 'Create' : 'Save', $isnew ? '' : 'Cancel');
print '</form>';

// Show only the settings of the method, file type and schedule chosen
print '<script>
$(function() {
	function feedimportToggle() {
		var m = $("#method").val(), t = $("#filetype").val(), s = $("#schedule_type").val();
		$(".fi-m").hide(); $(".fi-m-" + m).show();
		$(".fi-t").hide(); $(".fi-t-" + t).show();
		$(".fi-s").hide(); $(".fi-s-" + s).show();
	}
	$("#method, #filetype, #schedule_type").on("change", feedimportToggle);
	feedimportToggle();
});
</script>';

if (!$isnew) {
	// Actions
	print '<div class="tabsAction">';
	if (in_array($feed->method, FeedImportFeed::REMOTE_METHODS)) {
		print dolGetButtonAction('', $langs->trans('FeedImportTest'), 'default', $self.'&action=test&token='.newToken());
	}
	print dolGetButtonAction('', $langs->trans('FeedImportPreview'), 'default', $self.'&action=preview&token='.newToken());
	print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $self.'&action=delete&token='.newToken());
	print '</div>';

	// Run now / import a file
	print '<form method="POST" action="'.$self.'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('FeedImportRunTitle').'</td></tr>';
	print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans('Options').'</td><td>';
	print '<label><input type="checkbox" name="force" value="1"> '.$langs->trans('FeedImportForce').'</label><br>';
	print '<label><input type="checkbox" name="forcezero" value="1"> '.$langs->trans('FeedImportForceZero').'</label>';
	print '</td></tr>';
	if (in_array($feed->method, FeedImportFeed::REMOTE_METHODS) || $feed->method == 'email') {
		print '<tr class="oddeven"><td></td><td><button type="submit" name="action" value="run" class="button smallpaddingimp">'.img_picto('', 'fa-play', 'class="pictofixedwidth"').$langs->trans('FeedImportRunNow').'</button>';
		print ' <span class="opacitymedium">'.$langs->trans($feed->method == 'email' ? 'FeedImportRunNowEmailHelp' : 'FeedImportRunNowHelp').'</span></td></tr>';
	}
	print '<tr class="oddeven"><td>'.$langs->trans('FeedImportUploadFile').'</td><td><input type="file" name="feedfile" accept=".csv,.txt,.xml"> ';
	print '<button type="submit" name="action" value="upload" class="button smallpaddingimp">'.img_picto('', 'fa-upload', 'class="pictofixedwidth"').$langs->trans('FeedImportUploadAndImport').'</button></td></tr>';
	print '</table>';
	print '</div>';
	print '</form>';

	// Preview
	if ($previewerror !== '') {
		print '<div class="error">'.$langs->trans('FeedImportPreview').': '.dol_escape_htmltag($previewerror).'</div>';
	}
	if (is_array($preview)) {
		print load_fiche_titre($langs->trans('FeedImportPreviewTitle', count($preview)), '', '');
		$mapped = array_intersect_key($feed->mapping, array_flip($previewfields));
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>#</td>';
		foreach ($previewfields as $field) {
			print '<td>'.dol_escape_htmltag($field).(isset($mapped[$field]) ? '<br><span class="badge badge-info">'.dol_escape_htmltag($targets[$mapped[$field]]).'</span>' : '').'</td>';
		}
		print '</tr>';
		foreach ($preview as $row) {
			print '<tr class="oddeven"><td>'.((int) $row['line']).'</td>';
			foreach ($previewfields as $field) {
				$value = isset($row['data'][$field]) ? (string) $row['data'][$field] : '';
				$css = isset($mapped[$field]) ? '' : ' opacitymedium';
				print '<td class="tdoverflowmax200'.$css.'" title="'.dol_escape_htmltag($value).'">'.dol_escape_htmltag(dol_trunc($value, 60)).'</td>';
			}
			print '</tr>';
		}
		print '</table>';
		print '</div>';
	}

	// Last runs
	print load_fiche_titre($langs->trans('FeedImportLastRuns'), '<a href="'.dol_buildpath('/feedimport/runs.php', 1).'?search_feed='.$feed->id.'">'.$langs->trans('FullList').'</a>', '');
	print feedimportRunsTable($db, $feed->id, 10);
}

print dol_get_fiche_end();

llxFooter();
$db->close();
