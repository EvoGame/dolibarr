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
 * \file    square/admin/terminals.php
 * \ingroup square
 * \brief   Pair Square Terminals with Dolibarr (device codes of the Devices API), and choose the default one
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
dol_include_once('/square/lib/square.lib.php');
dol_include_once('/square/class/squareapi.class.php');

$langs->loadLangs(array('admin', 'square@square'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$api = new SquareApi();
$envkey = $api->live ? 'LIVE' : 'SANDBOX';
$newcode = null;


/*
 * Actions
 */

if ($action == 'pair') {
	$name = trim(GETPOST('name', 'alphanohtml'));
	if ($name === '') {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Name')), null, 'errors');
	} else {
		$newcode = $api->createDeviceCode($name);
		if ($newcode === null) {
			setEventMessages($langs->trans('SquareErrorPairing'), array($api->error), 'errors');
		}
	}
}

// Square Terminal of each TakePOS till
if ($action == 'settills' && isModEnabled('takepos')) {
	$db->begin();
	$ok = true;
	$nbtills = max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1));
	for ($till = 1; $till <= $nbtills; $till++) {
		$ok = $ok && dolibarr_set_const($db, 'SQUARE_'.$envkey.'_DEVICE_ID_TAKEPOS'.$till, trim(GETPOST('till'.$till, 'alphanohtml')), 'chaine', 0, '', $conf->entity) > 0;
	}
	if ($ok) {
		$db->commit();
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	$db->rollback();
	setEventMessages($langs->trans('Error'), null, 'errors');
}

if ($action == 'setdefault') {
	if (dolibarr_set_const($db, 'SQUARE_'.$envkey.'_DEVICE_ID', trim(GETPOST('deviceid', 'alphanohtml')), 'chaine', 0, '', $conf->entity) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($langs->trans('Error'), null, 'errors');
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('SquareSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-square page-admin-terminals');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(squareAdminPrepareHead(), 'terminals', $title, -1, 'fa-credit-card');

print '<span class="opacitymedium">'.$langs->trans('SquareTerminalsHelp', $langs->transnoentitiesnoconv($api->live ? 'SquareEnvLive' : 'SquareEnvSandbox')).'</span><br><br>';

if (!$api->isConfigured()) {
	print '<div class="warning">'.$langs->trans('SquareErrorNotConfigured', $langs->transnoentitiesnoconv($api->live ? 'SquareEnvLive' : 'SquareEnvSandbox')).'</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

if ($newcode !== null) {
	print '<div class="ok">'.$langs->trans('SquarePairingCode', dol_print_date(dol_stringtotime($newcode['pair_by']), 'dayhour', 'tzuserrel'));
	print ' <strong class="size15x">'.dolPrintLabel($newcode['code']).'</strong></div><br>';
}

// Pair a new Terminal
print load_fiche_titre($langs->trans('SquarePairTerminal'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="pair">';
print $langs->trans('Name').' <input type="text" name="name" class="minwidth200" maxlength="128" value="'.dolPrintHTMLForAttribute($langs->trans('SquareDefaultTerminalName')).'"> ';
print '<input type="submit" class="button small" value="'.dolPrintHTMLForAttribute($langs->trans('SquareGetPairingCode')).'">';
print '</form><br>';

// Device codes of the location
$default = getDolGlobalString('SQUARE_'.$envkey.'_DEVICE_ID');
$codes = $api->listDeviceCodes();
print load_fiche_titre($langs->trans('SquareTerminals'), '', '');
if ($codes === null) {
	print '<div class="warning">'.$langs->trans('SquareErrorDevices').' '.dolPrintLabel($api->error).'</div>';
} else {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('SquarePairingCodeShort').'</td><td>'.$langs->trans('Status').'</td>';
	print '<td>'.$langs->trans('SquareDeviceId').'</td><td class="center">'.$langs->trans('Default').'</td></tr>';
	$shown = 0;
	foreach ($codes as $code) {
		if (!empty($code['status']) && $code['status'] == 'EXPIRED') {
			continue;
		}
		$shown++;
		$deviceid = empty($code['device_id']) ? '' : $code['device_id'];
		print '<tr class="oddeven">';
		print '<td>'.dolPrintLabel(empty($code['name']) ? '' : $code['name']).'</td>';
		print '<td>'.dolPrintLabel(empty($code['code']) ? '' : $code['code']).'</td>';
		print '<td>'.dolPrintLabel($langs->trans('SquareDeviceStatus'.(empty($code['status']) ? '' : ucfirst(strtolower($code['status']))))).'</td>';
		print '<td class="tdoverflowmax300">'.dolPrintLabel($deviceid).'</td>';
		print '<td class="center">';
		if ($deviceid !== '') {
			if ($deviceid == $default) {
				print img_picto($langs->trans('Default'), 'on');
			} else {
				print '<a href="'.$_SERVER['PHP_SELF'].'?action=setdefault&token='.newToken().'&deviceid='.urlencode($deviceid).'">'.img_picto($langs->trans('SquareSetDefault'), 'off').'</a>';
			}
		}
		print '</td></tr>';
	}
	if (!$shown) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('SquareNoTerminal').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';
}

// Any device id, e.g. one of Square's sandbox test devices, which are never paired
print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setdefault">';
print $form->textwithpicto($langs->trans('SquareDefaultDevice'), $langs->trans('SquareDefaultDeviceHelp')).' ';
print '<input type="text" name="deviceid" class="minwidth300" value="'.dolPrintHTMLForAttribute($default).'"> ';
print '<input type="submit" class="button small" value="'.dolPrintHTMLForAttribute($langs->trans('Save')).'">';
print '</form>';

if (isModEnabled('takepos')) {
	$langs->load('cashdesk');
	$options = array();
	foreach ((empty($codes) ? array() : $codes) as $code) {
		if (!empty($code['device_id']) && !empty($code['status']) && $code['status'] == 'PAIRED') {
			$options[$code['device_id']] = (empty($code['name']) ? $code['device_id'] : $code['name']);
		}
	}
	if ($default !== '' && empty($options[$default])) {
		$options[$default] = $default;
	}

	print '<br>';
	print load_fiche_titre($langs->trans('SquareTakeposTills'), '', '');
	print '<span class="opacitymedium">'.$langs->trans('SquareTakeposTillsHelp').'</span><br><br>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="settills">';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans('Terminal').'</td><td>'.$langs->trans('SquareTerminal').'</td><td>'.$langs->trans('Status').'</td></tr>';
	$nbtills = max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1));
	for ($till = 1; $till <= $nbtills; $till++) {
		$selected = getDolGlobalString('SQUARE_'.$envkey.'_DEVICE_ID_TAKEPOS'.$till);
		$tilloptions = $options;
		if ($selected !== '' && empty($tilloptions[$selected])) {
			$tilloptions[$selected] = $selected;
		}
		// What TakePOS needs to record the payment: a bank account for the payment mode on this till
		$tillproblem = squareTakeposProblem((string) $till);

		print '<tr class="oddeven"><td>'.dolPrintLabel(getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$till, $langs->trans('TerminalName', $till))).'</td><td>';
		print $form->selectarray('till'.$till, $tilloptions, $selected, $langs->trans('SquareUseDefaultDevice'), 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print '</td><td>'.($tillproblem === '' ? img_picto('', 'tick').' '.$langs->trans('SquareReadyShort') : '<span class="warning">'.dolPrintLabel($tillproblem).'</span>').'</td></tr>';
	}
	print '</table>';
	print '</div>';
	print '<div class="center"><input type="submit" class="button small" value="'.dolPrintHTMLForAttribute($langs->trans('Save')).'"></div>';
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
