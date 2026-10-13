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
		dol_include_once('/infomatical/lib/infomatical.lib.php');
		dol_include_once('/infomatical/class/toolinfomaticaltickets.class.php');

		$tools = array(
			new ToolInfomaticalTickets($parameters['db'], $parameters['user'], $parameters['conf']),
		);
		// Without the AI module's write confirmation (Dolibarr 24), a write would run as soon as the model calls it
		if (infomaticalAiConfirmsWrites()) {
			dol_include_once('/infomatical/class/toolinfomaticalticketwrites.class.php');
			$tools[] = new ToolInfomaticalTicketWrites($parameters['db'], $parameters['user'], $parameters['conf']);
		}

		// One array of tools per module: HookManager merges results of all modules into resArray
		$this->results = array($tools);
		return 0;
	}

	/**
	 * Context 'usercard': on the user form, ask before a day the business is normally closed is added to the
	 * person's working days, or say straight away that it will be refused. The real check is in the module
	 * trigger (USER_CREATE, USER_MODIFY); this is only so nobody finds out after filling in the whole form.
	 *
	 * @param array<string,mixed>	$parameters	Hook parameters
	 * @param User					$object		User shown on the card
	 * @param string				$action		Action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int<0,0>
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!$this->isContext($parameters, 'usercard') || !in_array($action, array('create', 'edit'))) {
			return 0;
		}
		dol_include_once('/infomatical/lib/infomatical.lib.php');
		$langs->load('infomatical@infomatical');

		$names = array();
		foreach (infomaticalClosedWeekdays() as $weekday) {
			$names[$weekday] = infomaticalWeekdayNames(array($weekday), $langs);
		}
		if (empty($names)) {
			return 0;
		}
		$canauthorise = infomaticalCanAuthoriseClosedDays($user, (int) $object->id);
		$message = $langs->transnoentitiesnoconv($canauthorise ? 'InfomaticalConfirmClosedWorkday' : 'InfomaticalErrorClosedWorkdays');

		$this->resprints = '<script>
$(function () {
	var closed = '.json_encode($names, JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT).';
	var message = '.json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP).';
	$("#options_'.INFOMATICAL_WORKDAYS_FIELD.'").on("select2:selecting", function (e) {
		var name = closed[e.params.args.data.id];
		if (!name) {
			return;
		}
		'.($canauthorise ? 'if (!confirm(message.replace("%s", name))) { e.preventDefault(); }' : 'alert(message.replace("%s", name)); e.preventDefault();').'
	});
});
</script>';

		return 0;
	}
}
