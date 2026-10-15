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
 * \file    square/lib/square.lib.php
 * \ingroup square
 * \brief   Library functions of module Square
 */

/**
 * Tabs of the module setup pages.
 *
 * @return array<array{string,string,string}>
 */
function squareAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('square@square');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/square/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/square/admin/terminals.php', 1);
	$head[$h][1] = $langs->trans('SquareTerminals');
	$head[$h][2] = 'terminals';
	$h++;

	$head[$h][0] = dol_buildpath('/square/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'square@square');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'square@square', 'remove');

	return $head;
}

/**
 * Number of decimals Square uses for a currency (amounts are sent in the smallest unit)
 *
 * @param	string	$currency	ISO currency code
 * @return	int
 */
function squareCurrencyDecimals($currency)
{
	// Of the currencies Square accounts can use, only JPY has no minor unit
	return in_array(strtoupper($currency), array('JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XAF', 'XOF', 'XPF')) ? 0 : 2;
}

/**
 * Amount in the smallest currency unit, as Square expects it
 *
 * @param	float|string	$amount		Amount (e.g. 12.50)
 * @param	string			$currency	ISO currency code
 * @return	int							Amount in minor units (e.g. 1250)
 */
function squareToMinor($amount, $currency)
{
	return (int) round((float) price2num($amount, 'MT') * pow(10, squareCurrencyDecimals($currency)));
}

/**
 * Amount from the smallest currency unit
 *
 * @param	int		$minor		Amount in minor units (e.g. 1250)
 * @param	string	$currency	ISO currency code
 * @return	float				Amount (e.g. 12.5)
 */
function squareFromMinor($minor, $currency)
{
	return (float) price2num($minor / pow(10, squareCurrencyDecimals($currency)), 'MT');
}

/**
 * Id of the payment mode (llx_c_paiement) to record Square payments with: the one of the setup, else card (CB)
 *
 * @return int
 */
function squarePaymentModeId()
{
	global $db;

	$id = getDolGlobalInt('SQUARE_PAYMENT_MODE_FOR_PAYMENTS');
	if ($id <= 0) {
		$id = (int) dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
	}
	return $id;
}

/**
 * Whether the module can take payments in the current environment, with the reason when it cannot
 *
 * @return string	'' when ready, else a translated message
 */
function squareSetupProblem()
{
	global $langs;

	$langs->load('square@square');
	$env = getDolGlobalInt('SQUARE_LIVE') ? 'LIVE' : 'SANDBOX';
	if (!getDolGlobalString('SQUARE_'.$env.'_ACCESS_TOKEN') || !getDolGlobalString('SQUARE_'.$env.'_LOCATION_ID')) {
		return $langs->trans('SquareErrorNotConfigured', $langs->transnoentitiesnoconv($env == 'LIVE' ? 'SquareEnvLive' : 'SquareEnvSandbox'));
	}
	if (isModEnabled('bank') && getDolGlobalInt('SQUARE_BANK_ACCOUNT_FOR_PAYMENTS') <= 0) {
		return $langs->trans('SquareErrorNoBankAccount');
	}
	return '';
}

/**
 * Whether Dolibarr can send SMS (Setup > SMS, with an SMS module such as OVH)
 *
 * @return bool
 */
function squareCanSendSms()
{
	return getDolGlobalString('MAIN_SMS_SENDMODE') !== '' && !getDolGlobalString('MAIN_DISABLE_ALL_SMS');
}

/**
 * Device id of the Square Terminal to use: the one of the TakePOS terminal if set, else the default one
 *
 * @param	int|string	$takeposterminal	TakePOS terminal number ('' outside TakePOS)
 * @return	string
 */
function squareDeviceId($takeposterminal = '')
{
	$env = getDolGlobalInt('SQUARE_LIVE') ? 'LIVE' : 'SANDBOX';
	if ((string) $takeposterminal !== '' && getDolGlobalString('SQUARE_'.$env.'_DEVICE_ID_TAKEPOS'.$takeposterminal)) {
		return getDolGlobalString('SQUARE_'.$env.'_DEVICE_ID_TAKEPOS'.$takeposterminal);
	}
	return getDolGlobalString('SQUARE_'.$env.'_DEVICE_ID');
}

/**
 * Why the Square Terminal button of TakePOS cannot be used on a till, if it cannot
 *
 * @param	?string		$terminal	TakePOS till number (null: the till of the session)
 * @return	string					'' when ready, else a translated message
 */
function squareTakeposProblem($terminal = null)
{
	global $db, $langs;

	$langs->load('square@square');
	if ($terminal === null) {
		$terminal = empty($_SESSION['takeposterminal']) ? '' : (string) $_SESSION['takeposterminal'];
	}
	if (squareDeviceId($terminal) === '') {
		return $langs->trans('SquareNoTerminal');
	}
	// TakePOS records the payment in the bank account its till setup gives the payment mode
	if (isModEnabled('bank') && !getDolGlobalString('TAKEPOS_CAN_FORCE_BANK_ACCOUNT_DURING_PAYMENT')) {
		$code = (string) dol_getIdFromCode($db, squarePaymentModeId(), 'c_paiement', 'id', 'code', 1);
		$key = ($code == 'LIQ' ? 'CASH' : ($code == 'CHQ' ? 'CHEQUE' : $code));
		if (getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_'.$key.$terminal) <= 0) {
			return $langs->trans('SquareErrorTakeposBank', $langs->transnoentitiesnoconv('PaymentTypeShort'.$code), $terminal);
		}
	}
	return '';
}
