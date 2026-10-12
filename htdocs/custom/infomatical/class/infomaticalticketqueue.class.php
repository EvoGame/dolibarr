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
 * \file    infomatical/class/infomaticalticketqueue.class.php
 * \ingroup infomatical
 * \brief   Open tickets a technician is responsible for (shared by the dashboard and the AI tool)
 */

require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';

/**
 * Read-only queue of open tickets for one internal user.
 *
 * A ticket is in a user's queue when it is assigned to them (llx_ticket.fk_user_assign)
 * or when they are an internal "Ticket manager" contact (SUPPORTTEC) on it.
 */
class InfomaticalTicketQueue
{
	/** Hard cap so a misconfigured queue cannot load the whole ticket table */
	const MAX_ROWS = 500;

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
	 * Return the open tickets of a user, most urgent first (severity, then oldest).
	 *
	 * @param	User	$user	User whose queue is loaded
	 * @param	int		$limit	Max number of tickets (capped by MAX_ROWS)
	 * @return	array<int,array{id:int,ref:string,track_id:string,subject:string,status:int,progress:int,severity_code:string,severity_label:string,severity_pos:int,socid:int,thirdparty:string,datec:int,date_last_msg_sent:int,assigned_to_me:bool}>|int<-1,-1>	Rows, or -1 on error
	 */
	public function fetchOpenForUser(User $user, $limit = self::MAX_ROWS)
	{
		global $conf;

		$limit = min(max(1, (int) $limit), self::MAX_ROWS);

		$sql = "SELECT t.rowid, t.ref, t.track_id, t.subject, t.fk_statut, t.progress, t.severity_code,";
		$sql .= " t.fk_soc, s.nom as thirdparty, t.datec, t.date_last_msg_sent, t.fk_user_assign";
		$sql .= " FROM ".$this->db->prefix()."ticket as t";
		$sql .= " LEFT JOIN ".$this->db->prefix()."societe as s ON s.rowid = t.fk_soc";
		$sql .= " WHERE t.entity IN (".getEntity('ticket').")";
		$sql .= " AND t.fk_statut NOT IN (".Ticket::STATUS_CLOSED.", ".Ticket::STATUS_CANCELED.")";
		$sql .= " AND (t.fk_user_assign = ".((int) $user->id);
		$sql .= " OR EXISTS (SELECT 1 FROM ".$this->db->prefix()."element_contact as ec";
		$sql .= " INNER JOIN ".$this->db->prefix()."c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact";
		$sql .= " WHERE ec.element_id = t.rowid AND ec.fk_socpeople = ".((int) $user->id);
		$sql .= " AND tc.element = 'ticket' AND tc.source = 'internal' AND tc.code = 'SUPPORTTEC'))";
		if ($user->socid > 0) {
			$sql .= " AND t.fk_soc = ".((int) $user->socid);
		}
		$sql .= " ORDER BY t.datec ASC";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		// Severity order comes from the dictionary position (llx_c_ticket_severity.pos)
		$ticketstatic = new Ticket($this->db);
		$ticketstatic->loadCacheSeveritiesTickets();
		$severities = array();
		foreach ((array) ($conf->cache['severity_tickets'] ?? array()) as $sev) {
			$severities[$sev['code']] = array('label' => $sev['label'], 'pos' => (int) $sev['pos']);
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$code = (string) $obj->severity_code;
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'track_id' => (string) $obj->track_id,
				'subject' => (string) $obj->subject,
				'status' => (int) $obj->fk_statut,
				'progress' => (int) $obj->progress,
				'severity_code' => $code,
				'severity_label' => $severities[$code]['label'] ?? $code,
				'severity_pos' => $severities[$code]['pos'] ?? 0,
				'socid' => (int) $obj->fk_soc,
				'thirdparty' => (string) $obj->thirdparty,
				'datec' => (int) $this->db->jdate($obj->datec),
				'date_last_msg_sent' => (int) $this->db->jdate($obj->date_last_msg_sent),
				'assigned_to_me' => ((int) $obj->fk_user_assign === (int) $user->id),
			);
		}
		$this->db->free($resql);

		// Most severe first, then oldest first
		usort($rows, static function ($a, $b) {
			return ($b['severity_pos'] <=> $a['severity_pos']) ?: ($a['datec'] <=> $b['datec']);
		});

		return $rows;
	}
}
