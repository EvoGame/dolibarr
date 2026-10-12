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
 * \file    infomatical/myqueue.php
 * \ingroup infomatical
 * \brief   Technician dashboard: open tickets the current user is responsible for
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

$langs->loadLangs(array('ticket', 'companies', 'infomatical@infomatical'));

// Security check: internal users with ticket read permission only
if (!isModEnabled('infomatical') || !isModEnabled('ticket')) {
	accessforbidden('Module not enabled');
}
if ($user->socid > 0 || !$user->hasRight('ticket', 'read')) {
	accessforbidden();
}


/*
 * Actions
 */

// None


/*
 * View
 */

$queue = new InfomaticalTicketQueue($db);
$rows = $queue->fetchOpenForUser($user);
if (!is_array($rows)) {
	setEventMessages($queue->error, null, 'errors');
	$rows = array();
}

$now = dol_now();
$ticketstatic = new Ticket($db);
$socstatic = new Societe($db);

$title = $langs->trans('InfomaticalMyQueue');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-infomatical page-myqueue');

print load_fiche_titre($title.' ('.count($rows).')', '', 'ticket');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('InfomaticalMyQueueHelp').'</div>';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Subject').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('TicketSeverity').'</th>';
print '<th class="center">'.$langs->trans('InfomaticalAge').'</th>';
print '<th class="center">'.$langs->trans('InfomaticalLastMessageSent').'</th>';
print '<th>'.$langs->trans('InfomaticalRole').'</th>';
print '<th class="right">'.$langs->trans('Status').'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('InfomaticalNothingOpen').'</span></td></tr>';
}

foreach ($rows as $row) {
	$ticketstatic->id = $row['id'];
	$ticketstatic->ref = $row['ref'];
	$ticketstatic->track_id = $row['track_id'];
	$ticketstatic->subject = $row['subject'];
	$ticketstatic->status = $row['status'];
	$ticketstatic->progress = $row['progress'];

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$ticketstatic->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax300" title="'.dolPrintHTMLForAttribute($row['subject']).'">'.dolPrintLabel($row['subject']).'</td>';
	print '<td class="tdoverflowmax150">';
	if ($row['socid'] > 0) {
		$socstatic->id = $row['socid'];
		$socstatic->name = $row['thirdparty'];
		print $socstatic->getNomUrl(1);
	}
	print '</td>';
	print '<td>'.dolPrintLabel($row['severity_label']).'</td>';
	print '<td class="center nowraponall" title="'.dol_print_date($row['datec'], 'dayhour', 'tzuser').'">'.convertSecondToTime(max(0, $now - $row['datec']), 'all').'</td>';
	print '<td class="center nowraponall">'.($row['date_last_msg_sent'] ? dol_print_date($row['date_last_msg_sent'], 'dayhour', 'tzuser') : '').'</td>';
	print '<td>'.$langs->trans($row['assigned_to_me'] ? 'InfomaticalRoleAssigned' : 'InfomaticalRoleContact').'</td>';
	print '<td class="right nowraponall">'.$ticketstatic->getLibStatut(5).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
