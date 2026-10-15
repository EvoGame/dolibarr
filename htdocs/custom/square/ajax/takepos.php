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
 * \file    square/ajax/takepos.php
 * \ingroup square
 * \brief   Square buttons of the TakePOS payment screen (code in ActionsSquare::completePayment()).
 *
 * POST, with the CSRF token:
 * - action=send&invoiceid=&amount=: sends the amount to the till's Square Terminal
 * - action=status&id=: checkout status. 'claimed' is true once, when the payment is complete: the page then has
 *   TakePOS validate the sale and record the payment (invoice.php action=valid), and calls status again, which
 *   links that payment to the Square payment.
 * - action=cancel&id=: cancels the checkout on the Terminal
 * - action=sendlink&invoiceid=&channel=email|sms&to=: sends a payment link for what remains to pay
 * Returns JSON.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/square/class/squaretransaction.class.php');

$langs->loadLangs(array('main', 'bills', 'square@square'));

top_httphead('application/json');

/**
 * Print a JSON answer and stop
 *
 * @param	array<string,mixed>	$data	Answer
 * @param	int					$code	HTTP status
 * @return	never
 */
function squareAnswer($data, $code = 200)
{
	global $db;

	http_response_code($code);
	print json_encode($data);
	$db->close();
	exit;
}

$action = GETPOST('action', 'aZ09');
$terminal = empty($_SESSION['takeposterminal']) ? '' : (string) $_SESSION['takeposterminal'];
$right = ($action == 'sendlink' ? 'sendlink' : 'terminal');

if (!isModEnabled('square') || !isModEnabled('takepos') || !$user->hasRight('takepos', 'run') || !$user->hasRight('square', $right)
	|| $_SERVER['REQUEST_METHOD'] != 'POST' || GETPOST('token', 'alpha') !== currentToken()) {
	squareAnswer(array('error' => 'Access denied'), 403);
}

$problem = squareSetupProblem();
if ($problem !== '') {
	squareAnswer(array('error' => $problem));
}

$messages = array(
	SquareTransaction::STATUS_PENDING => $langs->transnoentitiesnoconv('SquareTerminalWaiting'),
	SquareTransaction::STATUS_PROCESSING => $langs->transnoentitiesnoconv('SquareTerminalRecording'),
	SquareTransaction::STATUS_RECORDED => $langs->transnoentitiesnoconv('SquareTerminalRecorded'),
	SquareTransaction::STATUS_CANCELLED => $langs->transnoentitiesnoconv('SquareTerminalCancelled'),
);

/**
 * JSON state of a row
 *
 * @param	SquareTransaction	$row		Row
 * @param	bool				$claimed	Whether this request claimed it
 * @param	string				$message	Message to show first
 * @return	array<string,mixed>
 */
function squareRowAnswer($row, $claimed = false, $message = '')
{
	global $langs, $messages;

	$text = ($row->status == SquareTransaction::STATUS_ATTENTION ? $langs->transnoentitiesnoconv('SquareTakeposAttention', $row->note) : (empty($messages[$row->status]) ? '' : $messages[$row->status]));
	return array(
		'id' => $row->id,
		'status' => $row->status,
		'amount' => (float) price2num($row->amount, 'MT'),
		'message' => trim($message.' '.$text),
		'claimed' => $claimed,
		'done' => in_array($row->status, array(SquareTransaction::STATUS_RECORDED, SquareTransaction::STATUS_ATTENTION, SquareTransaction::STATUS_CANCELLED)),
	);
}

if ($action == 'send' || $action == 'sendlink') {
	$invoice = new Facture($db);
	if ($invoice->fetch(GETPOSTINT('invoiceid')) <= 0) {
		squareAnswer(array('error' => $langs->transnoentitiesnoconv('ErrorRecordNotFound')));
	}
	// Same rule as the TakePOS screens: the sale is seen through the TakePOS right, so only POS sales qualify
	if ($invoice->module_source != 'takepos' || !in_array((string) $invoice->entity, explode(',', getEntity('invoice')), true)) {
		squareAnswer(array('error' => 'Access denied'), 403);
	}

	if ($action == 'sendlink') {
		$sender = new SquareTransaction($db);
		$channel = (GETPOST('channel', 'aZ09') == 'sms' ? 'sms' : 'email');
		$to = GETPOST('to', 'alphanohtml');
		$row = $sender->sendInvoiceLink($invoice, $channel, $to, $user);
		if ($row === null) {
			squareAnswer(array('error' => $sender->error));
		}
		squareAnswer(array('id' => $row->id, 'message' => $langs->transnoentitiesnoconv($channel == 'sms' ? 'SquareLinkSentBySms' : 'SquareLinkSentByEmail', $to)));
	}

	// Terminal: a draft sale (TakePOS validates it once paid) or a validated one with something left to pay
	if ($invoice->type == Facture::TYPE_CREDIT_NOTE || $invoice->paye || !in_array($invoice->status, array(Facture::STATUS_DRAFT, Facture::STATUS_VALIDATED))
		|| (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code != $conf->currency)) {
		squareAnswer(array('error' => $langs->transnoentitiesnoconv('SquareErrorInvoiceNotPayable')));
	}
	$problem = squareTakeposProblem();
	if ($problem !== '') {
		squareAnswer(array('error' => $problem));
	}
	$remain = (float) price2num($invoice->getRemainToPay(), 'MT');
	$amount = (float) price2num(GETPOST('amount', 'alpha'), 'MT');
	if ($amount <= 0 || $amount > $remain) {
		squareAnswer(array('error' => $langs->transnoentitiesnoconv('SquareErrorAmount', price($remain, 0, $langs, 1, -1, -1, $conf->currency))));
	}

	// A checkout still waiting for this sale is followed again rather than doubled on the Terminal
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."square_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND type = '".$db->escape(SquareTransaction::TYPE_TERMINAL)."'";
	$sql .= " AND fk_facture = ".((int) $invoice->id)." AND status = '".$db->escape(SquareTransaction::STATUS_PENDING)."'";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$row = new SquareTransaction($db);
		if ($row->fetch((int) $obj->rowid) > 0) {
			squareAnswer(squareRowAnswer($row));
		}
	}

	$api = new SquareApi();
	$deviceid = squareDeviceId($terminal);
	$tillname = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal, $langs->transnoentitiesnoconv('TerminalName', $terminal));
	$checkout = $api->createTerminalCheckout($deviceid, squareToMinor($amount, $conf->currency), $conf->currency, $invoice->ref, $tillname.' - '.$invoice->ref);
	if ($checkout === null) {
		squareAnswer(array('error' => $langs->transnoentitiesnoconv('SquareErrorTerminalNotSent').' '.$api->error));
	}
	$row = new SquareTransaction($db);
	$row->type = SquareTransaction::TYPE_TERMINAL;
	$row->livemode = $api->live;
	$row->square_id = (string) $checkout['id'];
	$row->device_id = $deviceid;
	$row->source = SquareTransaction::SOURCE_TAKEPOS;
	$row->source_ref = $invoice->ref;
	$row->fk_facture = $invoice->id;
	$row->amount = $amount;
	$row->currency = $conf->currency;
	if ($row->create($user) < 0) {
		$api->cancelTerminalCheckout((string) $checkout['id']);
		squareAnswer(array('error' => $row->error));
	}
	squareAnswer(squareRowAnswer($row));
}

if ($action == 'status' || $action == 'cancel') {
	$row = new SquareTransaction($db);
	if ($row->fetch(GETPOSTINT('id')) <= 0 || $row->type != SquareTransaction::TYPE_TERMINAL || $row->source != SquareTransaction::SOURCE_TAKEPOS) {
		squareAnswer(array('error' => 'Access denied'), 403);
	}

	$message = '';
	if ($action == 'cancel' && $row->status == SquareTransaction::STATUS_PENDING) {
		$api = new SquareApi($row->livemode);
		if ($api->cancelTerminalCheckout($row->square_id) === null) {
			$message = $langs->transnoentitiesnoconv('SquareCancelRefused');
		}
	}

	$before = $row->status;
	if ($row->refresh($user) < 0 && $row->status == SquareTransaction::STATUS_PENDING) {
		$message = $langs->transnoentitiesnoconv('SquareErrorCannotCheck');
		dol_syslog('Square TakePOS status of row '.$row->id.': '.$row->error, LOG_WARNING);
	}
	// Only the request that moved it from pending has TakePOS record the payment, so a reload never records it twice
	$claimed = ($before == SquareTransaction::STATUS_PENDING && $row->status == SquareTransaction::STATUS_PROCESSING);
	squareAnswer(squareRowAnswer($row, $claimed, $message));
}

squareAnswer(array('error' => 'Bad action'), 400);
