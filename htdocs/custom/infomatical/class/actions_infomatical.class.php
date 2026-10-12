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
 * \file    infomatical/class/actions_infomatical.class.php
 * \ingroup infomatical
 * \brief   Hook implementations of module Infomatical
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook class. Contexts are declared in modInfomatical::$module_parts['hooks'].
 */
class ActionsInfomatical extends CommonHookActions
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
	 * Context 'aimcp': register the module's AI tools.
	 *
	 * @param array<string,mixed>	$parameters	Hook parameters (db, user, conf)
	 * @param McpHandler			$object		MCP handler
	 * @param string				$action		Action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int<0,0>
	 */
	public function addMcpTools($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('ticket')) {
			return 0;
		}
		dol_include_once('/infomatical/class/toolinfomaticaltickets.class.php');
		dol_include_once('/infomatical/class/toolinfomaticalticketwrites.class.php');

		// One array of tools per module: HookManager merges results of all modules into resArray
		$this->results = array(
			array(
				new ToolInfomaticalTickets($parameters['db'], $parameters['user'], $parameters['conf']),
				new ToolInfomaticalTicketWrites($parameters['db'], $parameters['user'], $parameters['conf']),
			),
		);
		return 0;
	}
}
