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
 * \file    square/sendlink.php
 * \ingroup square
 * \brief   Send a Square payment link for a customer invoice by email or SMS
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
dol_include_once('/square/class/squaretransaction.class.php');

$langs->loadLangs(array('bills', 'companies', 'square@square'));

$facid = GETPOSTINT('facid');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('square') || !$user->hasRight('square', 'sendlink')) {
	accessforbidden();
}
$result = restrictedArea($user, 'facture', $facid, '', '', 'fk_soc', 'rowid');

$invoice = new Facture($db);
if ($invoice->fetch($facid) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$invoice->fetch_thirdparty();
$backurl = DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $invoice->id);


/*
 * Actions
 */

if ($action == 'send') {
	$channel = (GETPOST('channel', 'aZ09') == 'sms' ? 'sms' : 'email');
	$to = GETPOST($channel == 'sms' ? 'phone' : 'email', 'alphanohtml');
	$sender = new SquareTransaction($db);
	$row = $sender->sendInvoiceLink($invoice, $channel, $to, $user);
	if ($row === null) {
		setEventMessages($sender->error, null, 'errors');
	} else {
		setEventMessages($langs->trans($channel == 'sms' ? 'SquareLinkSentBySms' : 'SquareLinkSentByEmail', $to), null, 'mesgs');
		header('Location: '.$backurl);
		exit;
	}
}


/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('SquareSendLink').' - '.$invoice->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-square page-sendlink');

$head = facture_prepare_head($invoice);
print dol_get_fiche_head($head, 'compta', $langs->trans('InvoiceCustomer'), -1, 'bill');
$linkback = '<a href="'.$backurl.'">'.$langs->trans('SquareBackToInvoice').'</a>';
dol_banner_tab($invoice, 'ref', $linkback, 0, 'ref', 'ref', '', '&facid='.((int) $invoice->id), 0, '', '', 1);
print dol_get_fiche_end();

print load_fiche_titre($langs->trans('SquareSendLink'), '', 'fa-paper-plane');

$remain = (float) price2num($invoice->getRemainToPay(), 'MT');
$problem = squareSetupProblem();
if ($problem !== '') {
	print '<div class="error">'.dolPrintLabel($problem).'</div>';
} elseif ($invoice->status != Facture::STATUS_VALIDATED || $invoice->paye || $remain <= 0 || $invoice->type == Facture::TYPE_CREDIT_NOTE) {
	print '<div class="warning">'.$langs->trans('SquareErrorInvoiceNotPayable').'</div>';
} else {
	if (!getDolGlobalInt('SQUARE_LIVE')) {
		print info_admin($langs->trans('SquareSandboxWarning'), 0, 0, 'warning');
	}
	print '<span class="opacitymedium">'.$langs->trans('SquareSendLinkHelp', price($remain, 0, $langs, 1, -1, -1, $conf->currency)).'</span><br><br>';

	$email = GETPOSTISSET('email') ? GETPOST('email', 'alphanohtml') : (string) $invoice->thirdparty->email;
	$phone = GETPOSTISSET('phone') ? GETPOST('phone', 'alphanohtml') : (string) (empty($invoice->thirdparty->phone_mobile) ? $invoice->thirdparty->phone : $invoice->thirdparty->phone_mobile);

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?facid='.((int) $invoice->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="send">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('Email').'</td><td>';
	print img_picto('', 'email', 'class="pictofixedwidth"').'<input type="email" name="email" class="minwidth300" value="'.dolPrintHTMLForAttribute($email).'"> ';
	print '<button type="submit" class="button small" name="channel" value="email">'.$langs->trans('SquareSendByEmail').'</button>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('SquarePhoneForSms').'</td><td>';
	print img_picto('', 'phone', 'class="pictofixedwidth"').'<input type="tel" name="phone" class="minwidth300" value="'.dolPrintHTMLForAttribute($phone).'"> ';
	if (squareCanSendSms()) {
		print '<button type="submit" class="button small" name="channel" value="sms">'.$langs->trans('SquareSendBySms').'</button>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('SquareErrorNoSms').'</span>';
	}
	print '</td></tr>';
	print '</table>';
	print '<div class="center margintoponly"><a class="button button-cancel" href="'.$backurl.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
}

llxFooter();
$db->close();
