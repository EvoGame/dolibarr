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
 * \file    infomatical/lib/infomatical.lib.php
 * \ingroup infomatical
 * \brief   Library functions of module Infomatical
 */

/**
 * Tabs of the module setup pages.
 *
 * @return array<array{string,string,string}>
 */
function infomaticalAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('infomatical@infomatical');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/infomatical/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/infomatical/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'infomatical@infomatical');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'infomatical@infomatical', 'remove');

	return $head;
}

/**
 * Among ticket ids, those the user could not open on the ticket card (entity, external user, sales representative).
 *
 * Core getObjectIdsRefusedToUser() exists from Dolibarr 25 only. For tickets it runs checkUserAccessToObject() on each
 * id, which is what the fallback does on Dolibarr 24.
 *
 * @param	User	$user	User
 * @param	int[]	$ids	Ticket ids
 * @return	int[]			Ids refused to the user
 */
function infomaticalTicketIdsRefusedToUser(User $user, array $ids)
{
	global $db;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';

	$ticketstatic = new Ticket($db);
	if (function_exists('getObjectIdsRefusedToUser')) {
		return getObjectIdsRefusedToUser($user, $ticketstatic, $ids);
	}

	$refusedids = array();
	foreach ($ids as $id) {
		$id = (int) $id;
		if ($id > 0 && !checkUserAccessToObject($user, array('ticket'), $id, $ticketstatic->table_element.'&'.$ticketstatic->element, '', 'fk_soc', 'rowid')) {
			$refusedids[] = $id;
		}
	}

	return $refusedids;
}

/**
 * Whether the AI module asks the user to approve a write before it runs (McpTool::writeConfirmationPreview()).
 *
 * Dolibarr 25 does. Dolibarr 24 runs every tool as soon as it is called, so the write tools are not offered there.
 *
 * @return	bool
 */
function infomaticalAiConfirmsWrites()
{
	require_once DOL_DOCUMENT_ROOT.'/ai/class/mcp.class.php';

	return method_exists('McpHandler', 'checkWriteConfirmation');
}

/**
 * First right the user lacks among those an AI tool declares in getRequiredRights().
 *
 * Dolibarr 25's McpHandler checks them before running a tool, Dolibarr 24's does not: the tools check them again
 * themselves, so they are enforced on both.
 *
 * @param	User							$user		User running the tool
 * @param	array<int,array<int,string>>|string	$required	Rights triples (module, permission, sub-permission)
 * @return	string									'' when allowed, otherwise the missing right as "module/permission"
 */
function infomaticalAiMissingRight(User $user, $required)
{
	if (!is_array($required)) {
		return 'undeclared';
	}
	foreach ($required as $right) {
		$module = (string) ($right[0] ?? '');
		$perm = (string) ($right[1] ?? '');
		$subperm = (string) ($right[2] ?? '');
		if ($module === '' || !$user->hasRight($module, $perm, $subperm)) {
			return $module.'/'.$perm.($subperm !== '' ? '/'.$subperm : '');
		}
	}

	return '';
}

/**
 * Name of the user extrafield holding the days a person works (ISO weekdays, 1 = Monday ... 7 = Sunday).
 */
const INFOMATICAL_WORKDAYS_FIELD = 'infomatical_workdays';

/**
 * Weekdays the business is normally closed, from core's MAIN_NON_WORKING_DAYS_INCLUDE_* (Leave module setup).
 * Core only lets Monday, Friday, Saturday and Sunday be closed; Saturday and Sunday are closed by default.
 *
 * @return int[] ISO weekdays, 1 = Monday ... 7 = Sunday
 */
function infomaticalClosedWeekdays()
{
	$closed = array();
	foreach (array(1 => array('MONDAY', 0), 5 => array('FRIDAY', 0), 6 => array('SATURDAY', 1), 7 => array('SUNDAY', 1)) as $weekday => $setting) {
		if (getDolGlobalInt('MAIN_NON_WORKING_DAYS_INCLUDE_'.$setting[0], $setting[1])) {
			$closed[] = $weekday;
		}
	}

	return $closed;
}

/**
 * Parse a working-days value as stored by a checkbox extrafield ("1,2,3" or an array).
 *
 * @param mixed $value Stored or posted value
 * @return int[] Sorted, unique ISO weekdays
 */
function infomaticalParseWeekdays($value)
{
	$days = array();
	foreach (is_array($value) ? $value : explode(',', (string) $value) as $day) {
		$day = (int) $day;
		if ($day >= 1 && $day <= 7) {
			$days[$day] = $day;
		}
	}
	sort($days);

	return $days;
}

/**
 * Days the user works, from the working-days field on their user card.
 *
 * @param User $user User
 * @return int[] ISO weekdays, empty when the field is not filled in
 */
function infomaticalUserWorkdays($user)
{
	if (!isset($user->array_options) || !is_array($user->array_options) || !array_key_exists('options_'.INFOMATICAL_WORKDAYS_FIELD, $user->array_options)) {
		$user->fetch_optionals();
	}

	return infomaticalParseWeekdays($user->array_options['options_'.INFOMATICAL_WORKDAYS_FIELD] ?? '');
}

/**
 * Whether a user may give another user working days on which the business is normally closed:
 * administrators, holders of the module permission, and anyone above the person in the hierarchy.
 *
 * @param User $editor   User making the change
 * @param int  $targetid User whose working days change
 * @return bool
 */
function infomaticalCanAuthoriseClosedDays(User $editor, $targetid)
{
	if (!empty($editor->admin) || $editor->hasRight('infomatical', 'closedworkdays')) {
		return true;
	}

	return $targetid > 0 && $targetid != $editor->id && in_array($targetid, $editor->getAllChildIds());
}

/**
 * Weekday names for messages, e.g. "Saturday, Sunday".
 *
 * @param int[]     $weekdays ISO weekdays
 * @param Translate $langs    Translations
 * @return string
 */
function infomaticalWeekdayNames(array $weekdays, $langs)
{
	$names = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');
	$out = array();
	foreach ($weekdays as $weekday) {
		$out[] = $langs->trans($names[$weekday]);
	}

	return implode(', ', $out);
}
