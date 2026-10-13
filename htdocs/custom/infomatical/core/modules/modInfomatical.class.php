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
 * \defgroup   infomatical     Module Infomatical
 * \brief      Infomatical enhancements to the core Ticket module.
 *
 * \file       infomatical/core/modules/modInfomatical.class.php
 * \ingroup    infomatical
 * \brief      Description and activation file for module Infomatical
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Infomatical
 */
class modInfomatical extends DolibarrModules
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
		$this->numero = 591000;
		$this->rights_class = 'infomatical';
		$this->family = 'crm';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleInfomaticalDesc';
		$this->descriptionlong = 'ModuleInfomaticalDesc';
		$this->editor_name = 'Infomatical';
		$this->editor_url = '';
		$this->version = '0.4.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'ticket';

		$this->module_parts = array(
			// core/triggers: cleans day plans on TICKET_DELETE
			'triggers' => 1,
			// 'aimcp': addMcpTools hook of the AI module (htdocs/ai/class/mcp.class.php)
			// 'usercard': asks before a working day is set on a day the business is closed
			'hooks' => array(
				'aimcp',
				'usercard',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@infomatical');

		$this->hidden = false;
		$this->depends = array('modTicket');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('infomatical@infomatical');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(24, 0);	// AI write tools need 25 (see infomaticalAiConfirmsWrites())

		// Kept when the module is disabled, so an adjusted value survives a re-activation
		$this->const = array(
			1 => array('INFOMATICAL_WORKDAY_MINUTES', 'chaine', '450', 'Length of a technician working day, in minutes, for users with no weekly hours (day planning)', 1, 'current', 0),
			2 => array('INFOMATICAL_DEFAULT_PLAN_MINUTES', 'chaine', '60', 'Estimate given to a ticket added to a day plan, in minutes', 1, 'current', 0),
			3 => array('INFOMATICAL_PUBLIC_HOLIDAYS_CLOSE', 'chaine', '1', 'Public holidays close the business: no working time on them in day plans', 1, 'current', 0),
			4 => array('INFOMATICAL_CLOSEDDAY_POLICY', 'chaine', 'authorise', 'Working days on days the business is normally closed: authorise (needs authorisation) or confirm (confirmation only)', 1, 'current', 0),
			5 => array('INFOMATICAL_CLOSEDDAY_SUPERVISORS', 'chaine', '1', 'Supervisors may authorise working days on days the business is normally closed', 1, 'current', 0),
		);

		if (!isModEnabled('infomatical')) {
			$conf->infomatical = new stdClass();
			$conf->infomatical->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->rights[0][0] = $this->numero * 100 + 1;
		$this->rights[0][1] = 'Authorise working days on days the business is normally closed';
		$this->rights[0][3] = 0;
		$this->rights[0][4] = 'closedworkdays';
		$this->rights[0][5] = '';

		// Menus
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=ticket,fk_leftmenu=ticket',
			'type' => 'left',
			'titre' => 'InfomaticalPlanMyDay',
			'mainmenu' => 'ticket',
			'leftmenu' => 'infomatical_plan',
			'url' => '/infomatical/plan.php',
			'langs' => 'infomatical@infomatical',
			'position' => 106,
			'enabled' => 'isModEnabled("infomatical")',
			'perms' => '$user->hasRight("ticket", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=ticket,fk_leftmenu=ticket',
			'type' => 'left',
			'titre' => 'InfomaticalMyQueue',
			'mainmenu' => 'ticket',
			'leftmenu' => 'infomatical_myqueue',
			'url' => '/infomatical/myqueue.php',
			'langs' => 'infomatical@infomatical',
			'position' => 107,
			'enabled' => 'isModEnabled("infomatical")',
			'perms' => '$user->hasRight("ticket", "read")',
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
		$result = $this->_load_tables('/infomatical/sql/');
		if ($result <= 0) {
			return -1;
		}

		// Days the person works, on the user card. Kept when the module is disabled, like the constants.
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$weekdays = array('1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday');
		$result = $extrafields->addExtraField('infomatical_workdays', 'InfomaticalWorkingDays', 'checkbox', 100, '', 'user', 0, 0, '', array('options' => $weekdays), 1, '', '1', 'InfomaticalWorkingDaysHelp', '', '', 'infomatical@infomatical', 'isModEnabled("infomatical")');
		if ($result < 0) {
			$this->error = $extrafields->error;
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
