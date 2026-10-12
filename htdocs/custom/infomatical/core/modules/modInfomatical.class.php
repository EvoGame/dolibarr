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
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'ticket';

		$this->module_parts = array(
			'triggers' => 0,
			// 'aimcp': addMcpTools hook of the AI module (htdocs/ai/class/mcp.class.php)
			'hooks' => array(
				'aimcp',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array();

		$this->hidden = false;
		$this->depends = array('modTicket');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('infomatical@infomatical');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(25, -3);	// Built against develop 25.0.0-alpha (AI addMcpTools hook)

		$this->const = array();

		if (!isModEnabled('infomatical')) {
			$conf->infomatical = new stdClass();
			$conf->infomatical->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();

		// Menus
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=ticket,fk_leftmenu=ticket',
			'type' => 'left',
			'titre' => 'InfomaticalMyQueue',
			'mainmenu' => 'ticket',
			'leftmenu' => 'infomatical_myqueue',
			'url' => '/infomatical/myqueue.php',
			'langs' => 'infomatical@infomatical',
			'position' => 106,
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
