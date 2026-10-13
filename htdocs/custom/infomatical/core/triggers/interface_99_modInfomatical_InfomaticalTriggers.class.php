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
		$this->description = "Infomatical triggers: remove day-plan lines of deleted tickets, check working days set on days the business is closed.";
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

			case 'USER_CREATE':
			case 'USER_MODIFY':
				return $this->checkClosedWorkdays($action, $object, $user, $langs);
		}

		return 0;
	}

	/**
	 * Refuse working days on which the business is normally closed, unless the user making the change may
	 * authorise them (see infomaticalCanAuthoriseClosedDays()). Only days added by this change are checked,
	 * so days authorised earlier survive later edits by anyone.
	 *
	 * On USER_MODIFY the days before the change come from $object->oldcopy, which the user card sets. Callers
	 * that do not set it (the REST API, for example) are not checked: there is no way to tell what changed.
	 *
	 * @param string    $action USER_CREATE or USER_MODIFY
	 * @param User      $object User being saved
	 * @param User      $user   User making the change
	 * @param Translate $langs  Translations
	 * @return int<-1,1> 0 if nothing to check, 1 if OK, -1 if refused
	 */
	private function checkClosedWorkdays($action, $object, User $user, Translate $langs)
	{
		dol_include_once('/infomatical/lib/infomatical.lib.php');

		$key = 'options_'.INFOMATICAL_WORKDAYS_FIELD;
		if (!is_array($object->array_options) || !array_key_exists($key, $object->array_options)) {
			return 0;
		}
		if ($action == 'USER_MODIFY' && !is_object($object->oldcopy)) {
			return 0;
		}

		$before = $action == 'USER_CREATE' ? array() : infomaticalParseWeekdays($object->oldcopy->array_options[$key] ?? '');
		$added = array_values(array_intersect(array_diff(infomaticalParseWeekdays($object->array_options[$key]), $before), infomaticalClosedWeekdays()));
		if (empty($added)) {
			return 0;
		}

		$langs->load('infomatical@infomatical');
		$days = infomaticalWeekdayNames($added, $langs);
		if (infomaticalCanAuthoriseClosedDays($user, (int) $object->id)) {
			dol_syslog('Infomatical: '.$user->login.' authorised working days on which the business is closed ('.$days.') for user id '.$object->id, LOG_NOTICE);
			return 1;
		}

		$this->errors[] = $langs->trans('InfomaticalErrorClosedWorkdays', $days);
		return -1;
	}
}
