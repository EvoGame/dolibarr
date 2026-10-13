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
 * \file    infomatical/admin/setup.php
 * \ingroup infomatical
 * \brief   Settings of module Infomatical: day planning, working days, and status of the AI tools
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var Societe $mysoc
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
dol_include_once('/infomatical/lib/infomatical.lib.php');
dol_include_once('/infomatical/class/infomaticalticketplan.class.php');

$langs->loadLangs(array('admin', 'holiday', 'infomatical@infomatical'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Tools registered by ActionsInfomatical::addMcpTools(), in the order shown, with whether they write
$aitools = array(
	'infomatical_my_open_tickets' => false,
	'infomatical_ticket_assign' => true,
	'infomatical_ticket_set_status' => true,
	'infomatical_ticket_update' => true,
	'infomatical_ticket_add_note' => true,
);


/*
 * Actions
 */

if ($action == 'update') {
	$workday = GETPOSTINT('workdayhour') * 60 + GETPOSTINT('workdaymin');
	$estimate = GETPOSTINT('estimatehour') * 60 + GETPOSTINT('estimatemin');
	$policy = GETPOST('closeddaypolicy', 'aZ09') == 'confirm' ? 'confirm' : 'authorise';

	$error = 0;
	if ($workday < 15 || $workday > 24 * 60) {
		setEventMessages($langs->trans('InfomaticalErrorWorkdayLength'), null, 'errors');
		$error++;
	}
	if ($estimate < 0 || $estimate > InfomaticalTicketPlan::MAX_DURATION) {
		setEventMessages($langs->trans('InfomaticalErrorDefaultEstimate'), null, 'errors');
		$error++;
	}
	if (!$error) {
		$db->begin();
		if (dolibarr_set_const($db, 'INFOMATICAL_WORKDAY_MINUTES', (string) $workday, 'chaine', 0, '', $conf->entity) > 0
			&& dolibarr_set_const($db, 'INFOMATICAL_DEFAULT_PLAN_MINUTES', (string) $estimate, 'chaine', 0, '', $conf->entity) > 0
			&& dolibarr_set_const($db, 'INFOMATICAL_PUBLIC_HOLIDAYS_CLOSE', (string) (GETPOSTINT('publicholidaysclose') ? 1 : 0), 'chaine', 0, '', $conf->entity) > 0
			&& dolibarr_set_const($db, 'INFOMATICAL_CLOSEDDAY_POLICY', $policy, 'chaine', 0, '', $conf->entity) > 0
			&& dolibarr_set_const($db, 'INFOMATICAL_CLOSEDDAY_SUPERVISORS', (string) (GETPOSTINT('closeddaysupervisors') ? 1 : 0), 'chaine', 0, '', $conf->entity) > 0) {
			$db->commit();
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		$db->rollback();
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('InfomaticalSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-infomatical page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print dol_get_fiche_head(infomaticalAdminPrepareHead(), 'settings', $title, -1, 'ticket');

// Day planning
$workday = getDolGlobalInt('INFOMATICAL_WORKDAY_MINUTES', InfomaticalTicketPlan::DEFAULT_WORKDAY_MINUTES);
$estimate = getDolGlobalInt('INFOMATICAL_DEFAULT_PLAN_MINUTES', InfomaticalTicketPlan::DEFAULT_PLAN_MINUTES);
$publicholidaysclose = getDolGlobalInt('INFOMATICAL_PUBLIC_HOLIDAYS_CLOSE', 1);
$policy = infomaticalClosedDayPolicy();
$supervisors = getDolGlobalInt('INFOMATICAL_CLOSEDDAY_SUPERVISORS', 1);
if ($action == 'update') {
	// Redisplay what was typed after a validation error
	$workday = GETPOSTINT('workdayhour') * 60 + GETPOSTINT('workdaymin');
	$estimate = GETPOSTINT('estimatehour') * 60 + GETPOSTINT('estimatemin');
	$publicholidaysclose = GETPOSTINT('publicholidaysclose');
	$policy = GETPOST('closeddaypolicy', 'aZ09') == 'confirm' ? 'confirm' : 'authorise';
	$supervisors = GETPOSTINT('closeddaysupervisors');
}

print load_fiche_titre($langs->trans('InfomaticalPlanMyDay'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InfomaticalWorkdayLength'), $langs->trans('InfomaticalWorkdayLengthHelp')).'</td>';
print '<td class="nowraponall">'.$form->select_duration('workday', $workday * 60, 0, 'text', 0, 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InfomaticalDefaultEstimate'), $langs->trans('InfomaticalDefaultEstimateHelp')).'</td>';
print '<td class="nowraponall">'.$form->select_duration('estimate', $estimate * 60, 0, 'text', 0, 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InfomaticalPublicHolidaysClose'), $langs->trans('InfomaticalPublicHolidaysCloseHelp')).'</td>';
print '<td>'.$form->selectyesno('publicholidaysclose', $publicholidaysclose, 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InfomaticalClosedDayPolicy'), $langs->trans('InfomaticalClosedDayPolicyHelp')).'</td>';
print '<td>'.$form->selectarray('closeddaypolicy', array('authorise' => $langs->trans('InfomaticalClosedDayPolicyAuthorise'), 'confirm' => $langs->trans('InfomaticalClosedDayPolicyConfirm')), $policy, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InfomaticalClosedDaySupervisors'), $langs->trans('InfomaticalClosedDaySupervisorsHelp')).'</td>';
print '<td>'.$form->selectyesno('closeddaysupervisors', $supervisors, 1).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dolPrintHTMLForAttribute($langs->trans('Save')).'"></div>';
print '</form>';

// Working days: read-only status of what the day plan relies on, most of which is set up outside this module
print '<br>';
print load_fiche_titre($langs->trans('InfomaticalWorkingDaysStatus'), '', '');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('InfomaticalWorkingDaysStatusHelp').'</div>';

$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label('user');
$fieldexists = !empty($extrafields->attributes['user']['type'][INFOMATICAL_WORKDAYS_FIELD]);

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';

// The extrafield, created by modInfomatical::init()
print '<tr class="oddeven"><td>'.$langs->trans('InfomaticalWorkingDaysField').'</td><td>';
if ($fieldexists) {
	print dolGetBadge($langs->trans('InfomaticalStatusOk'), '', 'status4');
} else {
	print dolGetBadge($langs->trans('InfomaticalStatusMissing'), '', 'status8').' '.$langs->trans('InfomaticalWorkingDaysFieldMissing');
}
print '</td><td class="right"><a href="'.DOL_URL_ROOT.'/admin/extrafields.php?elementtype=user">'.$langs->trans('InfomaticalChange').'</a></td></tr>';

// Days the business is normally closed: Leave module setup
$closed = infomaticalClosedWeekdays();
print '<tr class="oddeven"><td>'.$langs->trans('InfomaticalBusinessClosedDays').'</td><td>';
print $closed ? infomaticalWeekdayNames($closed, $langs) : $langs->trans('None');
if (!isModEnabled('holiday')) {
	print '<br><span class="opacitymedium">'.$langs->trans('InfomaticalBusinessClosedDaysNoLeave').'</span>';
}
print '</td><td class="right">';
if (isModEnabled('holiday')) {
	print '<a href="'.DOL_URL_ROOT.'/admin/holiday.php">'.$langs->trans('InfomaticalChange').'</a>';
}
print '</td></tr>';

// Public holidays for the company's country, counted the way num_public_holiday() reads them
print '<tr class="oddeven"><td>'.$langs->trans('InfomaticalPublicHolidays').'</td><td>';
if (!getDolGlobalInt('INFOMATICAL_PUBLIC_HOLIDAYS_CLOSE', 1)) {
	print '<span class="opacitymedium">'.$langs->trans('InfomaticalPublicHolidaysNotUsed').'</span>';
} else {
	$countryid = (int) $mysoc->country_id;
	$nbholidays = 0;
	$sql = "SELECT COUNT(id) as nb FROM ".MAIN_DB_PREFIX."c_hrm_public_holiday";
	$sql .= " WHERE active = 1 AND fk_country IN (0".($countryid > 0 ? ", ".$countryid : "").")";
	$sql .= " AND entity IN (0, ".getEntity('holiday').")";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$nbholidays = (int) $obj->nb;
	}
	if ($nbholidays > 0) {
		print dolGetBadge($langs->trans('InfomaticalStatusOk'), '', 'status4').' '.$langs->trans('InfomaticalPublicHolidaysCount', $nbholidays, $mysoc->country_code);
	} else {
		print dolGetBadge($langs->trans('InfomaticalStatusNone'), '', 'status1').' '.$langs->trans('InfomaticalPublicHolidaysNone', $mysoc->country_code);
	}
	if (!isModEnabled('holiday') && !isModEnabled('hrm')) {
		print '<br><span class="opacitymedium">'.$langs->trans('InfomaticalPublicHolidaysNoModule').'</span>';
	}
}
print '</td><td class="right">';
if (isModEnabled('holiday') || isModEnabled('hrm')) {
	// 32 is DICT_HRM_PUBLIC_HOLIDAY, only defined inside admin/dict.php
	print '<a href="'.DOL_URL_ROOT.'/admin/dict.php?id=32">'.$langs->trans('InfomaticalChange').'</a>';
}
print '</td></tr>';

// Active internal users, and how many have their own weekly hours and working days
$nbusers = $nbhours = $nbdays = 0;
$sql = "SELECT COUNT(u.rowid) as nb, SUM(CASE WHEN u.weeklyhours > 0 THEN 1 ELSE 0 END) as nbhours";
if ($fieldexists) {
	$sql .= ", SUM(CASE WHEN ef.".INFOMATICAL_WORKDAYS_FIELD." IS NOT NULL AND ef.".INFOMATICAL_WORKDAYS_FIELD." <> '' THEN 1 ELSE 0 END) as nbdays";
}
$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
if ($fieldexists) {
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user_extrafields as ef ON ef.fk_object = u.rowid";
}
$sql .= " WHERE u.statut = 1 AND (u.fk_soc IS NULL OR u.fk_soc = 0) AND u.entity IN (".getEntity('user').")";
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbusers = (int) $obj->nb;
	$nbhours = (int) $obj->nbhours;
	$nbdays = $fieldexists ? (int) $obj->nbdays : 0;
}
print '<tr class="oddeven"><td>'.$langs->trans('InfomaticalUsersStatus').'</td><td>';
print $langs->trans('InfomaticalUsersStatusCount', $nbusers, $nbhours, $nbdays);
print '</td><td class="right"><a href="'.DOL_URL_ROOT.'/user/list.php">'.$langs->trans('InfomaticalChange').'</a></td></tr>';

print '</table>';
print '</div>';

// AI tools: what the module provides, and whether the AI module's allow-lists let them through
print '<br>';
print load_fiche_titre($langs->trans('InfomaticalAiTools'), '', '');
if (!isModEnabled('ai')) {
	print '<div class="info">'.$langs->trans('InfomaticalAiModuleDisabled').'</div>';
} else {
	require_once DOL_DOCUMENT_ROOT.'/ai/class/mcp.class.php';

	$toolnames = array_keys($aitools);
	$allowed = array(
		'assistant' => McpHandler::resolveAllowList(getDolGlobalString('AI_ASSISTANT_ALLOWED_TOOLS'), $toolnames),
		'mcp' => McpHandler::resolveAllowList(getDolGlobalString('AI_MCP_SERVER_ALLOWED_TOOLS'), $toolnames),
	);

	$writesavailable = infomaticalAiConfirmsWrites();

	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('InfomaticalAiToolsHelp').'</div>';
	if (!$writesavailable) {
		print '<div class="warning">'.$langs->trans('InfomaticalAiWritesUnavailable').'</div>';
	}
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('InfomaticalAiTool').'</td>';
	print '<td>'.$langs->trans('Description').'</td>';
	print '<td class="center">'.$langs->trans('Assistant').'</td>';
	print '<td class="center">'.$langs->trans('MCPServer').'</td>';
	print '</tr>';
	foreach ($aitools as $name => $writes) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall"><code>'.$name.'</code></td>';
		print '<td>'.$langs->trans('InfomaticalToolDesc_'.$name);
		if ($writes) {
			print ' <span class="opacitymedium">('.$langs->trans('InfomaticalToolConfirmed').')</span>';
		}
		print '</td>';
		foreach (array('assistant', 'mcp') as $context) {
			if ($writes && !$writesavailable) {
				print '<td class="center">'.dolGetBadge($langs->trans('InfomaticalNotAvailable'), '', 'status5').'</td>';
				continue;
			}
			$ok = in_array($name, $allowed[$context], true);
			print '<td class="center">'.dolGetBadge($langs->trans($ok ? 'InfomaticalAllowed' : 'InfomaticalNotAllowed'), '', $ok ? 'status4' : 'status8').'</td>';
		}
		print '</tr>';
	}
	print '</table>';
	print '</div>';
	print '<div class="right">';
	print '<a class="button" href="'.dol_buildpath('/ai/admin/configure_tools.php', 1).'">'.$langs->trans('ToolAccessControl').'</a>';
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
