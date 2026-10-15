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
 * \file    square/ajax/terminal.php
 * \ingroup square
 * \brief   Status and cancellation of a Square Terminal checkout, polled by terminal.php.
 *
 * POST, with the CSRF token:
 * - action=status&id=<row>: checks the checkout on Square, records the payment once it is completed
 * - action=cancel&id=<row>: asks Square to cancel the checkout (refused once the card has been read)
 * Returns JSON: {status, label, message, done, paymenturl}
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
dol_include_once('/square/class/squaretransaction.class.php');

$langs->loadLangs(array('bills', 'square@square'));

top_httphead('application/json');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

$row = new SquareTransaction($db);
if (!isModEnabled('square') || !$user->hasRight('square', 'terminal') || !$user->hasRight('facture', 'paiement')
	|| $_SERVER['REQUEST_METHOD'] != 'POST' || GETPOST('token', 'alpha') !== currentToken()
	|| $row->fetch($id) <= 0 || $row->type != SquareTransaction::TYPE_TERMINAL) {
	http_response_code(403);
	print json_encode(array('error' => 'Access denied'));
	exit;
}
// Same access check as the invoice card
$result = restrictedArea($user, 'facture', $row->fk_facture, '', '', 'fk_soc', 'rowid', 0, 1);
if (!$result) {
	http_response_code(403);
	print json_encode(array('error' => 'Access denied'));
	exit;
}

$message = '';
if ($action == 'cancel' && $row->status == SquareTransaction::STATUS_PENDING) {
	$api = new SquareApi($row->livemode);
	if ($api->cancelTerminalCheckout($row->square_id) === null) {
		// Usually: the buyer has already presented a card. The status check below tells.
		$message = $langs->trans('SquareCancelRefused');
	}
}

if ($action == 'status' || $action == 'cancel') {
	if ($row->refresh($user) < 0 && $row->status == SquareTransaction::STATUS_PENDING) {
		$message = $langs->trans('SquareErrorCannotCheck');
		dol_syslog('Square terminal status of row '.$row->id.': '.$row->error, LOG_WARNING);
	}
}

$messages = array(
	SquareTransaction::STATUS_PENDING => $langs->trans('SquareTerminalWaiting'),
	SquareTransaction::STATUS_PROCESSING => $langs->trans('SquareTerminalRecording'),
	SquareTransaction::STATUS_RECORDED => $langs->trans('SquareTerminalRecorded'),
	SquareTransaction::STATUS_ATTENTION => $langs->trans('SquareTerminalAttention', $row->note),
	SquareTransaction::STATUS_CANCELLED => $langs->trans('SquareTerminalCancelled'),
);

print json_encode(array(
	'status' => $row->status,
	'label' => SquareTransaction::statusBadge($row->status),
	'message' => ($message !== '' ? $message.' ' : '').(empty($messages[$row->status]) ? '' : $messages[$row->status]),
	'done' => !in_array($row->status, array(SquareTransaction::STATUS_PENDING, SquareTransaction::STATUS_PROCESSING)),
	'paymenturl' => $row->fk_paiement > 0 ? DOL_URL_ROOT.'/compta/paiement/card.php?id='.$row->fk_paiement : '',
));

$db->close();
