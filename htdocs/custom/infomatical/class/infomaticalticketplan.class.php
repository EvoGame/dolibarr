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
 * \file    infomatical/class/infomaticalticketplan.class.php
 * \ingroup infomatical
 * \brief   A technician's plan of tickets for a day (table llx_infomatical_ticketplan)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';

/**
 * Day plans. Days are 'YYYY-MM-DD' strings in the user's calendar, so no timezone conversion
 * can move a plan to another day. Every method works on the plan of the given user only.
 */
class InfomaticalTicketPlan
{
	/** Default length of a working day, in minutes (constant INFOMATICAL_WORKDAY_MINUTES) */
	const DEFAULT_WORKDAY_MINUTES = 450;

	/** Default estimate given to a ticket added to a plan (constant INFOMATICAL_DEFAULT_PLAN_MINUTES) */
	const DEFAULT_PLAN_MINUTES = 60;

	/** Longest estimate accepted for one ticket, in minutes */
	const MAX_DURATION = 1440;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

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
	 * Return the day as 'YYYY-MM-DD' if it is a valid calendar date, '' otherwise.
	 *
	 * @param string $day Day to check
	 * @return string
	 */
	public static function normaliseDay($day)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $reg) || !checkdate((int) $reg[2], (int) $reg[3], (int) $reg[1])) {
			return '';
		}

		return $reg[0];
	}

	/**
	 * Tickets planned by a user for a day, in plan order. Tickets the user can no longer open are left out.
	 *
	 * @param User   $user User
	 * @param string $day  Day 'YYYY-MM-DD'
	 * @return array<int,array{planid:int,id:int,ref:string,track_id:string,subject:string,status:int,progress:int,severity_code:string,socid:int,thirdparty:string,duration:int,note:string}>|int<-1,-1>
	 */
	public function fetchDay(User $user, $day)
	{
		$sql = "SELECT p.rowid as planid, p.duration_planned, p.note,";
		$sql .= " t.rowid, t.ref, t.track_id, t.subject, t.fk_statut, t.progress, t.severity_code, t.fk_soc, s.nom as thirdparty";
		$sql .= " FROM ".$this->db->prefix()."infomatical_ticketplan as p";
		$sql .= " INNER JOIN ".$this->db->prefix()."ticket as t ON t.rowid = p.fk_ticket";
		$sql .= " LEFT JOIN ".$this->db->prefix()."societe as s ON s.rowid = t.fk_soc";
		$sql .= " WHERE p.entity = ".$this->entity();
		$sql .= " AND p.fk_user = ".((int) $user->id);
		$sql .= " AND p.date_plan = '".$this->db->escape($day)."'";
		$sql .= " ORDER BY p.rang ASC, p.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}
		$objs = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$objs[(int) $obj->rowid] = $obj;
		}
		$this->db->free($resql);

		$refused = getObjectIdsRefusedToUser($user, new Ticket($this->db), array_keys($objs));

		$rows = array();
		foreach ($objs as $id => $obj) {
			if (in_array($id, $refused)) {
				continue;
			}
			$rows[] = array(
				'planid' => (int) $obj->planid,
				'id' => $id,
				'ref' => (string) $obj->ref,
				'track_id' => (string) $obj->track_id,
				'subject' => (string) $obj->subject,
				'status' => (int) $obj->fk_statut,
				'progress' => (int) $obj->progress,
				'severity_code' => (string) $obj->severity_code,
				'socid' => (int) $obj->fk_soc,
				'thirdparty' => (string) $obj->thirdparty,
				'duration' => (int) $obj->duration_planned,
				'note' => (string) $obj->note,
			);
		}

		return $rows;
	}

	/**
	 * Most recent day before $day on which each ticket was in the user's plan.
	 *
	 * @param User   $user      User
	 * @param int[]  $ticketids Tickets
	 * @param string $day       Day 'YYYY-MM-DD'
	 * @return array<int,string>|int<-1,-1> Ticket id => day, for tickets planned before $day
	 */
	public function fetchLastPlannedBefore(User $user, array $ticketids, $day)
	{
		$ticketids = array_filter(array_map('intval', $ticketids));
		if (empty($ticketids)) {
			return array();
		}

		$sql = "SELECT p.fk_ticket, MAX(p.date_plan) as lastday";
		$sql .= " FROM ".$this->db->prefix()."infomatical_ticketplan as p";
		$sql .= " WHERE p.entity = ".$this->entity();
		$sql .= " AND p.fk_user = ".((int) $user->id);
		$sql .= " AND p.fk_ticket IN (".$this->db->sanitize(implode(',', $ticketids)).")";
		$sql .= " AND p.date_plan < '".$this->db->escape($day)."'";
		$sql .= " GROUP BY p.fk_ticket";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}
		$days = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$days[(int) $obj->fk_ticket] = substr((string) $obj->lastday, 0, 10);
		}
		$this->db->free($resql);

		return $days;
	}

	/**
	 * Latest day before $day with a plan, and the tickets of that plan still open and not yet planned on $day.
	 *
	 * @param User   $user User
	 * @param string $day  Day 'YYYY-MM-DD'
	 * @return array{day:string,ticketids:int[]}|int<-1,-1> Empty day when there is no earlier plan
	 */
	public function fetchCarryOver(User $user, $day)
	{
		$entity = $this->entity();

		$sql = "SELECT MAX(p.date_plan) as lastday FROM ".$this->db->prefix()."infomatical_ticketplan as p";
		$sql .= " WHERE p.entity = ".$entity." AND p.fk_user = ".((int) $user->id);
		$sql .= " AND p.date_plan < '".$this->db->escape($day)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$lastday = $obj && $obj->lastday ? substr((string) $obj->lastday, 0, 10) : '';
		if ($lastday === '') {
			return array('day' => '', 'ticketids' => array());
		}

		$sql = "SELECT p.fk_ticket FROM ".$this->db->prefix()."infomatical_ticketplan as p";
		$sql .= " INNER JOIN ".$this->db->prefix()."ticket as t ON t.rowid = p.fk_ticket";
		$sql .= " WHERE p.entity = ".$entity." AND p.fk_user = ".((int) $user->id);
		$sql .= " AND p.date_plan = '".$this->db->escape($lastday)."'";
		$sql .= " AND t.fk_statut NOT IN (".Ticket::STATUS_CLOSED.", ".Ticket::STATUS_CANCELED.")";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$this->db->prefix()."infomatical_ticketplan as p2";
		$sql .= " WHERE p2.entity = p.entity AND p2.fk_user = p.fk_user AND p2.fk_ticket = p.fk_ticket";
		$sql .= " AND p2.date_plan = '".$this->db->escape($day)."')";
		$sql .= " ORDER BY p.rang ASC, p.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}
		$ticketids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ticketids[] = (int) $obj->fk_ticket;
		}
		$this->db->free($resql);

		return array('day' => $lastday, 'ticketids' => array_values(array_diff($ticketids, getObjectIdsRefusedToUser($user, new Ticket($this->db), $ticketids))));
	}

	/**
	 * Add tickets at the end of a user's plan for a day, keeping the estimates they had on $fromday if given.
	 *
	 * @param User   $user      User
	 * @param int[]  $ticketids Tickets to add
	 * @param string $day       Day 'YYYY-MM-DD'
	 * @param int    $minutes   Estimate for tickets without an earlier one
	 * @param string $fromday   Day whose estimates and notes are copied ('' for none)
	 * @return int Number of tickets added, -1 on error
	 */
	public function add(User $user, array $ticketids, $day, $minutes, $fromday = '')
	{
		$entity = $this->entity();
		$minutes = min(max(0, (int) $minutes), self::MAX_DURATION);

		$previous = array();
		if ($fromday !== '') {
			$sql = "SELECT fk_ticket, duration_planned, note FROM ".$this->db->prefix()."infomatical_ticketplan";
			$sql .= " WHERE entity = ".$entity." AND fk_user = ".((int) $user->id)." AND date_plan = '".$this->db->escape($fromday)."'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				return $this->sqlError();
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$previous[(int) $obj->fk_ticket] = $obj;
			}
			$this->db->free($resql);
		}

		$this->db->begin();

		// Skip tickets already planned that day: a failed INSERT would abort the transaction on PostgreSQL
		$sql = "SELECT fk_ticket, rang FROM ".$this->db->prefix()."infomatical_ticketplan";
		$sql .= " WHERE entity = ".$entity." AND fk_user = ".((int) $user->id)." AND date_plan = '".$this->db->escape($day)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			return $this->sqlError();
		}
		$rang = 0;
		$planned = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$planned[] = (int) $obj->fk_ticket;
			$rang = max($rang, (int) $obj->rang);
		}
		$this->db->free($resql);

		$added = 0;
		foreach (array_diff(array_unique(array_map('intval', $ticketids)), $planned) as $ticketid) {
			$prev = $previous[$ticketid] ?? null;
			$sql = "INSERT INTO ".$this->db->prefix()."infomatical_ticketplan";
			$sql .= " (entity, fk_ticket, fk_user, date_plan, rang, duration_planned, note, date_creation, fk_user_creat)";
			$sql .= " VALUES (".$entity.", ".$ticketid.", ".((int) $user->id).", '".$this->db->escape($day)."', ".(++$rang);
			$sql .= ", ".($prev ? (int) $prev->duration_planned : $minutes);
			$sql .= ", ".($prev && $prev->note !== null ? "'".$this->db->escape($prev->note)."'" : "NULL");
			$sql .= ", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				return $this->sqlError();
			}
			$added++;
		}

		$this->db->commit();
		return $added;
	}

	/**
	 * Change the estimate and note of a planned ticket.
	 *
	 * @param User   $user    User
	 * @param int    $planid  Plan line
	 * @param int    $minutes Estimate in minutes
	 * @param string $note    Note
	 * @return int 1 if OK, 0 if the line is not the user's, -1 on error
	 */
	public function setDetails(User $user, $planid, $minutes, $note)
	{
		$sql = "UPDATE ".$this->db->prefix()."infomatical_ticketplan";
		$sql .= " SET duration_planned = ".min(max(0, (int) $minutes), self::MAX_DURATION);
		$sql .= ", note = ".(trim($note) === '' ? "NULL" : "'".$this->db->escape(dol_trunc(trim($note), 255, 'right', 'UTF-8', 1))."'");
		$sql .= $this->whereOwnLine($user, $planid);

		return $this->runOwnLineUpdate($sql);
	}

	/**
	 * Remove a ticket from a plan.
	 *
	 * @param User $user   User
	 * @param int  $planid Plan line
	 * @return int 1 if OK, 0 if the line is not the user's, -1 on error
	 */
	public function remove(User $user, $planid)
	{
		return $this->runOwnLineUpdate("DELETE FROM ".$this->db->prefix()."infomatical_ticketplan".$this->whereOwnLine($user, $planid));
	}

	/**
	 * Move a ticket one place up or down in its day's plan.
	 *
	 * @param User   $user   User
	 * @param int    $planid Plan line
	 * @param string $dir    'up' or 'down'
	 * @return int 1 if OK, 0 if nothing to move, -1 on error
	 */
	public function move(User $user, $planid, $dir)
	{
		$entity = $this->entity();

		$sql = "SELECT p.rowid FROM ".$this->db->prefix()."infomatical_ticketplan as p";
		$sql .= " INNER JOIN ".$this->db->prefix()."infomatical_ticketplan as ref ON ref.date_plan = p.date_plan AND ref.fk_user = p.fk_user AND ref.entity = p.entity";
		$sql .= " WHERE ref.rowid = ".((int) $planid)." AND ref.fk_user = ".((int) $user->id)." AND ref.entity = ".$entity;
		$sql .= " ORDER BY p.rang ASC, p.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$pos = array_search((int) $planid, $ids, true);
		$swap = ($dir === 'up') ? $pos - 1 : $pos + 1;
		if ($pos === false || !isset($ids[$swap])) {
			return 0;
		}
		list($ids[$pos], $ids[$swap]) = array($ids[$swap], $ids[$pos]);

		// Renumber the whole day: it also repairs gaps and equal ranks
		$this->db->begin();
		foreach ($ids as $i => $id) {
			if (!$this->db->query("UPDATE ".$this->db->prefix()."infomatical_ticketplan SET rang = ".($i + 1)." WHERE rowid = ".((int) $id))) {
				$this->db->rollback();
				return $this->sqlError();
			}
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Delete every plan line of a ticket (called when the ticket is deleted).
	 *
	 * @param int $ticketid Ticket
	 * @return int 1 if OK, -1 on error
	 */
	public function deleteForTicket($ticketid)
	{
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."infomatical_ticketplan WHERE fk_ticket = ".((int) $ticketid))) {
			return $this->sqlError();
		}

		return 1;
	}

	/**
	 * Entity the plans of this request belong to.
	 *
	 * @return int
	 */
	private function entity()
	{
		global $conf;

		return (int) $conf->entity;
	}

	/**
	 * WHERE clause restricting a statement to one line of the user's own plan.
	 *
	 * @param User $user   User
	 * @param int  $planid Plan line
	 * @return string
	 */
	private function whereOwnLine(User $user, $planid)
	{
		return " WHERE rowid = ".((int) $planid)." AND fk_user = ".((int) $user->id)." AND entity = ".$this->entity();
	}

	/**
	 * Run an UPDATE/DELETE on one line of the user's plan.
	 *
	 * @param string $sql Statement built with whereOwnLine()
	 * @return int 1 if a line was changed, 0 if none, -1 on error
	 */
	private function runOwnLineUpdate($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->sqlError();
		}

		return $this->db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Record and log the last SQL error.
	 *
	 * @return int<-1,-1>
	 */
	private function sqlError()
	{
		$this->error = $this->db->lasterror();
		dol_syslog(get_class($this).' '.$this->error, LOG_ERR);

		return -1;
	}
}
