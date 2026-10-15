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
 * \file    square/class/squaretransaction.class.php
 * \ingroup square
 * \brief   A Square payment link or Square Terminal checkout, and the recording of its payment in Dolibarr
 */

dol_include_once('/square/class/squareapi.class.php');
dol_include_once('/square/lib/square.lib.php');

/**
 * A Square payment link or Square Terminal checkout (table llx_square_transaction).
 *
 * Life cycle:
 * - pending: created on Square, not paid yet.
 * - processing: claimed by the one process that records the payment (see claim()). Only one process
 *   can claim a row, so a payment is never recorded twice by paymentok.php, the terminal page and the cron job.
 * - recorded: the payment is recorded in Dolibarr (fk_paiement).
 * - attention: paid on Square but not recorded in Dolibarr. The note says why; someone must record it by hand.
 * - cancelled: cancelled on the Terminal, or a payment link that expired unpaid and was deleted on Square.
 */
class SquareTransaction
{
	const TYPE_LINK = 'link';
	const TYPE_TERMINAL = 'terminal';

	const STATUS_PENDING = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_RECORDED = 'recorded';
	const STATUS_ATTENTION = 'attention';
	const STATUS_CANCELLED = 'cancelled';

	/** Name recorded in llx_paiement.ext_payment_site. paymentok.php records 'square' (the payment method). */
	const PAYMENT_SITE = 'square';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string Output of the cron job */
	public $output = '';

	/** @var int */
	public $id = 0;
	/** @var int */
	public $entity;
	/** @var string */
	public $type = '';
	/** @var int */
	public $livemode = 0;
	/** @var string */
	public $status = '';
	/** @var string */
	public $square_id = '';
	/** @var string */
	public $square_order_id = '';
	/** @var string */
	public $square_payment_id = '';
	/** @var string */
	public $square_url = '';
	/** @var string */
	public $device_id = '';
	/** @var string */
	public $source = '';
	/** @var string */
	public $source_ref = '';
	/** @var int */
	public $fk_facture = 0;
	/** @var string */
	public $fulltag = '';
	/** @var float */
	public $amount = 0;
	/** @var string */
	public $currency = '';
	/** @var int */
	public $fk_paiement = 0;
	/** @var string */
	public $note = '';
	/** @var int */
	public $date_creation;
	/** @var int */
	public $tms;
	/** @var int */
	public $fk_user_creat = 0;

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
	 * Insert the row
	 *
	 * @param	?User	$user	User who creates it (null for an online payment)
	 * @return	int				Id, or <0 on error
	 */
	public function create($user = null)
	{
		global $conf;

		$this->entity = (int) $conf->entity;
		$this->date_creation = dol_now();
		$this->fk_user_creat = ($user && $user->id > 0) ? (int) $user->id : 0;
		if ($this->status === '') {
			$this->status = self::STATUS_PENDING;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."square_transaction";
		$sql .= " (entity, type, livemode, status, square_id, square_order_id, square_payment_id, square_url, device_id,";
		$sql .= " source, source_ref, fk_facture, fulltag, amount, currency, note, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($this->type)."', ".((int) $this->livemode).",";
		$sql .= " '".$this->db->escape($this->status)."', '".$this->db->escape($this->square_id)."',";
		$sql .= " ".$this->quoteOrNull($this->square_order_id).", ".$this->quoteOrNull($this->square_payment_id).",";
		$sql .= " ".$this->quoteOrNull($this->square_url).", ".$this->quoteOrNull($this->device_id).",";
		$sql .= " ".$this->quoteOrNull($this->source).", ".$this->quoteOrNull($this->source_ref).",";
		$sql .= " ".($this->fk_facture > 0 ? ((int) $this->fk_facture) : "NULL").", ".$this->quoteOrNull($this->fulltag).",";
		$sql .= " ".((float) price2num($this->amount, 'MT')).", '".$this->db->escape(strtoupper($this->currency))."',";
		$sql .= " ".$this->quoteOrNull($this->note).", '".$this->db->idate($this->date_creation)."',";
		$sql .= " ".($this->fk_user_creat > 0 ? ((int) $this->fk_user_creat) : "NULL").")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."square_transaction");
		return $this->id;
	}

	/**
	 * Load a row of the current entity
	 *
	 * @param	int		$id			Row id (0 to search by Square id)
	 * @param	string	$squareid	Payment link id or terminal checkout id
	 * @param	string	$orderid	Square order id of a payment link
	 * @return	int					1 if found, 0 if not, <0 on error
	 */
	public function fetch($id, $squareid = '', $orderid = '')
	{
		global $conf;

		$sql = "SELECT rowid, entity, type, livemode, status, square_id, square_order_id, square_payment_id, square_url, device_id,";
		$sql .= " source, source_ref, fk_facture, fulltag, amount, currency, fk_paiement, note, date_creation, tms, fk_user_creat";
		$sql .= " FROM ".MAIN_DB_PREFIX."square_transaction";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		if ($id > 0) {
			$sql .= " AND rowid = ".((int) $id);
		} elseif ($squareid !== '') {
			$sql .= " AND square_id = '".$this->db->escape($squareid)."'";
		} elseif ($orderid !== '') {
			$sql .= " AND square_order_id = '".$this->db->escape($orderid)."'";
		} else {
			return 0;
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->setFromRow($obj);
		return 1;
	}

	/**
	 * Fill the properties from a database row
	 *
	 * @param	object	$obj	Row
	 * @return	void
	 */
	public function setFromRow($obj)
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->type = (string) $obj->type;
		$this->livemode = (int) $obj->livemode;
		$this->status = (string) $obj->status;
		$this->square_id = (string) $obj->square_id;
		$this->square_order_id = (string) $obj->square_order_id;
		$this->square_payment_id = (string) $obj->square_payment_id;
		$this->square_url = (string) $obj->square_url;
		$this->device_id = (string) $obj->device_id;
		$this->source = (string) $obj->source;
		$this->source_ref = (string) $obj->source_ref;
		$this->fk_facture = (int) $obj->fk_facture;
		$this->fulltag = (string) $obj->fulltag;
		$this->amount = (float) $obj->amount;
		$this->currency = (string) $obj->currency;
		$this->fk_paiement = (int) $obj->fk_paiement;
		$this->note = (string) $obj->note;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
	}

	/**
	 * Change the status, only if it is still one of the expected ones.
	 *
	 * This is the lock that keeps a payment from being recorded twice: of several processes trying to move a
	 * row out of 'pending', only one sees an affected row.
	 *
	 * @param	string		$newstatus	New status
	 * @param	string[]	$from		Statuses the row must have now
	 * @param	array<string,string|int|null>	$fields		Other columns to set (square_payment_id, fk_paiement, note)
	 * @return	bool					True if this call changed it
	 */
	public function setStatus($newstatus, $from, $fields = array())
	{
		// tms set explicitly: PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP, and checkProcessing() relies on it
		$sql = "UPDATE ".MAIN_DB_PREFIX."square_transaction SET status = '".$this->db->escape($newstatus)."', tms = '".$this->db->idate(dol_now())."'";
		foreach ($fields as $field => $value) {
			if (!in_array($field, array('square_payment_id', 'fk_paiement', 'note'), true)) {
				continue;
			}
			if ($field == 'fk_paiement') {
				$sql .= ", fk_paiement = ".($value > 0 ? ((int) $value) : "NULL");
			} else {
				$sql .= ", ".$field." = ".$this->quoteOrNull(dol_trunc((string) $value, 255, 'right', 'UTF-8', 1));
			}
		}
		$sql .= " WHERE rowid = ".((int) $this->id);
		$quoted = array();
		foreach ($from as $status) {
			$quoted[] = "'".$this->db->escape($status)."'";
		}
		$sql .= " AND status IN (".implode(', ', $quoted).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR, 0, '_payment');
			return false;
		}
		if ($this->db->affected_rows($resql) < 1) {
			return false;
		}
		$this->status = $newstatus;
		$this->tms = dol_now();
		foreach ($fields as $field => $value) {
			if (property_exists($this, $field)) {
				$this->$field = $value;
			}
		}
		return true;
	}

	/**
	 * Claim the row to record its payment. Only one caller ever gets true for a given row.
	 *
	 * @param	string[]	$paymentids		Square payments that settle it
	 * @return	bool
	 */
	public function claim($paymentids)
	{
		return $this->setStatus(self::STATUS_PROCESSING, array(self::STATUS_PENDING), array('square_payment_id' => implode(',', $paymentids)));
	}

	/**
	 * First Square payment id, the one recorded in llx_paiement.ext_payment_id
	 *
	 * @return string
	 */
	public function mainPaymentId()
	{
		$ids = explode(',', $this->square_payment_id);
		return trim($ids[0]);
	}

	/**
	 * Id of the Dolibarr payment that already uses a Square payment id, if any
	 *
	 * @param	string	$paymentid	Square payment id
	 * @return	int					Payment id, 0 if none, <0 on error
	 */
	public function findRecordedPayment($paymentid)
	{
		if ($paymentid === '') {
			return 0;
		}
		$sql = "SELECT p.rowid FROM ".MAIN_DB_PREFIX."paiement as p";
		$sql .= " WHERE p.ext_payment_id = '".$this->db->escape($paymentid)."' OR p.ext_payment_id LIKE '".$this->db->escape($this->db->escapeforlike($paymentid)).":%'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Ask Square whether the payment link or Terminal checkout has been paid.
	 *
	 * @param	?SquareApi	$api	API client of the row's environment (created if null)
	 * @return	?array{state:string,paymentids:string[],amount:int,reason:string}	state is 'paid', 'unpaid',
	 *				'cancelled' or 'mismatch' (paid, but not the amount asked); null on error ($this->error is set)
	 */
	public function fetchSquareState($api = null)
	{
		if ($api === null) {
			$api = new SquareApi($this->livemode);
		}
		$expected = squareToMinor($this->amount, $this->currency);

		if ($this->type == self::TYPE_LINK) {
			$paid = $api->getOrderPaid($this->square_order_id, $this->currency);
			if ($paid === null) {
				$this->error = $api->error;
				return null;
			}
			if (empty($paid['paymentids'])) {
				return array('state' => 'unpaid', 'paymentids' => array(), 'amount' => 0, 'reason' => '');
			}
			return array(
				'state' => ($paid['amount'] == $expected ? 'paid' : 'mismatch'),
				'paymentids' => $paid['paymentids'],
				'amount' => $paid['amount'],
				'reason' => '',
			);
		}

		$checkout = $api->getTerminalCheckout($this->square_id);
		if ($checkout === null) {
			$this->error = $api->error;
			return null;
		}
		$status = empty($checkout['status']) ? '' : $checkout['status'];
		if ($status == 'CANCELED') {
			return array('state' => 'cancelled', 'paymentids' => array(), 'amount' => 0, 'reason' => empty($checkout['cancel_reason']) ? '' : (string) $checkout['cancel_reason']);
		}
		if ($status != 'COMPLETED') {
			return array('state' => 'unpaid', 'paymentids' => array(), 'amount' => 0, 'reason' => $status);
		}
		// Check each payment with GetPayment rather than trusting the checkout's amount
		$amount = 0;
		$paymentids = array();
		foreach ((empty($checkout['payment_ids']) ? array() : $checkout['payment_ids']) as $paymentid) {
			$payment = $api->getPayment($paymentid);
			if ($payment === null) {
				$this->error = $api->error;
				return null;
			}
			if (SquareApi::isPaymentCompleted($payment, $this->currency)) {
				$amount += (int) $payment['amount_money']['amount'];
				$paymentids[] = (string) $paymentid;
			}
		}
		if (empty($paymentids)) {
			return array('state' => 'unpaid', 'paymentids' => array(), 'amount' => 0, 'reason' => $status);
		}
		return array('state' => ($amount == $expected ? 'paid' : 'mismatch'), 'paymentids' => $paymentids, 'amount' => $amount, 'reason' => '');
	}

	/**
	 * Check the row on Square and, when it is paid for an invoice, record the payment.
	 *
	 * Used by the terminal page and the cron job. Online payments for anything else than an invoice are recorded
	 * by paymentok.php when the buyer comes back; if they never come back, the row is set to 'attention'.
	 *
	 * @param	User	$user		User recording the payment
	 * @param	bool	$handover	True when a buyer who did not come back can no longer do so (cron job)
	 * @return	int					1 if the row changed, 0 if not, <0 on error ($this->error is set)
	 */
	public function refresh($user, $handover = false)
	{
		global $langs;

		$langs->load('square@square');

		if ($this->status == self::STATUS_PROCESSING) {
			return $this->checkProcessing();
		}
		if ($this->status != self::STATUS_PENDING) {
			return 0;
		}

		$state = $this->fetchSquareState();
		if ($state === null) {
			return -1;
		}

		if ($state['state'] == 'unpaid') {
			return 0;
		}
		if ($state['state'] == 'cancelled') {
			return $this->setStatus(self::STATUS_CANCELLED, array(self::STATUS_PENDING), array('note' => $state['reason'])) ? 1 : 0;
		}
		if ($state['state'] == 'mismatch') {
			$note = $langs->transnoentitiesnoconv('SquareNoteAmountMismatch', price(squareFromMinor($state['amount'], $this->currency)).' '.$this->currency);
			return $this->setStatus(self::STATUS_ATTENTION, array(self::STATUS_PENDING), array('square_payment_id' => implode(',', $state['paymentids']), 'note' => $note)) ? 1 : 0;
		}

		// Paid
		if ($this->fk_facture <= 0) {
			if (!$handover) {
				return 0;	// The buyer may still come back to paymentok.php, which records it
			}
			$note = $langs->transnoentitiesnoconv('SquareNoteRecordByHand', $this->fulltag);
			return $this->setStatus(self::STATUS_ATTENTION, array(self::STATUS_PENDING), array('square_payment_id' => implode(',', $state['paymentids']), 'note' => $note)) ? 1 : 0;
		}

		if (!$this->claim($state['paymentids'])) {
			return 0;	// Another process is recording it
		}
		return $this->recordInvoicePayment($user) > 0 ? 1 : -1;
	}

	/**
	 * For a row claimed by paymentok.php: once the page has had time to run, find the payment it recorded
	 *
	 * @return int	1 if the row changed, 0 if not, <0 on error
	 */
	public function checkProcessing()
	{
		global $langs;

		$paiementid = $this->findRecordedPayment($this->mainPaymentId());
		if ($paiementid < 0) {
			return -1;
		}
		if ($paiementid > 0) {
			return $this->setStatus(self::STATUS_RECORDED, array(self::STATUS_PROCESSING), array('fk_paiement' => $paiementid, 'note' => '')) ? 1 : 0;
		}
		// paymentok.php runs in seconds: after 30 minutes, it failed
		if (dol_now() - $this->tms < 1800) {
			return 0;
		}
		$note = $langs->transnoentitiesnoconv('SquareNoteNotRecorded');
		return $this->setStatus(self::STATUS_ATTENTION, array(self::STATUS_PROCESSING), array('note' => $note)) ? 1 : 0;
	}

	/**
	 * Record the payment of a claimed row on its invoice, and in the bank account of the setup.
	 *
	 * @param	User	$user	User recording the payment
	 * @return	int				Payment id, or <0 on error (the row is then set to 'attention' with the reason)
	 */
	public function recordInvoicePayment($user)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

		$langs->loadLangs(array('bills', 'square@square'));

		if ($this->status != self::STATUS_PROCESSING) {
			$this->error = 'Row '.$this->id.' is not claimed';
			return -1;
		}

		// Already recorded (a previous run that died after the commit, for example)
		$existing = $this->findRecordedPayment($this->mainPaymentId());
		if ($existing > 0) {
			$this->setStatus(self::STATUS_RECORDED, array(self::STATUS_PROCESSING), array('fk_paiement' => $existing, 'note' => ''));
			return $existing;
		}

		$invoice = new Facture($this->db);
		$error = '';
		if ($existing < 0) {
			$error = $this->error;
		} elseif ($invoice->fetch($this->fk_facture) <= 0) {
			$error = $langs->transnoentitiesnoconv('SquareNoteInvoiceNotFound', $this->fk_facture);
		} elseif (strtoupper($this->currency) != strtoupper($conf->currency)) {
			// As paymentok.php: payments in another currency than the company's are not recorded automatically
			$error = $langs->transnoentitiesnoconv('SquareNoteOtherCurrency', $this->currency);
		}

		$paiementid = 0;
		if ($error === '') {
			$this->db->begin();

			if ($invoice->status == Facture::STATUS_DRAFT && $invoice->validate($user) < 0) {
				$error = $invoice->error;
			}

			if ($error === '') {
				$now = dol_now();
				$paiement = new Paiement($this->db);
				$paiement->datepaye = $now;
				$paiement->amounts = array($invoice->id => (float) price2num($this->amount, 'MT'));
				$paiement->paiementid = squarePaymentModeId();
				$paiement->num_payment = '';
				$paiement->note_public = ($this->type == self::TYPE_TERMINAL ? 'Square Terminal payment ' : 'Square online payment ').dol_print_date($now, 'standard');
				$paiement->ext_payment_id = $this->mainPaymentId();
				$paiement->ext_payment_site = self::PAYMENT_SITE;

				$paiementid = $paiement->create($user, 1);	// Also closes the invoice when fully paid
				if ($paiementid < 0) {
					$error = $paiement->error.' '.implode(' ', $paiement->errors);
				} elseif (isModEnabled('bank')) {
					$bankaccountid = getDolGlobalInt('SQUARE_BANK_ACCOUNT_FOR_PAYMENTS');
					if ($bankaccountid <= 0) {
						$error = $langs->transnoentitiesnoconv('SquareErrorNoBankAccount');
					} else {
						$label = ($invoice->type == Facture::TYPE_CREDIT_NOTE ? '(CustomerInvoicePaymentBack)' : '(CustomerInvoicePayment)');
						if ($paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '') < 0) {
							$error = $paiement->error.' '.implode(' ', $paiement->errors);
						}
					}
				}
			}

			if ($error === '') {
				$this->db->commit();
			} else {
				$this->db->rollback();
			}
		}

		if ($error !== '') {
			$this->error = $error;
			dol_syslog(__METHOD__.' row '.$this->id.' paid on Square but not recorded: '.$error, LOG_ERR, 0, '_payment');
			$this->setStatus(self::STATUS_ATTENTION, array(self::STATUS_PROCESSING), array('note' => $error));
			return -1;
		}

		$this->setStatus(self::STATUS_RECORDED, array(self::STATUS_PROCESSING), array('fk_paiement' => $paiementid, 'note' => ''));
		return $paiementid;
	}

	/**
	 * Cron job: catch up with payments taken on Square that are not recorded in Dolibarr yet, and expire old links.
	 *
	 * @return int	0 if OK, else the number of errors (cron convention)
	 */
	public function cronReconcile()
	{
		global $conf, $user, $langs;

		$langs->load('square@square');
		$this->output = '';
		$now = dol_now();
		$expirydays = getDolGlobalInt('SQUARE_LINK_EXPIRY_DAYS', 7);

		// Online buyers get 10 minutes to come back to paymentok.php before the cron job steps in
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."square_transaction";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND status IN ('".self::STATUS_PENDING."', '".self::STATUS_PROCESSING."')";
		$sql .= " AND date_creation < '".$this->db->idate($now - 600)."'";
		$sql .= " ORDER BY rowid";
		$sql .= $this->db->plimit(200);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = $this->error;
			return 1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$nberrors = 0;
		$nbchanged = 0;
		foreach ($ids as $id) {
			$row = new SquareTransaction($this->db);
			if ($row->fetch($id) <= 0) {
				continue;
			}
			$result = $row->refresh($user, true);
			if ($result < 0) {
				$nberrors++;
				$this->output .= 'Row '.$id.': '.$row->error."\n";
				continue;
			}
			$nbchanged += $result;

			// An unpaid link past its expiry is deleted on Square, then checked once more in case it was paid meanwhile
			if ($result == 0 && $row->status == self::STATUS_PENDING && $row->type == self::TYPE_LINK
				&& $expirydays > 0 && $row->date_creation < $now - $expirydays * 86400) {
				$api = new SquareApi($row->livemode);
				if ($api->deletePaymentLink($row->square_id)) {
					if ($row->refresh($user, true) == 0 && $row->status == self::STATUS_PENDING) {
						$row->setStatus(self::STATUS_CANCELLED, array(self::STATUS_PENDING), array('note' => $langs->transnoentitiesnoconv('SquareNoteExpired')));
					}
					$nbchanged++;
				} else {
					$nberrors++;
					$this->output .= 'Row '.$id.': '.$api->error."\n";
				}
			}
		}

		$this->output .= count($ids).' Square transaction(s) checked, '.$nbchanged.' updated, '.$nberrors.' error(s)';
		return $nberrors;
	}

	/**
	 * Label of a status
	 *
	 * @param	string	$status		Status
	 * @return	string				HTML badge
	 */
	public static function statusBadge($status)
	{
		global $langs;

		$langs->load('square@square');
		$types = array(
			self::STATUS_PENDING => 'status1',
			self::STATUS_PROCESSING => 'status3',
			self::STATUS_RECORDED => 'status4',
			self::STATUS_ATTENTION => 'status8',
			self::STATUS_CANCELLED => 'status9',
		);
		$label = $langs->trans('SquareStatus'.ucfirst($status));
		return dolGetStatus($label, $label, '', empty($types[$status]) ? 'status0' : $types[$status], 1);
	}

	/**
	 * SQL literal for a string, NULL when empty
	 *
	 * @param	string	$value	Value
	 * @return	string
	 */
	private function quoteOrNull($value)
	{
		return ($value === '' || $value === null) ? "NULL" : "'".$this->db->escape($value)."'";
	}
}
