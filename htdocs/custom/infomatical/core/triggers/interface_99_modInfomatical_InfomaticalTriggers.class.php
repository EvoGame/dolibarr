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
 * \file    infomatical/core/triggers/interface_99_modInfomatical_InfomaticalTriggers.class.php
 * \ingroup infomatical
 * \brief   Triggers of module Infomatical
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of module Infomatical
 */
class InterfaceInfomaticalTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		$this->family = "crm";
		$this->description = "Infomatical triggers: remove day-plan lines of deleted tickets.";
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'ticket';
	}

	/**
	 * Run a trigger.
	 *
	 * @param string       $action Event code
	 * @param CommonObject $object Object the event is about
	 * @param User         $user   User
	 * @param Translate    $langs  Translations
	 * @param Conf         $conf   Configuration
	 * @return int<-1,1> 0 if nothing done, 1 if OK, <0 if KO
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('infomatical')) {
			return 0;
		}

		switch ($action) {
			case 'TICKET_DELETE':
				dol_include_once('/infomatical/class/infomaticalticketplan.class.php');
				$plan = new InfomaticalTicketPlan($this->db);
				if ($plan->deleteForTicket($object->id) < 0) {
					$this->errors[] = $plan->error;
					return -1;
				}
				return 1;
		}

		return 0;
	}
}
