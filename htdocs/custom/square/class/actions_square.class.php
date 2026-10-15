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
 * \file    square/class/actions_square.class.php
 * \ingroup square
 * \brief   Hook implementations of module Square
 *
 * Online payment, with the hooks core gives external payment modules (see docs/maintenance.md):
 * 1. newpayment.php, getValidPayment: 'square' becomes a valid payment method (also used by the invoice card
 *    and emails to decide whether to show the online payment link).
 * 2. newpayment.php, doAddButton: the "Pay with Square" button.
 * 3. newpayment.php, doPayment with action dopayment: a Square payment link is created for the amount, and the
 *    buyer is sent to Square's checkout page. Square sends them back to paymentok.php.
 * 4. paymentok.php, isPaymentOK: the payment is checked on Square (order tenders, then each payment), the row is
 *    claimed, and core records the payment (invoice, order, membership, donation...) as it does for Stripe.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook class. Contexts are declared in modSquare::$module_parts['hooks'].
 */
class ActionsSquare extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var mixed[] Merged into $hookmanager->resArray */
	public $results = array();

	/** @var ?string */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add 'square' to the valid online payment methods, once the module is set up.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (paymentmethod, mode)
	 * @param	object				$object			Not used
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<0,0>
	 */
	public function getValidPayment($parameters, &$object, &$action, $hookmanager)
	{
		$paymentmethod = empty($parameters['paymentmethod']) ? '' : $parameters['paymentmethod'];
		if (!isModEnabled('square') || ($paymentmethod !== '' && $paymentmethod != 'square')) {
			return 0;
		}
		dol_include_once('/square/lib/square.lib.php');
		if (squareSetupProblem() !== '') {
			return 0;
		}
		$this->results['validpaymentmethod'] = array(
			'square' => empty($parameters['mode']) ? 'valid' : array('label' => 'Square', 'status' => 'valid'),
		);
		return 0;
	}

	/**
	 * Bank account in which Square payments are recorded (newpayment.php and paymentok.php)
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (paymentmethod)
	 * @param	object				$object			Not used
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<0,0>
	 */
	public function getBankAccountPaymentMethod($parameters, &$object, &$action, $hookmanager)
	{
		if (isModEnabled('square') && !empty($parameters['paymentmethod']) && $parameters['paymentmethod'] == 'square') {
			$this->results['bankaccountid'] = getDolGlobalInt('SQUARE_BANK_ACCOUNT_FOR_PAYMENTS', -1);
		}
		return 0;
	}

	/**
	 * The "Pay with Square" button of newpayment.php
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (paymentmethod)
	 * @param	object				$object			Object paid
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<0,0>
	 */
	public function doAddButton($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $source;

		$paymentmethod = empty($parameters['paymentmethod']) ? '' : $parameters['paymentmethod'];
		if (!isModEnabled('square') || ($paymentmethod !== '' && $paymentmethod != 'square')) {
			return 0;
		}
		// Same switch as core's MEMBER_FORCE_DISABLE_STRIPE: e.g. INVOICE_FORCE_DISABLE_SQUARE
		if (getDolGlobalString(strtoupper((string) $source).'_FORCE_DISABLE_SQUARE')) {
			return 0;
		}
		dol_include_once('/square/lib/square.lib.php');
		if (squareSetupProblem() !== '') {
			return 0;
		}
		$langs->load('square@square');

		$out = '<div class="button buttonpayment" id="div_dopayment_square">';
		$out .= '<span class="fa fa-credit-card"></span> ';
		$out .= '<input class="" type="submit" id="dopayment_square" name="dopayment_square" value="'.dolPrintHTMLForAttribute($langs->trans('SquareDoPayment')).'">';
		$out .= '<br>';
		$out .= '<span class="buttonpaymentsmall">'.$langs->trans('SquareDoPaymentHelp').'</span>';
		$out .= '</div>';
		$out .= '<script>
			$(document).ready(function() {
				$("#div_dopayment_square").click(function() {
					$("#dopayment_square").click();
				});
				$("#dopayment_square").click(function(e) {
					$("#div_dopayment_square").css("cursor", "wait");
					e.stopPropagation();
					return true;
				});
			});
		</script>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * newpayment.php, after the buyer chose Square: create a payment link and send the buyer to it.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (paymentmethod)
	 * @param	object				$object			Object paid
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<-1,0>
	 */
	public function doPayment($parameters, &$object, &$action, $hookmanager)
	{
		// Globals of htdocs/public/payment/newpayment.php, which is not a function
		global $conf, $langs, $mysoc, $urlok, $FULLTAG, $source, $ref, $currency;

		if (!isModEnabled('square') || $action != 'dopayment' || empty($parameters['paymentmethod']) || $parameters['paymentmethod'] != 'square') {
			return 0;
		}

		dol_include_once('/square/class/squaretransaction.class.php');
		$langs->load('square@square');

		$problem = squareSetupProblem();
		if ($problem !== '') {
			$this->errors[] = $problem;
			$action = '';
			return -1;
		}

		$amount = (float) price2num(GETPOST('newamount', 'alpha'), 'MT');
		$currency = empty($currency) ? $conf->currency : (string) $currency;
		if ($amount <= 0) {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Amount'));
			$action = '';
			return -1;
		}

		$tag = dolExplodeIntoArray((string) $FULLTAG, '.', '=');
		$name = $langs->transnoentitiesnoconv('SquarePaymentTo', $mysoc->name);
		if (!empty($ref)) {
			$name .= ' - '.$ref;
		}
		$note = 'Dolibarr '.($source ? $source.' ' : '').($ref ? $ref.' ' : '').'('.$FULLTAG.')';

		$api = new SquareApi();
		$link = $api->createPaymentLink($name, squareToMinor($amount, $currency), $currency, $urlok, $note, GETPOST('email', 'alphanohtml'));
		if ($link === null) {
			dol_syslog('Square payment link not created: '.$api->error, LOG_ERR, 0, '_payment');
			$this->errors[] = $langs->trans('SquareErrorLinkNotCreated');
			$action = '';
			return -1;
		}

		$row = new SquareTransaction($this->db);
		$row->type = SquareTransaction::TYPE_LINK;
		$row->livemode = $api->live;
		$row->square_id = (string) $link['id'];
		$row->square_order_id = (string) $link['order_id'];
		$row->square_url = (string) $link['url'];
		$row->source = (string) $source;
		$row->source_ref = (string) $ref;
		$row->fk_facture = empty($tag['INV']) ? 0 : (int) $tag['INV'];
		$row->fulltag = (string) $FULLTAG;
		$row->amount = $amount;
		$row->currency = $currency;
		if ($row->create() < 0) {
			// Without the row the payment could not be checked: do not let the buyer pay
			dol_syslog('Square payment link '.$link['id'].' created but not stored: '.$row->error, LOG_ERR, 0, '_payment');
			$api->deletePaymentLink((string) $link['id']);
			$this->errors[] = $langs->trans('SquareErrorLinkNotCreated');
			$action = '';
			return -1;
		}

		// Read by paymentok.php when the buyer comes back
		$remoteip = getUserRemoteIP();
		$_SESSION['FinalPaymentAmt'] = $amount;
		$_SESSION['currencyCodeType'] = $currency;
		$_SESSION['paymentType'] = (string) dol_getIdFromCode($this->db, squarePaymentModeId(), 'c_paiement', 'id', 'code', 1);
		$_SESSION['ipaddress'] = ($remoteip ? $remoteip : 'unknown');
		$_SESSION['TRANSACTIONID'] = '';
		// Read unconditionally by paymentok.php, which warns when they are unset
		$_SESSION['payerID'] = '';
		$_SESSION['onlinetoken'] = '';
		$_SESSION['paymentoksessioncode'] = '';
		$_SESSION['SQUARE_TRANSACTION_ID'] = $row->id;

		dol_syslog('Square payment link '.$row->square_id.' (order '.$row->square_order_id.') created for '.$amount.' '.$currency.', redirect to '.$row->square_url, LOG_DEBUG, 0, '_payment');
		header('Location: '.$row->square_url);
		exit;
	}

	/**
	 * paymentok.php: whether the buyer really paid on Square. Only then does core record the payment.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (paymentmethod)
	 * @param	object				$object			Not used
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<-1,0>
	 */
	public function isPaymentOK($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $FULLTAG;

		if (!isModEnabled('square') || empty($parameters['paymentmethod']) || $parameters['paymentmethod'] != 'square') {
			return 0;
		}

		dol_include_once('/square/class/squaretransaction.class.php');
		$langs->load('square@square');
		$this->results['ispaymentok'] = false;

		// The row of this browser session, else the order Square adds to the redirect URL
		$row = new SquareTransaction($this->db);
		$found = 0;
		if (!empty($_SESSION['SQUARE_TRANSACTION_ID'])) {
			$found = $row->fetch((int) $_SESSION['SQUARE_TRANSACTION_ID']);
		}
		if ($found <= 0 && GETPOST('orderId', 'aZ09')) {
			$found = $row->fetch(0, '', GETPOST('orderId', 'aZ09'));
		}
		if ($found <= 0 || $row->type != SquareTransaction::TYPE_LINK || $row->fulltag !== (string) $FULLTAG) {
			dol_syslog('Square paymentok: no payment link matches this return (fulltag '.$FULLTAG.')', LOG_WARNING, 0, '_payment');
			return 0;
		}

		if ($row->status == SquareTransaction::STATUS_PROCESSING || $row->status == SquareTransaction::STATUS_RECORDED) {
			// Page reloaded, or the cron job recorded it first
			$this->errors[] = $langs->trans('SquareAlreadyRecorded');
			return -1;
		}

		$state = $row->fetchSquareState();
		if ($state === null) {
			$this->errors[] = $langs->trans('SquareErrorCannotCheck');
			return -1;
		}
		if ($state['state'] == 'mismatch') {
			dol_syslog('Square paymentok: link '.$row->square_id.' paid '.$state['amount'].' instead of '.$row->amount, LOG_ERR, 0, '_payment');
			$note = $langs->transnoentitiesnoconv('SquareNoteAmountMismatch', price(squareFromMinor($state['amount'], $row->currency)).' '.$row->currency);
			$row->setStatus(SquareTransaction::STATUS_ATTENTION, array(SquareTransaction::STATUS_PENDING), array('square_payment_id' => implode(',', $state['paymentids']), 'note' => $note));
			return 0;
		}
		if ($state['state'] != 'paid') {
			dol_syslog('Square paymentok: link '.$row->square_id.' is not paid', LOG_WARNING, 0, '_payment');
			return 0;
		}
		if (!$row->claim($state['paymentids'])) {
			$this->errors[] = $langs->trans('SquareAlreadyRecorded');
			return -1;
		}

		// What core records: from the row, not from the session, which may have been lost on the way to Square
		$_SESSION['FinalPaymentAmt'] = (float) $row->amount;
		$_SESSION['currencyCodeType'] = $row->currency;
		$_SESSION['TRANSACTIONID'] = $row->mainPaymentId();
		$_SESSION['paymentType'] = (string) dol_getIdFromCode($this->db, squarePaymentModeId(), 'c_paiement', 'id', 'code', 1);
		if (empty($_SESSION['ipaddress'])) {
			$_SESSION['ipaddress'] = getUserRemoteIP();
		}
		unset($_SESSION['SQUARE_TRANSACTION_ID']);

		// Row stays 'processing': the cron job sets it to 'recorded' once it finds the payment core recorded
		$this->results['ispaymentok'] = true;
		return 0;
	}

	/**
	 * Invoice card: button to take the payment on a Square Terminal
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	Facture				$object			Invoice
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<0,0>
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		if (!isModEnabled('square') || !in_array('invoicecard', explode(':', $parameters['context'])) || !is_object($object) || $object->element != 'facture') {
			return 0;
		}
		if (!$user->hasRight('square', 'terminal') || !$user->hasRight('facture', 'paiement')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		if ($object->status != Facture::STATUS_VALIDATED || $object->paye || $object->type == Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		if ((!empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency) || $object->getRemainToPay() <= 0) {
			return 0;
		}
		$langs->load('square@square');

		dol_include_once('/square/lib/square.lib.php');
		$problem = squareSetupProblem();
		$url = dol_buildpath('/square/terminal.php', 1).'?facid='.((int) $object->id);
		print dolGetButtonAction($problem, $langs->trans('SquareTakeTerminalPayment'), 'default', $url, 'square-terminal', $problem === '');

		return 0;
	}
}
