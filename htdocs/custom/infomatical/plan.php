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
 * \file    infomatical/plan.php
 * \ingroup infomatical
 * \brief   Technician day planning: order the tickets of a day, with estimates, from the user's queue
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/infomatical/class/infomaticalticketqueue.class.php');
dol_include_once('/infomatical/class/infomaticalticketplan.class.php');

$langs->loadLangs(array('ticket', 'companies', 'infomatical@infomatical'));

// Security check: internal users with ticket read permission only, each on their own plan
if (!isModEnabled('infomatical') || !isModEnabled('ticket')) {
	accessforbidden('Module not enabled');
}
if ($user->socid > 0 || !$user->hasRight('ticket', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$planid = GETPOSTINT('planid');

// Day shown: ?day=YYYY-MM-DD, or the date selector, or today in the user's timezone
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzuserrel');
$day = InfomaticalTicketPlan::normaliseDay(GETPOST('day', 'alphanohtml'));
if ($day === '' && GETPOSTINT('planyear') > 0) {
	$day = InfomaticalTicketPlan::normaliseDay(sprintf('%04d-%02d-%02d', GETPOSTINT('planyear'), GETPOSTINT('planmonth'), GETPOSTINT('planday')));
}
if ($day === '') {
	$day = $today;
}

$workday = InfomaticalTicketPlan::getWorkday($user, $day);
$workdayminutes = $workday['minutes'];
$defaultminutes = getDolGlobalInt('INFOMATICAL_DEFAULT_PLAN_MINUTES', InfomaticalTicketPlan::DEFAULT_PLAN_MINUTES);

$plan = new InfomaticalTicketPlan($db);
$queue = new InfomaticalTicketQueue($db);
$backurl = $_SERVER['PHP_SELF'].'?day='.urlencode($day);


/*
 * Actions
 */

$error = 0;
if ($action == 'add') {
	// Only tickets of the user's own queue can be planned
	$ticketid = GETPOSTINT('ticketid');
	$queuerows = $queue->fetchOpenForUser($user);
	if (!is_array($queuerows) || !in_array($ticketid, array_column($queuerows, 'id'))) {
		setEventMessages($langs->trans('InfomaticalErrorNotInQueue'), null, 'errors');
		$error++;
	} elseif ($plan->add($user, array($ticketid), $day, GETPOSTINT('minutes')) < 0) {
		setEventMessages($plan->error, null, 'errors');
		$error++;
	}
} elseif ($action == 'carryover') {
	$carry = $plan->fetchCarryOver($user, $day);
	if (!is_array($carry) || $plan->add($user, $carry['ticketids'], $day, $defaultminutes, $carry['day']) < 0) {
		setEventMessages($plan->error, null, 'errors');
		$error++;
	}
} elseif ($action == 'update') {
	if ($plan->setDetails($user, $planid, GETPOSTINT('minutes'), GETPOST('note', 'alphanohtml')) < 0) {
		setEventMessages($plan->error, null, 'errors');
		$error++;
	}
} elseif ($action == 'moveup' || $action == 'movedown') {
	if ($plan->move($user, $planid, $action == 'moveup' ? 'up' : 'down') < 0) {
		setEventMessages($plan->error, null, 'errors');
		$error++;
	}
} elseif ($action == 'remove') {
	if ($plan->remove($user, $planid) < 0) {
		setEventMessages($plan->error, null, 'errors');
		$error++;
	}
}
if ($action && !$error) {
	header('Location: '.$backurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$ticketstatic = new Ticket($db);
$socstatic = new Societe($db);

$planned = $plan->fetchDay($user, $day);
$queuerows = $queue->fetchOpenForUser($user);
$carry = $plan->fetchCarryOver($user, $day);
foreach (array($planned, $queuerows, $carry) as $result) {
	if (!is_array($result)) {
		setEventMessages($plan->error ?: $queue->error, null, 'errors');
	}
}
$planned = is_array($planned) ? $planned : array();
$queuerows = is_array($queuerows) ? $queuerows : array();
$carry = is_array($carry) ? $carry : array('day' => '', 'ticketids' => array());

$plannedids = array_column($planned, 'id');
$unplanned = array_values(array_filter($queuerows, static function ($row) use ($plannedids) {
	return !in_array($row['id'], $plannedids);
}));
$lastplanned = $plan->fetchLastPlannedBefore($user, array_column($unplanned, 'id'), $day);
$lastplanned = is_array($lastplanned) ? $lastplanned : array();

// Severity labels from the dictionary
$ticketstatic->loadCacheSeveritiesTickets();
$severitylabels = array();
foreach ((array) ($conf->cache['severity_tickets'] ?? array()) as $sev) {
	$severitylabels[$sev['code']] = $sev['label'];
}

$totalminutes = 0;
$openminutes = 0;
$nbdone = 0;
foreach ($planned as $row) {
	$totalminutes += $row['duration'];
	if (in_array($row['status'], array(Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELED), true)) {
		$nbdone++;
	} else {
		$openminutes += $row['duration'];
	}
}

/**
 * Noon of a 'YYYY-MM-DD' day in the user's timezone, for display functions.
 *
 * @param string $day Day
 * @return int
 */
function infomaticalDayToTime($day)
{
	return (int) dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4), 'tzuserrel');
}

/**
 * Minutes as hours and minutes, e.g. "7h30".
 *
 * @param int $minutes Minutes
 * @return string
 */
function infomaticalFormatMinutes($minutes)
{
	return sprintf('%dh%02d', intdiv(abs($minutes), 60), abs($minutes) % 60);
}

$daytime = infomaticalDayToTime($day);
$prevday = (new DateTime($day))->modify('-1 day')->format('Y-m-d');
$nextday = (new DateTime($day))->modify('+1 day')->format('Y-m-d');

$title = $langs->trans('InfomaticalPlanMyDay');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-infomatical page-plan');

// Day navigation
$nav = '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly">';
$nav .= '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?day='.$prevday.'" title="'.dolPrintHTMLForAttribute($langs->trans('Previous')).'">'.img_previous().'</a>';
$nav .= $form->selectDate($daytime, 'plan', 0, 0, 0, '', 1, 0);
$nav .= ' <input type="submit" class="button smallpaddingimp" value="'.dolPrintHTMLForAttribute($langs->trans('Show')).'">';
$nav .= '<a class="paddingleft" href="'.$_SERVER['PHP_SELF'].'?day='.$nextday.'" title="'.dolPrintHTMLForAttribute($langs->trans('Next')).'">'.img_next().'</a>';
if ($day !== $today) {
	$nav .= ' <a class="paddingleft" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Today').'</a>';
}
$nav .= '</form>';

print load_fiche_titre($title.' - '.dol_print_date($daytime, 'daytext', 'tzuserrel'), '', 'ticket');
print $nav;

// Not a working day: say why before the summary
if ($workday['status'] == 'publicholiday') {
	print '<div class="warning marginbottomonly">'.$langs->trans('InfomaticalPlanPublicHoliday').'</div>';
} elseif ($workday['status'] == 'dayoff') {
	$weekdayname = infomaticalWeekdayNames(array((int) (new DateTime($day))->format('N')), $langs);
	print '<div class="warning marginbottomonly">'.$langs->trans($workday['userdays'] ? 'InfomaticalPlanNotYourWorkingDay' : 'InfomaticalPlanBusinessClosedDay', $weekdayname).'</div>';
}

// Summary of the day
$free = $workdayminutes - $totalminutes;
print '<div class="info marginbottomonly">';
if ($workday['source'] == 'user') {
	$workdayhelp = $langs->trans('InfomaticalWorkdayFromUser', price($workday['weeklyhours'], 1, $langs, 0, 0), $workday['days']);
} elseif ($workday['weeklyhours'] > 0) {
	$workdayhelp = $langs->trans('InfomaticalWorkdayUserOutOfRange', price($workday['weeklyhours'], 1, $langs, 0, 0));
} else {
	$workdayhelp = $langs->trans('InfomaticalWorkdayFromGlobal');
}
$workdays = $workday['userdays'] ? infomaticalUserWorkdays($user) : array_values(array_diff(range(1, 7), infomaticalClosedWeekdays()));
$workdayhelp .= '<br>'.$langs->trans($workday['userdays'] ? 'InfomaticalWorkdaysFromUser' : 'InfomaticalWorkdaysFromBusiness', infomaticalWeekdayNames($workdays, $langs));
print $form->textwithpicto($langs->trans('InfomaticalPlanSummary', count($planned), infomaticalFormatMinutes($totalminutes), infomaticalFormatMinutes($workdayminutes)), $workdayhelp);
print '. '.$langs->trans('InfomaticalPlanDone', $nbdone, infomaticalFormatMinutes($openminutes));
print ' - ';
if ($free < 0) {
	print img_warning().' <span class="error">'.$langs->trans('InfomaticalPlanOverbooked', infomaticalFormatMinutes(-$free)).'</span>';
} else {
	print $langs->trans('InfomaticalPlanFreeTime', infomaticalFormatMinutes($free));
}
print '</div>';

/**
 * Print ticket ref, subject, third party, severity and status cells.
 *
 * @param array<string,mixed> $row            Ticket row
 * @param Ticket              $ticketstatic   Ticket used for links and status
 * @param Societe             $socstatic      Third party used for links
 * @param array<string,string> $severitylabels Severity code => label
 * @return void
 */
function infomaticalPrintTicketCells($row, $ticketstatic, $socstatic, $severitylabels)
{
	$ticketstatic->id = $row['id'];
	$ticketstatic->ref = $row['ref'];
	$ticketstatic->track_id = $row['track_id'];
	$ticketstatic->subject = $row['subject'];
	$ticketstatic->status = $row['status'];
	$ticketstatic->progress = $row['progress'];

	print '<td class="nowraponall">'.$ticketstatic->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax200" title="'.dolPrintHTMLForAttribute($row['subject']).'">'.dolPrintLabel($row['subject']).'</td>';
	print '<td class="tdoverflowmax150">';
	if ($row['socid'] > 0) {
		$socstatic->id = $row['socid'];
		$socstatic->name = $row['thirdparty'];
		print $socstatic->getNomUrl(1);
	}
	print '</td>';
	print '<td>'.dolPrintLabel($severitylabels[$row['severity_code']] ?? $row['severity_code']).'</td>';
	print '<td class="nowraponall">'.$ticketstatic->getLibStatut(5).'</td>';
}

// Planned tickets
print load_fiche_titre($langs->trans('InfomaticalPlanned'), '', '');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="center width25">#</th>';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Subject').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('TicketSeverity').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th>'.$langs->trans('InfomaticalEstimateAndNote').'</th>';
print '<th class="center"></th>';
print '</tr>';

if (empty($planned)) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('InfomaticalPlanEmpty').'</span></td></tr>';
}
foreach ($planned as $i => $row) {
	$lineurl = $backurl.'&planid='.$row['planid'].'&token='.newToken();
	print '<tr class="oddeven">';
	print '<td class="center">'.($i + 1).'</td>';
	infomaticalPrintTicketCells($row, $ticketstatic, $socstatic, $severitylabels);
	print '<td>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="day" value="'.$day.'">';
	print '<input type="hidden" name="planid" value="'.$row['planid'].'">';
	print '<input type="number" name="minutes" class="width75 right" min="0" max="'.InfomaticalTicketPlan::MAX_DURATION.'" step="15" value="'.$row['duration'].'" title="'.dolPrintHTMLForAttribute($langs->trans('InfomaticalMinutes')).'"> '.$langs->trans('InfomaticalMinutesShort');
	print ' <input type="text" name="note" class="maxwidth150" maxlength="255" value="'.dolPrintHTMLForAttribute($row['note']).'" placeholder="'.dolPrintHTMLForAttribute($langs->trans('Note')).'">';
	print ' <input type="submit" class="button smallpaddingimp" value="'.dolPrintHTMLForAttribute($langs->trans('Save')).'">';
	print '</form>';
	print '</td>';
	print '<td class="center nowraponall">';
	if ($i > 0) {
		print '<a class="paddingright" href="'.$lineurl.'&action=moveup">'.img_up($langs->trans('InfomaticalMoveUp')).'</a>';
	}
	if ($i < count($planned) - 1) {
		print '<a class="paddingright" href="'.$lineurl.'&action=movedown">'.img_down($langs->trans('InfomaticalMoveDown')).'</a>';
	}
	print '<a href="'.$lineurl.'&action=remove">'.img_delete($langs->trans('InfomaticalRemoveFromPlan')).'</a>';
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

// Carry over the unfinished tickets of the previous plan
if (!empty($carry['ticketids'])) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="margintoponly">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="carryover">';
	print '<input type="hidden" name="day" value="'.$day.'">';
	print '<input type="submit" class="button" value="'.dolPrintHTMLForAttribute($langs->trans('InfomaticalCarryOver', dol_print_date(infomaticalDayToTime($carry['day']), 'day', 'tzuserrel'), count($carry['ticketids']))).'">';
	print '</form>';
}

// Tickets of the queue not planned for this day
print '<br>';
print load_fiche_titre($langs->trans('InfomaticalNotPlanned').' ('.count($unplanned).')', '', '');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Subject').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('TicketSeverity').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th class="center">'.$langs->trans('InfomaticalLastPlanned').'</th>';
print '<th>'.$langs->trans('InfomaticalAddToPlan').'</th>';
print '</tr>';

if (empty($unplanned)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('InfomaticalNothingToPlan').'</span></td></tr>';
}
foreach ($unplanned as $row) {
	print '<tr class="oddeven">';
	infomaticalPrintTicketCells($row, $ticketstatic, $socstatic, $severitylabels);
	print '<td class="center nowraponall">';
	if (isset($lastplanned[$row['id']])) {
		$lastday = $lastplanned[$row['id']];
		print '<a href="'.$_SERVER['PHP_SELF'].'?day='.$lastday.'">'.dol_print_date(infomaticalDayToTime($lastday), 'day', 'tzuserrel').'</a>';
	}
	print '</td>';
	print '<td class="nowraponall">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="day" value="'.$day.'">';
	print '<input type="hidden" name="ticketid" value="'.$row['id'].'">';
	print '<input type="number" name="minutes" class="width75 right" min="0" max="'.InfomaticalTicketPlan::MAX_DURATION.'" step="15" value="'.$defaultminutes.'" title="'.dolPrintHTMLForAttribute($langs->trans('InfomaticalMinutes')).'"> '.$langs->trans('InfomaticalMinutesShort');
	print ' <input type="submit" class="button smallpaddingimp" value="'.dolPrintHTMLForAttribute($langs->trans('Add')).'">';
	print '</form>';
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
