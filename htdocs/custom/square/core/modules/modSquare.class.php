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
 * \defgroup   square     Module Square
 * \brief      Square (squareup.com) online payment links and Square Terminal in-person payments.
 *
 * \file       square/core/modules/modSquare.class.php
 * \ingroup    square
 * \brief      Description and activation file for module Square
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Square
 */
class modSquare extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// 500000-599999 is the range for modules not published on DoliStore (see docs/maintenance.md)
		$this->numero = 591100;
		$this->rights_class = 'square';
		$this->family = 'interface';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleSquareDesc';
		$this->descriptionlong = 'ModuleSquareDesc';
		$this->editor_name = 'Infomatical';
		$this->editor_url = '';
		$this->version = '0.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-credit-card';

		$this->module_parts = array(
			// 'newpayment': Square button and checkout on the public payment pages (public/payment/newpayment.php, paymentok.php)
			// 'invoicecard': "Take payment on Square Terminal" and "Send Square payment link" on the customer invoice card
			// 'takepospay': the same two as payment buttons of TakePOS (takepos/pay.php)
			'hooks' => array(
				'newpayment',
				'invoicecard',
				'takepospay',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@square');

		$this->hidden = false;
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('square@square');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(24, 0);

		// Kept when the module is disabled, so the setup survives a re-activation
		$this->const = array(
			1 => array('SQUARE_LIVE', 'chaine', '0', 'Use the Square production environment (1) or the sandbox (0)', 0, 'current', 0),
		);

		if (!isModEnabled('square')) {
			$conf->square = new stdClass();
			$conf->square->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Records payments that were taken on Square but not yet recorded in Dolibarr (customer closed the
		// browser before returning, terminal page closed before the payment completed...)
		$this->cronjobs = array(
			0 => array(
				'label' => 'SquareCronReconcile',
				'jobtype' => 'method',
				'class' => '/square/class/squaretransaction.class.php',
				'objectname' => 'SquareTransaction',
				'method' => 'cronReconcile',
				'parameters' => '',
				'comment' => 'SquareCronReconcileHelp',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("square")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero * 100 + 1;
		$this->rights[$r][1] = 'See Square payment links and terminal payments';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + 2;
		$this->rights[$r][1] = 'Take in-person card payments on a Square Terminal';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'terminal';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + 3;
		$this->rights[$r][1] = 'Send Square payment links to customers by email or SMS';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'sendlink';
		$this->rights[$r][5] = '';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills_payment',
			'type' => 'left',
			'titre' => 'SquareTransactions',
			'mainmenu' => 'billing',
			'leftmenu' => 'square_list',
			'url' => '/square/list.php',
			'langs' => 'square@square',
			'position' => 1100,
			'enabled' => 'isModEnabled("square")',
			'perms' => '$user->hasRight("square", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		// _load_tables() returns 0, not a negative value, when an SQL file fails
		$result = $this->_load_tables('/square/sql/');
		if ($result <= 0) {
			return -1;
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
