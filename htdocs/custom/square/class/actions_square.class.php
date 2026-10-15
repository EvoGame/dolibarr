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
		$creator = new SquareTransaction($this->db);
		$row = $creator->createLink($api, $name, $amount, $currency, $urlok, $note, GETPOST('email', 'alphanohtml'), (string) $source, (string) $ref, empty($tag['INV']) ? 0 : (int) $tag['INV'], (string) $FULLTAG);
		if ($row === null) {
			dol_syslog('Square payment link not created: '.$creator->error, LOG_ERR, 0, '_payment');
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
		if ($found <= 0) {
			// A link sent by email or SMS, paid on another device: the pending links of this payment page tag
			$found = $this->findPaidLinkByTag((string) $FULLTAG, $row);
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
	 * The most recent pending payment link with this payment page tag that Square says is paid
	 *
	 * @param	string				$fulltag	Tag of the payment page
	 * @param	SquareTransaction	$row		Loaded with the row found
	 * @return	int								1 if found, 0 if not
	 */
	private function findPaidLinkByTag($fulltag, $row)
	{
		global $conf;

		if ($fulltag === '') {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."square_transaction";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND type = '".$this->db->escape(SquareTransaction::TYPE_LINK)."'";
		$sql .= " AND status = '".$this->db->escape(SquareTransaction::STATUS_PENDING)."'";
		$sql .= " AND fulltag = '".$this->db->escape($fulltag)."'";
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(5);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		foreach ($ids as $id) {
			if ($row->fetch($id) > 0) {
				$state = $row->fetchSquareState();
				if ($state !== null && $state['state'] != 'unpaid') {
					return 1;
				}
			}
		}
		return 0;
	}

	/**
	 * Invoice card: buttons to take the payment on a Square Terminal and to send a payment link.
	 * TakePOS payment screen: the same two, as payment buttons (see completePayment()).
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

		if (!isModEnabled('square') || !is_object($object) || $object->element != 'facture') {
			return 0;
		}
		$contexts = explode(':', $parameters['context']);
		dol_include_once('/square/lib/square.lib.php');
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$langs->load('square@square');

		if (in_array('takepospay', $contexts)) {
			$buttons = $this->takeposButtons($object);
			if ($buttons) {
				$this->results = $buttons;
			}
			return 0;
		}
		if (!in_array('invoicecard', $contexts)) {
			return 0;
		}

		if ($object->status != Facture::STATUS_VALIDATED || $object->paye || $object->type == Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		if ((!empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency) || $object->getRemainToPay() <= 0) {
			return 0;
		}

		$problem = squareSetupProblem();
		if ($user->hasRight('square', 'terminal') && $user->hasRight('facture', 'paiement')) {
			$url = dol_buildpath('/square/terminal.php', 1).'?facid='.((int) $object->id);
			print dolGetButtonAction($problem, $langs->trans('SquareTakeTerminalPayment'), 'default', $url, 'square-terminal', $problem === '');
		}
		if ($user->hasRight('square', 'sendlink')) {
			$url = dol_buildpath('/square/sendlink.php', 1).'?facid='.((int) $object->id);
			print dolGetButtonAction($problem, $langs->trans('SquareSendLink'), 'default', $url, 'square-sendlink', $problem === '');
		}

		return 0;
	}

	/**
	 * Buttons of the TakePOS payment screen, in TakePOS's $action_buttons format
	 *
	 * @param	Facture		$invoice	Sale being paid
	 * @return	array<int,array{function:string,span:string,text:string,class:string}>
	 */
	private function takeposButtons($invoice)
	{
		global $conf, $langs, $user;

		if (empty($invoice->id) || $invoice->type == Facture::TYPE_CREDIT_NOTE || $invoice->total_ttc <= 0
			|| (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code != $conf->currency)) {
			return array();
		}
		$problem = squareSetupProblem();
		$buttons = array();

		if ($user->hasRight('square', 'terminal')) {
			if ($problem === '') {
				$problem = squareTakeposProblem();
			}
			$buttons[] = array(
				'function' => ($problem === '' ? 'squareTerminalPay();' : 'alert('.dolPrintHTMLForAttribute(json_encode($problem)).');'),
				'span' => "class='fa fa-2x fa-credit-card iconwithlabel'",
				'text' => '</span><br><span>'.dolPrintLabel($langs->trans('SquareTakeposTerminal')),
				'class' => ($problem === '' ? '' : 'disabled'),
			);
		}
		if ($user->hasRight('square', 'sendlink') && squareSetupProblem() === '') {
			$buttons[] = array(
				'function' => 'squareSendLinkForm();',
				'span' => "class='fa fa-2x fa-paper-plane iconwithlabel'",
				'text' => '</span><br><span>'.dolPrintLabel($langs->trans('SquareTakeposLink')),
				'class' => '',
			);
		}
		return $buttons;
	}

	/**
	 * TakePOS payment screen: the panel and the code behind the Square buttons
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	Facture				$object			Sale being paid
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int<0,0>
	 */
	public function completePayment($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user, $place;

		if (!isModEnabled('square') || !in_array('takepospay', explode(':', $parameters['context'])) || !is_object($object) || empty($object->id)) {
			return 0;
		}
		if (!$user->hasRight('square', 'terminal') && !$user->hasRight('square', 'sendlink')) {
			return 0;
		}
		dol_include_once('/square/lib/square.lib.php');
		$langs->loadLangs(array('square@square'));

		$object->fetch_thirdparty();
		$email = '';
		$phone = '';
		// The till's default customer is a placeholder: nothing of theirs to pre-fill
		if ((int) $object->socid != getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.(empty($_SESSION['takeposterminal']) ? '' : $_SESSION['takeposterminal'])) && is_object($object->thirdparty)) {
			$email = (string) $object->thirdparty->email;
			$phone = (string) (empty($object->thirdparty->phone_mobile) ? $object->thirdparty->phone : $object->thirdparty->phone_mobile);
		}
		$paycode = (string) dol_getIdFromCode($this->db, squarePaymentModeId(), 'c_paiement', 'id', 'code', 1);
		$remain = (float) price2num($object->getRemainToPay(), 'MT');

		$js = array(
			'ajax' => dol_buildpath('/square/ajax/takepos.php', 1),
			'token' => currentToken(),
			'invoiceid' => (int) $object->id,
			'place' => (string) $place,
			'remain' => $remain,
			'paycode' => $paycode,
			'cansms' => squareCanSendSms(),
			'email' => $email,
			'phone' => $phone,
			'sandbox' => !getDolGlobalInt('SQUARE_LIVE'),
			'txt' => array(
				'sending' => $langs->transnoentitiesnoconv('SquareTakeposSending'),
				'cancel' => $langs->transnoentitiesnoconv('SquareCancelOnTerminal'),
				'close' => $langs->transnoentitiesnoconv('Close'),
				'email' => $langs->transnoentitiesnoconv('Email'),
				'phone' => $langs->transnoentitiesnoconv('SquarePhoneForSms'),
				'byemail' => $langs->transnoentitiesnoconv('SquareSendByEmail'),
				'bysms' => $langs->transnoentitiesnoconv('SquareSendBySms'),
				'linkhelp' => $langs->transnoentitiesnoconv(squareCanSendSms() ? 'SquareTakeposLinkHelp' : 'SquareTakeposLinkHelpEmail'),
				'sandbox' => $langs->transnoentitiesnoconv('SquareSandboxWarning'),
				'error' => $langs->transnoentitiesnoconv('Error'),
			),
		);

		$out = '<div id="squarepanel" class="hideobject" style="position:absolute; z-index:100; left:5%; top:10%; width:90%; padding:1em; box-sizing:border-box; background:var(--colorbackbody, #fff); color:var(--colortext, #000); border:2px solid #888; border-radius:6px; text-align:center;"></div>';
		$out .= '<script>
		var squarePos = '.json_encode($js).';
		var squareRow = 0, squareTimer = null, squareAmountSent = 0;
		function squareEsc(s) { return $("<div>").text(s).html(); }
		function squareShow(html) { $("#squarepanel").html(html).removeClass("hideobject"); }
		function squareHide() { $("#squarepanel").addClass("hideobject").html(""); }
		function squareAmount() {
			var a = parseFloat($("#change1").val());
			if (!a || a <= 0 || a > squarePos.remain) { a = squarePos.remain; }
			return a;
		}
		function squarePost(data, done) {
			data.token = squarePos.token;
			$.post(squarePos.ajax, data, done, "json").fail(function(xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : squarePos.txt.error;
				squareShow("<div class=\"error\">"+squareEsc(msg)+"</div><br><button type=\"button\" class=\"button\" onclick=\"squareHide();\">"+squareEsc(squarePos.txt.close)+"</button>");
			});
		}
		/* Same end as TakePOS Validate(): close the popup when the sale is paid, else reload it */
		function squareAfterTakepos(amount) {
			if (amount >= squarePos.remain) {
				parent.$("#invoiceid").val("");
				parent.$.colorbox.close();
			} else {
				location.reload();
			}
		}
		function squareTerminalPay() {
			squareAmountSent = squareAmount();
			squareShow("<div class=\"warning\">"+squareEsc(squarePos.txt.sending)+"</div>");
			squarePost({action: "send", invoiceid: squarePos.invoiceid, amount: squareAmountSent}, function(d) {
				if (d.error) { squareShow("<div class=\"error\">"+squareEsc(d.error)+"</div><br><button type=\"button\" class=\"button\" onclick=\"squareHide();\">"+squareEsc(squarePos.txt.close)+"</button>"); return; }
				squareRow = d.id;
				squareAmountSent = d.amount;
				squareStatus(d);
				squareTimer = setInterval(function() { squarePost({action: "status", id: squareRow}, squareStatus); }, 2500);
			});
		}
		function squareStatus(d) {
			var html = (squarePos.sandbox ? "<div class=\"opacitymedium\">"+squareEsc(squarePos.txt.sandbox)+"</div>" : "")
				+ "<div class=\""+(d.status == "attention" ? "error" : "warning")+"\">"+squareEsc(d.message)+"</div><br>";
			if (d.status == "pending") {
				html += "<button type=\"button\" class=\"button\" onclick=\"squarePost({action: \'cancel\', id: squareRow}, squareStatus);\">"+squareEsc(squarePos.txt.cancel)+"</button>";
			} else if (d.done) {
				html += "<button type=\"button\" class=\"button\" onclick=\"squareHide();\">"+squareEsc(squarePos.txt.close)+"</button>";
			}
			squareShow(html);
			if (d.done || d.claimed) {
				clearInterval(squareTimer);
			}
			if (d.claimed) {
				/* Paid on the Terminal: TakePOS validates the sale and records the payment, then the module links it */
				var accountid = $("#selectaccountid").length ? $("#selectaccountid").val() : "";
				parent.$("#poslines").load("invoice.php?place="+encodeURIComponent(squarePos.place)+"&action=valid&token="+encodeURIComponent(squarePos.token)+"&pay="+encodeURIComponent(squarePos.paycode)+"&amount="+d.amount+"&excess=0&invoiceid="+squarePos.invoiceid+"&accountid="+encodeURIComponent(accountid), function() {
					squarePost({action: "status", id: squareRow}, function(d2) {
						squareAfterTakepos(d.amount);
					});
				});
			}
		}
		function squareSendLinkForm() {
			var html = "<div class=\"opacitymedium\">"+squareEsc(squarePos.txt.linkhelp)+"</div><br>"
				+ "<div><input type=\"email\" id=\"squareto_email\" class=\"minwidth200\" placeholder=\""+squareEsc(squarePos.txt.email)+"\" value=\""+squareEsc(squarePos.email)+"\"> "
				+ "<button type=\"button\" class=\"button\" onclick=\"squareSendLink(\'email\');\">"+squareEsc(squarePos.txt.byemail)+"</button></div><br>";
			/* No SMS provider: no SMS option at all, rather than one that cannot be used */
			if (squarePos.cansms) {
				html += "<div><input type=\"tel\" id=\"squareto_sms\" class=\"minwidth200\" placeholder=\""+squareEsc(squarePos.txt.phone)+"\" value=\""+squareEsc(squarePos.phone)+"\"> "
					+ "<button type=\"button\" class=\"button\" onclick=\"squareSendLink(\'sms\');\">"+squareEsc(squarePos.txt.bysms)+"</button></div><br>";
			}
			html += "<button type=\"button\" class=\"button button-cancel\" onclick=\"squareHide();\">"+squareEsc(squarePos.txt.close)+"</button>";
			squareShow(html);
		}
		function squareSendLink(channel) {
			var to = $("#squareto_"+channel).val();
			if (!to) { $("#squareto_"+channel).focus(); return; }
			squareShow("<div class=\"warning\">"+squareEsc(squarePos.txt.sending)+"</div>");
			/* The sale is validated by TakePOS as awaiting payment (as its "delayed" payment does), then the link is sent */
			parent.$("#poslines").load("invoice.php?place="+encodeURIComponent(squarePos.place)+"&action=valid&token="+encodeURIComponent(squarePos.token)+"&pay=delayed&amount=0&excess=0&invoiceid="+squarePos.invoiceid, function() {
				squarePost({action: "sendlink", invoiceid: squarePos.invoiceid, channel: channel, to: to}, function(d) {
					if (d.error) {
						squareShow("<div class=\"error\">"+squareEsc(d.error)+"</div><br><button type=\"button\" class=\"button\" onclick=\"squareSendLinkForm();\">"+squareEsc(squarePos.txt.byemail)+(squarePos.cansms ? " / "+squareEsc(squarePos.txt.bysms) : "")+"</button> <button type=\"button\" class=\"button\" onclick=\"squareAfterTakepos(0);\">"+squareEsc(squarePos.txt.close)+"</button>");
						return;
					}
					squareShow("<div class=\"ok\">"+squareEsc(d.message)+"</div>");
					setTimeout(function() { parent.$("#invoiceid").val(""); parent.$.colorbox.close(); }, 2500);
				});
			});
		}
		</script>';

		$this->resprints = $out;
		return 0;
	}
}
