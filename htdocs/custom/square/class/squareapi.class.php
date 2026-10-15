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
 * \file    square/class/squareapi.class.php
 * \ingroup square
 * \brief   Minimal client for the Square REST API (no SDK: Square's PHP SDK needs PHP 8.1 and Composer)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Square REST API client, for the environment chosen in the module setup (sandbox or production)
 */
class SquareApi
{
	/** Square API version sent with every request. Check the changelog before changing it (docs/maintenance.md). */
	const API_VERSION = '2026-09-16';

	const URL_LIVE = 'https://connect.squareup.com';
	const URL_SANDBOX = 'https://connect.squareupsandbox.com';

	/** @var int 1 for production, 0 for the sandbox */
	public $live;

	/** @var string */
	public $accesstoken;

	/** @var string */
	public $locationid;

	/** @var string Last error, readable by a user */
	public $error = '';

	/** @var int HTTP status of the last request (0 if the request could not be sent) */
	public $httpcode = 0;

	/**
	 * Constructor
	 *
	 * @param	int<-1,1>	$live	1 for production, 0 for the sandbox, -1 for the environment chosen in the setup
	 */
	public function __construct($live = -1)
	{
		$this->live = ($live < 0 ? getDolGlobalInt('SQUARE_LIVE') : $live) ? 1 : 0;
		$env = $this->live ? 'LIVE' : 'SANDBOX';
		$this->accesstoken = getDolGlobalString('SQUARE_'.$env.'_ACCESS_TOKEN');
		$this->locationid = getDolGlobalString('SQUARE_'.$env.'_LOCATION_ID');
	}

	/**
	 * Whether an access token and a location are set for this environment
	 *
	 * @return bool
	 */
	public function isConfigured()
	{
		return $this->accesstoken !== '' && $this->locationid !== '';
	}

	/**
	 * Send a request to the Square API.
	 *
	 * @param	'GET'|'POST'|'PUT'|'DELETE'		$method		HTTP method
	 * @param	string							$path		Path, starting with /v2/
	 * @param	?array<string,mixed>			$body		JSON body (POST, PUT)
	 * @return	?array<string,mixed>						Decoded response, or null on error ($this->error is set)
	 */
	public function request($method, $path, $body = null)
	{
		$this->error = '';
		$this->httpcode = 0;
		if ($this->accesstoken === '') {
			$this->error = 'No Square access token is set for the '.($this->live ? 'production' : 'sandbox').' environment';
			return null;
		}

		$headers = array(
			'Authorization: Bearer '.$this->accesstoken,
			'Square-Version: '.self::API_VERSION,
			'Accept: application/json',
		);
		$param = '';
		$mode = $method;
		if ($method == 'POST' || $method == 'PUT') {
			$headers[] = 'Content-Type: application/json';
			// An empty object, not an empty array, for requests with no parameters (e.g. cancel)
			$param = json_encode(empty($body) ? new stdClass() : $body);
			$mode = $method.'ALREADYFORMATED';
		}

		$url = ($this->live ? self::URL_LIVE : self::URL_SANDBOX).$path;
		$result = getURLContent($url, $mode, $param, 0, $headers, array('https'), 0, 1, 10, 30);
		$this->httpcode = (int) $result['http_code'];

		if (!empty($result['curl_error_no'])) {
			$this->error = 'Square API unreachable: '.$result['curl_error_msg'];
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR, 0, '_payment');
			return null;
		}

		$data = json_decode((string) $result['content'], true);
		if ($this->httpcode < 200 || $this->httpcode >= 300) {
			$messages = array();
			if (is_array($data) && !empty($data['errors']) && is_array($data['errors'])) {
				foreach ($data['errors'] as $err) {
					$messages[] = (empty($err['code']) ? '' : $err['code'].': ').(empty($err['detail']) ? '' : $err['detail']);
				}
			}
			$this->error = 'Square API error (HTTP '.$this->httpcode.')'.($messages ? ' '.implode('; ', $messages) : '');
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR, 0, '_payment');
			return null;
		}
		if (!is_array($data)) {
			$this->error = 'Square API returned an unreadable response (HTTP '.$this->httpcode.')';
			dol_syslog(__METHOD__.' '.$method.' '.$path.' '.$this->error, LOG_ERR, 0, '_payment');
			return null;
		}

		return $data;
	}

	/**
	 * A new idempotency key. Square keeps them for 24 hours, so a retried request is never run twice.
	 *
	 * @return string
	 */
	public static function newIdempotencyKey()
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * Active locations of the Square account
	 *
	 * @return ?array<int,array<string,mixed>>	Locations, or null on error
	 */
	public function listLocations()
	{
		$data = $this->request('GET', '/v2/locations');
		if ($data === null) {
			return null;
		}
		$locations = array();
		foreach ((empty($data['locations']) ? array() : $data['locations']) as $location) {
			if (empty($location['status']) || $location['status'] == 'ACTIVE') {
				$locations[] = $location;
			}
		}
		return $locations;
	}

	/**
	 * A location
	 *
	 * @param	string	$locationid	Location id (default: the one of the setup)
	 * @return	?array<string,mixed>
	 */
	public function getLocation($locationid = '')
	{
		$data = $this->request('GET', '/v2/locations/'.rawurlencode($locationid ? $locationid : $this->locationid));
		return ($data === null || empty($data['location'])) ? null : $data['location'];
	}

	/**
	 * Create a quick pay payment link (Checkout API)
	 *
	 * @param	string	$name			Item name shown on the Square checkout page
	 * @param	int		$amount			Amount in the smallest currency unit
	 * @param	string	$currency		ISO currency code
	 * @param	string	$redirecturl	Page the buyer is sent back to after paying
	 * @param	string	$note			Payment note, shown in the Square Dashboard
	 * @param	string	$buyeremail		Email to pre-fill on the checkout page
	 * @return	?array<string,mixed>	The payment_link object (id, url, order_id...), or null on error
	 */
	public function createPaymentLink($name, $amount, $currency, $redirecturl, $note = '', $buyeremail = '')
	{
		$body = array(
			'idempotency_key' => self::newIdempotencyKey(),
			'quick_pay' => array(
				'name' => dol_trunc($name, 255, 'right', 'UTF-8', 1),
				'price_money' => array('amount' => $amount, 'currency' => strtoupper($currency)),
				'location_id' => $this->locationid,
			),
			'checkout_options' => array(
				'redirect_url' => $redirecturl,
				// A tip would make the amount paid differ from the amount due, and be recorded on the invoice
				'allow_tipping' => false,
				'ask_for_shipping_address' => false,
			),
		);
		if ($note !== '') {
			$body['payment_note'] = dol_trunc($note, 500, 'right', 'UTF-8', 1);
		}
		if ($buyeremail !== '' && isValidEmail($buyeremail)) {
			$body['pre_populated_data'] = array('buyer_email' => $buyeremail);
		}
		$data = $this->request('POST', '/v2/online-checkout/payment-links', $body);
		if ($data === null || empty($data['payment_link']['url']) || empty($data['payment_link']['order_id'])) {
			if ($data !== null) {
				$this->error = 'Square did not return a payment link';
			}
			return null;
		}
		return $data['payment_link'];
	}

	/**
	 * Delete a payment link, so it can no longer be paid
	 *
	 * @param	string	$linkid		Payment link id
	 * @return	bool
	 */
	public function deletePaymentLink($linkid)
	{
		return $this->request('DELETE', '/v2/online-checkout/payment-links/'.rawurlencode($linkid)) !== null || $this->httpcode == 404;
	}

	/**
	 * An order
	 *
	 * @param	string	$orderid	Order id
	 * @return	?array<string,mixed>
	 */
	public function getOrder($orderid)
	{
		$data = $this->request('GET', '/v2/orders/'.rawurlencode($orderid));
		return ($data === null || empty($data['order'])) ? null : $data['order'];
	}

	/**
	 * A payment
	 *
	 * @param	string	$paymentid	Payment id
	 * @return	?array<string,mixed>
	 */
	public function getPayment($paymentid)
	{
		$data = $this->request('GET', '/v2/payments/'.rawurlencode($paymentid));
		return ($data === null || empty($data['payment'])) ? null : $data['payment'];
	}

	/**
	 * Payments that settle an order: its tenders whose payment is COMPLETED, in the given currency.
	 *
	 * The order of a quick pay link goes from DRAFT to OPEN when the buyer pays, with one tender per payment,
	 * so the tenders, each checked with GetPayment, are what proves the payment.
	 *
	 * @param	string	$orderid	Order id
	 * @param	string	$currency	Expected currency
	 * @return	?array{amount:int,paymentids:string[]}	Total paid and payment ids (amount 0 if unpaid), or null on error
	 */
	public function getOrderPaid($orderid, $currency)
	{
		$order = $this->getOrder($orderid);
		if ($order === null) {
			return null;
		}
		$paid = array('amount' => 0, 'paymentids' => array());
		foreach ((empty($order['tenders']) ? array() : $order['tenders']) as $tender) {
			$paymentid = empty($tender['payment_id']) ? (empty($tender['id']) ? '' : $tender['id']) : $tender['payment_id'];
			if ($paymentid === '') {
				continue;
			}
			$payment = $this->getPayment($paymentid);
			if ($payment === null) {
				return null;
			}
			if (self::isPaymentCompleted($payment, $currency, $orderid)) {
				$paid['amount'] += (int) $payment['amount_money']['amount'];
				$paid['paymentids'][] = $paymentid;
			}
		}
		return $paid;
	}

	/**
	 * Whether a payment is completed (captured), in the given currency, and for the given order
	 *
	 * @param	array<string,mixed>	$payment	Payment object
	 * @param	string				$currency	Expected currency
	 * @param	string				$orderid	Expected order id ('' to skip the check)
	 * @return	bool
	 */
	public static function isPaymentCompleted($payment, $currency, $orderid = '')
	{
		return !empty($payment['status']) && $payment['status'] == 'COMPLETED'
			&& isset($payment['amount_money']['amount'], $payment['amount_money']['currency'])
			&& strtoupper($payment['amount_money']['currency']) == strtoupper($currency)
			&& ($orderid === '' || (!empty($payment['order_id']) && $payment['order_id'] == $orderid));
	}

	/**
	 * Send a payment request to a Square Terminal (Terminal API)
	 *
	 * @param	string	$deviceid		Device id of a paired Terminal
	 * @param	int		$amount			Amount in the smallest currency unit
	 * @param	string	$currency		ISO currency code
	 * @param	string	$referenceid	Our reference (shown in the Square Dashboard)
	 * @param	string	$note			Note shown on the Terminal and in the Square Dashboard
	 * @return	?array<string,mixed>	The checkout object (id, status...), or null on error
	 */
	public function createTerminalCheckout($deviceid, $amount, $currency, $referenceid, $note = '')
	{
		$body = array(
			'idempotency_key' => self::newIdempotencyKey(),
			'checkout' => array(
				'amount_money' => array('amount' => $amount, 'currency' => strtoupper($currency)),
				'reference_id' => dol_trunc($referenceid, 40, 'right', 'UTF-8', 1),
				'device_options' => array(
					'device_id' => $deviceid,
					// A tip would make the amount paid differ from the amount due, and be recorded on the invoice
					'skip_receipt_screen' => false,
					'tip_settings' => array('allow_tipping' => false),
				),
				// Expires if nobody presents a card, so a forgotten request does not stay on the Terminal
				'deadline_duration' => 'PT5M',
			),
		);
		if ($note !== '') {
			$body['checkout']['note'] = dol_trunc($note, 250, 'right', 'UTF-8', 1);
		}
		$data = $this->request('POST', '/v2/terminals/checkouts', $body);
		return ($data === null || empty($data['checkout']['id'])) ? null : $data['checkout'];
	}

	/**
	 * A Terminal checkout
	 *
	 * @param	string	$checkoutid		Checkout id
	 * @return	?array<string,mixed>
	 */
	public function getTerminalCheckout($checkoutid)
	{
		$data = $this->request('GET', '/v2/terminals/checkouts/'.rawurlencode($checkoutid));
		return ($data === null || empty($data['checkout'])) ? null : $data['checkout'];
	}

	/**
	 * Cancel a Terminal checkout. Square refuses it once the card has been read.
	 *
	 * @param	string	$checkoutid		Checkout id
	 * @return	?array<string,mixed>	The checkout object, or null on error
	 */
	public function cancelTerminalCheckout($checkoutid)
	{
		$data = $this->request('POST', '/v2/terminals/checkouts/'.rawurlencode($checkoutid).'/cancel');
		return ($data === null || empty($data['checkout'])) ? null : $data['checkout'];
	}

	/**
	 * Create a device code, to pair a Square Terminal with this account (Devices API)
	 *
	 * @param	string	$name	Name of the Terminal, shown in the Square Dashboard
	 * @return	?array<string,mixed>	The device_code object (code, status, pair_by...), or null on error
	 */
	public function createDeviceCode($name)
	{
		$body = array(
			'idempotency_key' => self::newIdempotencyKey(),
			'device_code' => array(
				'name' => dol_trunc($name, 128, 'right', 'UTF-8', 1),
				'product_type' => 'TERMINAL_API',
				'location_id' => $this->locationid,
			),
		);
		$data = $this->request('POST', '/v2/devices/codes', $body);
		return ($data === null || empty($data['device_code'])) ? null : $data['device_code'];
	}

	/**
	 * Device codes of the location of the setup
	 *
	 * @param	string	$status		'PAIRED', 'UNPAIRED', 'EXPIRED' or '' for all
	 * @return	?array<int,array<string,mixed>>
	 */
	public function listDeviceCodes($status = '')
	{
		$codes = array();
		$cursor = '';
		do {
			$query = 'product_type=TERMINAL_API&location_id='.rawurlencode($this->locationid);
			if ($status !== '') {
				$query .= '&status='.rawurlencode($status);
			}
			if ($cursor !== '') {
				$query .= '&cursor='.rawurlencode($cursor);
			}
			$data = $this->request('GET', '/v2/devices/codes?'.$query);
			if ($data === null) {
				return null;
			}
			foreach ((empty($data['device_codes']) ? array() : $data['device_codes']) as $code) {
				$codes[] = $code;
			}
			$cursor = empty($data['cursor']) ? '' : (string) $data['cursor'];
		} while ($cursor !== '' && count($codes) < 500);
		return $codes;
	}
}
