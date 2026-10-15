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
 * \file    square/admin/setup.php
 * \ingroup square
 * \brief   Settings of module Square: environment, credentials, location, bank account and payment mode
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
dol_include_once('/square/lib/square.lib.php');
dol_include_once('/square/class/squareapi.class.php');

$langs->loadLangs(array('admin', 'banks', 'bills', 'square@square'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$envs = array('SANDBOX' => 0, 'LIVE' => 1);


/*
 * Actions
 */

if ($action == 'update') {
	$db->begin();
	$ok = dolibarr_set_const($db, 'SQUARE_LIVE', (string) (GETPOSTINT('live') ? 1 : 0), 'chaine', 0, '', $conf->entity) > 0;
	foreach ($envs as $env => $live) {
		$ok = $ok && dolibarr_set_const($db, 'SQUARE_'.$env.'_ACCESS_TOKEN', trim(GETPOST(strtolower($env).'_token', 'password')), 'chaine', 0, '', $conf->entity) > 0;
		$ok = $ok && dolibarr_set_const($db, 'SQUARE_'.$env.'_LOCATION_ID', trim(GETPOST(strtolower($env).'_location', 'alphanohtml')), 'chaine', 0, '', $conf->entity) > 0;
	}
	if (isModEnabled('bank')) {
		$ok = $ok && dolibarr_set_const($db, 'SQUARE_BANK_ACCOUNT_FOR_PAYMENTS', (string) GETPOSTINT('bankaccount'), 'chaine', 0, '', $conf->entity) > 0;
	}
	$ok = $ok && dolibarr_set_const($db, 'SQUARE_PAYMENT_MODE_FOR_PAYMENTS', (string) GETPOSTINT('paymentmode'), 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'SQUARE_LINK_EXPIRY_DAYS', (string) max(0, GETPOSTINT('expirydays')), 'chaine', 0, '', $conf->entity) > 0;

	if ($ok) {
		$db->commit();
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	$db->rollback();
	setEventMessages($langs->trans('Error'), null, 'errors');
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('SquareSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-square page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(squareAdminPrepareHead(), 'settings', $title, -1, 'fa-credit-card');

print '<span class="opacitymedium">'.$langs->trans('SquareSetupHelp').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('SquareEnvironment').'</td><td>';
print $form->selectarray('live', array(0 => $langs->trans('SquareEnvSandbox'), 1 => $langs->trans('SquareEnvLive')), getDolGlobalInt('SQUARE_LIVE'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';

foreach ($envs as $env => $live) {
	$api = new SquareApi($live);
	$label = $langs->trans($live ? 'SquareEnvLive' : 'SquareEnvSandbox');

	print '<tr class="liste_titre"><td colspan="2">'.$label.'</td></tr>';

	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('SquareAccessToken'), $langs->trans('SquareAccessTokenHelp')).'</td><td>';
	print '<input type="password" autocomplete="off" name="'.strtolower($env).'_token" class="minwidth500" value="'.dolPrintHTMLForAttribute($api->accesstoken).'">';
	print '</td></tr>';

	// Locations of the account, once the token works
	print '<tr class="oddeven"><td>'.$langs->trans('SquareLocation').'</td><td>';
	$locations = ($api->accesstoken !== '' ? $api->listLocations() : null);
	if ($locations) {
		$options = array();
		$selected = null;
		foreach ($locations as $location) {
			$options[$location['id']] = $location['name'].(empty($location['currency']) ? '' : ' ('.$location['currency'].')');
			if ($location['id'] == $api->locationid) {
				$selected = $location;
			}
		}
		print $form->selectarray(strtolower($env).'_location', $options, $api->locationid, 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
		if ($selected && !empty($selected['currency']) && $selected['currency'] != $conf->currency) {
			print '<div class="warning">'.$langs->trans('SquareErrorLocationCurrency', $selected['currency'], $conf->currency).'</div>';
		}
	} else {
		print '<input type="text" name="'.strtolower($env).'_location" class="minwidth300" value="'.dolPrintHTMLForAttribute($api->locationid).'">';
		if ($api->accesstoken !== '') {
			print '<div class="warning">'.$langs->trans('SquareErrorLocations').' '.dolPrintLabel($api->error).'</div>';
		} else {
			print ' <span class="opacitymedium">'.$langs->trans('SquareLocationAfterToken').'</span>';
		}
	}
	print '</td></tr>';
}

print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('SquareRecording').'</td></tr>';

if (isModEnabled('bank')) {
	print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BankAccount').'</td><td>';
	print img_picto('', 'bank_account', 'class="pictofixedwidth"');
	$form->select_comptes(getDolGlobalInt('SQUARE_BANK_ACCOUNT_FOR_PAYMENTS'), 'bankaccount', 0, '', 1);
	print '</td></tr>';
}

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('PaymentMode'), $langs->trans('SquarePaymentModeHelp')).'</td><td>';
$form->select_types_paiements(getDolGlobalInt('SQUARE_PAYMENT_MODE_FOR_PAYMENTS'), 'paymentmode', '', 0, 1, 1);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('SquareLinkExpiry'), $langs->trans('SquareLinkExpiryHelp')).'</td><td>';
print '<input type="number" min="0" name="expirydays" class="width50" value="'.getDolGlobalInt('SQUARE_LINK_EXPIRY_DAYS', 7).'"> '.$langs->trans('Days');
print '</td></tr>';

print '</table>';
print '</div>';

print $form->buttonsSaveCancel('Save', '');
print '</form>';

// Status, and where the module shows up
$problem = squareSetupProblem();
print load_fiche_titre($langs->trans('Status'), '', '');
if ($problem !== '') {
	print '<div class="warning">'.dolPrintLabel($problem).'</div>';
} else {
	print '<div class="ok">'.$langs->trans('SquareReady', $langs->transnoentitiesnoconv(getDolGlobalInt('SQUARE_LIVE') ? 'SquareEnvLive' : 'SquareEnvSandbox')).'</div>';
	print '<br>'.$langs->trans('SquareExampleLink').'<br>';
	print showOnlinePaymentUrl('free', 'test', 10);
}
if (!isModEnabled('cron')) {
	print '<div class="warning margintoponly">'.$langs->trans('SquareCronDisabled').'</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
