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
 * \file    square/list.php
 * \ingroup square
 * \brief   Square payment links and Terminal checkouts, with those needing attention first
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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
dol_include_once('/square/class/squaretransaction.class.php');

$langs->loadLangs(array('bills', 'square@square'));

if (!isModEnabled('square') || !$user->hasRight('square', 'read')) {
	accessforbidden();
}
// Checking a row on Square can record a payment
$canrecord = $user->hasRight('facture', 'paiement');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$search_status = GETPOST('search_status', 'aZ09');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;


/*
 * Actions
 */

if ($action == 'check' && $canrecord) {
	$row = new SquareTransaction($db);
	if ($row->fetch($id) > 0) {
		$result = $row->refresh($user, dol_now() - $row->date_creation > 600);
		if ($result < 0) {
			setEventMessages($row->error, null, 'errors');
		} else {
			setEventMessages($langs->trans($result ? 'SquareRowUpdated' : 'SquareRowUnchanged'), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].($search_status !== '' ? '?search_status='.urlencode($search_status) : ''));
	exit;
}

if ($action == 'checkall' && $canrecord) {
	$reconciler = new SquareTransaction($db);
	$result = $reconciler->cronReconcile();
	setEventMessages(dol_nl2br(dolPrintLabel($reconciler->output)), null, $result ? 'warnings' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].($search_status !== '' ? '?search_status='.urlencode($search_status) : ''));
	exit;
}

// Someone recorded by hand a payment the module could not record
if ($action == 'resolve' && $canrecord) {
	$row = new SquareTransaction($db);
	if ($row->fetch($id) > 0) {
		$row->setStatus(SquareTransaction::STATUS_RECORDED, array(SquareTransaction::STATUS_ATTENTION), array('note' => $langs->transnoentitiesnoconv('SquareNoteResolvedBy', $user->getFullName($langs), dol_print_date(dol_now(), 'dayhour'))));
	}
	header('Location: '.$_SERVER['PHP_SELF'].($search_status !== '' ? '?search_status='.urlencode($search_status) : ''));
	exit;
}


/*
 * View
 */

$form = new Form($db);
$invoicestatic = new Facture($db);
$paymentstatic = new Paiement($db);

$statuses = array();
foreach (array(SquareTransaction::STATUS_ATTENTION, SquareTransaction::STATUS_PENDING, SquareTransaction::STATUS_PROCESSING, SquareTransaction::STATUS_RECORDED, SquareTransaction::STATUS_CANCELLED) as $status) {
	$statuses[$status] = $langs->trans('SquareStatus'.ucfirst($status));
}

$sqlwhere = " WHERE t.entity = ".((int) $conf->entity);
if ($search_status !== '' && isset($statuses[$search_status])) {
	$sqlwhere .= " AND t.status = '".$db->escape($search_status)."'";
}

$nbtotalofrecords = 0;
$resql = $db->query("SELECT COUNT(t.rowid) as nb FROM ".MAIN_DB_PREFIX."square_transaction as t".$sqlwhere);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbtotalofrecords = (int) $obj->nb;
}

$sql = "SELECT t.rowid, t.entity, t.type, t.livemode, t.status, t.square_id, t.square_order_id, t.square_payment_id, t.square_url, t.device_id,";
$sql .= " t.source, t.source_ref, t.fk_facture, t.fulltag, t.amount, t.currency, t.fk_paiement, t.note, t.date_creation, t.tms, t.fk_user_creat,";
$sql .= " f.ref as invoice_ref, f.fk_statut as invoice_status, f.paye as invoice_paye, p.ref as payment_ref";
$sql .= " FROM ".MAIN_DB_PREFIX."square_transaction as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = t.fk_facture";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = t.fk_paiement";
$sql .= $sqlwhere;
// Rows needing a person first
$sql .= " ORDER BY CASE WHEN t.status = '".$db->escape(SquareTransaction::STATUS_ATTENTION)."' THEN 0 ELSE 1 END, t.rowid DESC";
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

llxHeader('', $langs->trans('SquareTransactions'), '', '', 0, 0, '', '', '', 'mod-square page-list');

$param = ($search_status !== '' ? '&search_status='.urlencode($search_status) : '');
$newbutton = $canrecord ? dolGetButtonTitle($langs->trans('SquareCheckAll'), '', 'fa fa-sync', $_SERVER['PHP_SELF'].'?action=checkall&token='.newToken().$param) : '';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print_barre_liste($langs->trans('SquareTransactions'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, $nbtotalofrecords, 'fa-credit-card', 0, $newbutton, '', $limit);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="6"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statuses, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre" colspan="2"></td>';
print '<td class="liste_titre center">'.$form->showFilterButtons().'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('Type').'</th>';
print '<th>'.$langs->trans('SquarePaidFor').'</th>';
print '<th class="right">'.$langs->trans('Amount').'</th>';
print '<th>'.$langs->trans('SquareReference').'</th>';
print '<th>'.$langs->trans('Payment').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '<th>'.$langs->trans('Note').'</th>';
print '<th>'.$langs->trans('SquareEnvironment').'</th>';
print '<th></th>';
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$i++;

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour', 'tzuserrel').'</td>';
	print '<td>'.$langs->trans($obj->type == SquareTransaction::TYPE_TERMINAL ? 'SquareTypeTerminal' : 'SquareTypeLink').'</td>';

	print '<td class="tdoverflowmax200">';
	if ($obj->fk_facture > 0 && $obj->invoice_ref) {
		$invoicestatic->id = (int) $obj->fk_facture;
		$invoicestatic->ref = $obj->invoice_ref;
		$invoicestatic->status = (int) $obj->invoice_status;
		$invoicestatic->paye = (int) $obj->invoice_paye;
		print $invoicestatic->getNomUrl(1);
	} else {
		print dolPrintLabel(trim($obj->source.' '.$obj->source_ref) !== '' ? trim($obj->source.' '.$obj->source_ref) : $obj->fulltag);
	}
	print '</td>';

	print '<td class="right nowraponall"><span class="amount">'.price($obj->amount, 0, $langs, 1, -1, -1, $obj->currency).'</span></td>';

	print '<td class="tdoverflowmax150" title="'.dolPrintHTMLForAttribute($obj->square_id.($obj->square_order_id ? ' / '.$obj->square_order_id : '').($obj->square_payment_id ? ' / '.$obj->square_payment_id : '')).'">';
	print dolPrintLabel($obj->square_payment_id ? $obj->square_payment_id : $obj->square_id);
	print '</td>';

	print '<td class="nowraponall">';
	if ($obj->fk_paiement > 0) {
		$paymentstatic->id = (int) $obj->fk_paiement;
		$paymentstatic->ref = $obj->payment_ref;
		print $paymentstatic->getNomUrl(1);
	}
	print '</td>';

	print '<td class="center">'.SquareTransaction::statusBadge($obj->status).'</td>';
	print '<td class="tdoverflowmax300" title="'.dolPrintHTMLForAttribute($obj->note).'">'.dolPrintLabel($obj->note).'</td>';
	print '<td>'.$langs->trans($obj->livemode ? 'SquareEnvLive' : 'SquareEnvSandbox').'</td>';

	print '<td class="nowraponall right">';
	if ($canrecord && in_array($obj->status, array(SquareTransaction::STATUS_PENDING, SquareTransaction::STATUS_PROCESSING))) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=check&id='.((int) $obj->rowid).'&token='.newToken().$param.'">'.img_picto($langs->trans('SquareCheckNow'), 'refresh').'</a>';
	}
	if ($canrecord && $obj->status == SquareTransaction::STATUS_ATTENTION) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=resolve&id='.((int) $obj->rowid).'&token='.newToken().$param.'" title="'.dolPrintHTMLForAttribute($langs->trans('SquareResolveHelp')).'" onclick="return confirm('.dolPrintHTMLForAttribute(json_encode($langs->transnoentitiesnoconv('SquareResolveHelp'))).');">'.$langs->trans('SquareResolve').'</a>';
	}
	print '</td>';
	print '</tr>';
}
if ($num == 0) {
	print '<tr class="oddeven"><td colspan="10"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
