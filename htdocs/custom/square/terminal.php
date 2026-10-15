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
 * \file    square/terminal.php
 * \ingroup square
 * \brief   Take the payment of a customer invoice on a Square Terminal
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
dol_include_once('/square/class/squaretransaction.class.php');

$langs->loadLangs(array('bills', 'companies', 'square@square'));

$facid = GETPOSTINT('facid');
$txid = GETPOSTINT('txid');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('square') || !$user->hasRight('square', 'terminal') || !$user->hasRight('facture', 'paiement')) {
	accessforbidden();
}
$result = restrictedArea($user, 'facture', $facid, '', '', 'fk_soc', 'rowid');

$invoice = new Facture($db);
if ($invoice->fetch($facid) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$invoice->fetch_thirdparty();

$api = new SquareApi();
$envkey = $api->live ? 'LIVE' : 'SANDBOX';
$remain = (float) price2num($invoice->getRemainToPay(), 'MT');
$problem = squareSetupProblem();

$row = new SquareTransaction($db);
if ($txid > 0 && ($row->fetch($txid) <= 0 || $row->type != SquareTransaction::TYPE_TERMINAL || $row->fk_facture != $invoice->id)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Only one checkout at a time on an invoice: show the one still waiting, if any
if ($txid <= 0 && $action != 'send') {
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."square_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND type = '".$db->escape(SquareTransaction::TYPE_TERMINAL)."'";
	$sql .= " AND fk_facture = ".((int) $invoice->id)." AND status = '".$db->escape(SquareTransaction::STATUS_PENDING)."'";
	$sql .= " ORDER BY rowid DESC";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		header('Location: '.$_SERVER['PHP_SELF'].'?facid='.((int) $invoice->id).'&txid='.((int) $obj->rowid));
		exit;
	}
}


/*
 * Actions
 */

if ($action == 'send') {
	$amount = (float) price2num(GETPOST('amount', 'alpha'), 'MT');
	$deviceid = GETPOST('deviceid', 'alphanohtml');

	$error = 0;
	if ($problem !== '') {
		setEventMessages($problem, null, 'errors');
		$error++;
	}
	if ($invoice->status != Facture::STATUS_VALIDATED || $invoice->paye || $invoice->type == Facture::TYPE_CREDIT_NOTE) {
		setEventMessages($langs->trans('SquareErrorInvoiceNotPayable'), null, 'errors');
		$error++;
	}
	if ($amount <= 0 || $amount > $remain) {
		setEventMessages($langs->trans('SquareErrorAmount', price($remain, 0, $langs, 1, -1, -1, $conf->currency)), null, 'errors');
		$error++;
	}
	if ($deviceid === '') {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('SquareTerminal')), null, 'errors');
		$error++;
	}

	if (!$error) {
		$checkout = $api->createTerminalCheckout($deviceid, squareToMinor($amount, $conf->currency), $conf->currency, $invoice->ref, $invoice->ref.' - '.$invoice->thirdparty->name);
		if ($checkout === null) {
			setEventMessages($langs->trans('SquareErrorTerminalNotSent'), array($api->error), 'errors');
		} else {
			$row->type = SquareTransaction::TYPE_TERMINAL;
			$row->livemode = $api->live;
			$row->square_id = (string) $checkout['id'];
			$row->device_id = $deviceid;
			$row->source = 'invoice';
			$row->source_ref = $invoice->ref;
			$row->fk_facture = $invoice->id;
			$row->amount = $amount;
			$row->currency = $conf->currency;
			if ($row->create($user) < 0) {
				// Without the row the payment could not be recorded: take the request off the Terminal
				$api->cancelTerminalCheckout((string) $checkout['id']);
				setEventMessages($row->error, null, 'errors');
			} else {
				// Next time, the same Terminal is offered first
				dolibarr_set_const($db, 'SQUARE_'.$envkey.'_DEVICE_ID', $deviceid, 'chaine', 0, '', $conf->entity);
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.((int) $invoice->id).'&txid='.((int) $row->id));
				exit;
			}
		}
	}
}


/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('SquareTakeTerminalPayment').' - '.$invoice->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-square page-terminal');

$head = facture_prepare_head($invoice);
print dol_get_fiche_head($head, 'compta', $langs->trans('InvoiceCustomer'), -1, 'bill');

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $invoice->id).'">'.$langs->trans('SquareBackToInvoice').'</a>';
dol_banner_tab($invoice, 'ref', $linkback, 0, 'ref', 'ref', '', '&facid='.((int) $invoice->id), 0, '', '', 1);
print dol_get_fiche_end();

print load_fiche_titre($langs->trans('SquareTakeTerminalPayment'), '', 'fa-credit-card');

if (!$api->live) {
	print info_admin($langs->trans('SquareSandboxWarning'), 0, 0, 'warning');
}

if ($row->id > 0) {
	// A checkout was sent: follow it
	print '<div class="div-table-responsive-no-min">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('AmountTTC').'</td><td><span class="amount">'.price($row->amount, 0, $langs, 1, -1, -1, $row->currency).'</span></td></tr>';
	print '<tr><td>'.$langs->trans('SquareTerminal').'</td><td>'.dolPrintLabel($row->device_id).'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td id="squarestatus">'.SquareTransaction::statusBadge($row->status).'</td></tr>';
	print '</table>';
	print '</div>';

	print '<div id="squaremessage" class="info margintoponly">'.$langs->trans('SquareTerminalWaiting').'</div>';

	print '<div class="tabsAction">';
	print '<a class="butActionDelete" id="squarecancel" href="#">'.$langs->trans('SquareCancelOnTerminal').'</a>';
	print '<a class="butAction hideobject" id="squarepayment" href="#">'.$langs->trans('SquareShowPayment').'</a>';
	print '<a class="butAction hideobject" id="squareagain" href="'.$_SERVER['PHP_SELF'].'?facid='.((int) $invoice->id).'&txid=0&action=new">'.$langs->trans('SquareNewTerminalPayment').'</a>';
	print '<a class="butAction" href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $invoice->id).'">'.$langs->trans('SquareBackToInvoice').'</a>';
	print '</div>';

	$ajaxurl = dol_buildpath('/square/ajax/terminal.php', 1);
	print '<script>
	$(document).ready(function() {
		var squareTimer = null;
		function squarePoll(action) {
			$.post('.json_encode($ajaxurl).', {action: action, id: '.((int) $row->id).', token: '.json_encode(currentToken()).'}, function(data) {
				if (!data || !data.status) {
					return;
				}
				$("#squarestatus").html(data.label);
				$("#squaremessage").text(data.message);
				if (data.done) {
					clearInterval(squareTimer);
					$("#squarecancel").hide();
					$("#squaremessage").toggleClass("info", data.status != "attention").toggleClass("warning", data.status == "attention");
					if (data.paymenturl) {
						$("#squarepayment").attr("href", data.paymenturl).removeClass("hideobject");
					} else {
						$("#squareagain").removeClass("hideobject");
					}
				}
			}, "json");
		}
		$("#squarecancel").click(function(e) {
			e.preventDefault();
			squarePoll("cancel");
		});
		squarePoll("status");
		squareTimer = setInterval(function() { squarePoll("status"); }, 3000);
	});
	</script>';
} else {
	// Choose the amount and the Terminal
	$devices = array();
	$deviceerror = '';
	if ($problem === '') {
		$codes = $api->listDeviceCodes('PAIRED');
		if ($codes === null) {
			$deviceerror = $api->error;
		} else {
			foreach ($codes as $code) {
				if (!empty($code['device_id'])) {
					$devices[$code['device_id']] = (empty($code['name']) ? $code['device_id'] : $code['name']);
				}
			}
		}
	}
	// A device typed on the Terminals setup tab (e.g. a sandbox test device) is offered even if not paired
	$defaultdevice = getDolGlobalString('SQUARE_'.$envkey.'_DEVICE_ID');
	if ($defaultdevice !== '' && empty($devices[$defaultdevice])) {
		$devices[$defaultdevice] = $defaultdevice;
	}

	if ($problem !== '') {
		print '<div class="error">'.dolPrintLabel($problem).'</div>';
	} elseif ($invoice->status != Facture::STATUS_VALIDATED || $invoice->paye || $remain <= 0) {
		print '<div class="warning">'.$langs->trans('SquareErrorInvoiceNotPayable').'</div>';
	} else {
		if ($deviceerror !== '') {
			print '<div class="warning">'.$langs->trans('SquareErrorDevices').' '.dolPrintLabel($deviceerror).'</div>';
		}
		if (empty($devices)) {
			$setupurl = dol_buildpath('/square/admin/terminals.php', 1);
			print '<div class="warning">'.$langs->trans('SquareNoTerminal').($user->admin ? ' <a href="'.$setupurl.'">'.$langs->trans('SquarePairTerminal').'</a>' : '').'</div>';
		} else {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?facid='.((int) $invoice->id).'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="send">';
			print '<table class="border centpercent tableforfield">';
			print '<tr><td class="titlefield">'.$langs->trans('RemainderToPay').'</td><td><span class="amount">'.price($remain, 0, $langs, 1, -1, -1, $conf->currency).'</span></td></tr>';
			$amount = GETPOSTISSET('amount') ? GETPOST('amount', 'alpha') : price($remain);
			print '<tr><td class="fieldrequired">'.$langs->trans('AmountTTC').'</td><td><input type="text" name="amount" class="maxwidth100" value="'.dolPrintHTMLForAttribute($amount).'"> '.$langs->trans('Currency'.$conf->currency).'</td></tr>';
			print '<tr><td class="fieldrequired">'.$langs->trans('SquareTerminal').'</td><td>';
			print $form->selectarray('deviceid', $devices, GETPOSTISSET('deviceid') ? GETPOST('deviceid', 'alphanohtml') : $defaultdevice, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
			print '</td></tr>';
			print '</table>';
			print '<div class="center margintoponly">';
			print '<input type="submit" class="button" value="'.dolPrintHTMLForAttribute($langs->trans('SquareSendToTerminal')).'">';
			print ' <a class="button button-cancel" href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $invoice->id).'">'.$langs->trans('Cancel').'</a>';
			print '</div>';
			print '</form>';
		}
	}
}

llxFooter();
$db->close();
