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
 * \file    infomatical/class/toolinfomaticalticketwrites.class.php
 * \ingroup infomatical
 * \brief   AI (MCP) tools that change tickets, registered through the addMcpTools hook
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';

/**
 * Ticket write tools. Every tool returns a preview from writeConfirmationPreview(), so McpHandler
 * asks the user to approve each change before execute() runs.
 */
class ToolInfomaticalTicketWrites extends McpTool
{
	/** Status names accepted by infomatical_ticket_set_status => Ticket status */
	const STATUSES = array(
		'read' => Ticket::STATUS_READ,
		'in_progress' => Ticket::STATUS_IN_PROGRESS,
		'need_more_info' => Ticket::STATUS_NEED_MORE_INFO,
		'waiting' => Ticket::STATUS_WAITING,
		'closed' => Ticket::STATUS_CLOSED,
		'canceled' => Ticket::STATUS_CANCELED,
	);

	/** Max length of a note shown in the confirmation preview */
	const PREVIEW_NOTE_LENGTH = 200;

	/**
	 * Return the tool definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDefinitions(): array
	{
		$ref = array('type' => 'string', 'description' => "Ticket reference, e.g. 'TS2401-0001'.");

		return array(
			array(
				'name' => 'infomatical_ticket_assign',
				'description' => "Assign a support ticket to an internal user, or remove its assignee. The user must confirm before anything is written. The new assignee is also added as internal 'Ticket manager' contact; other contacts are kept.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => $ref,
						'user_login' => array('type' => 'string', 'description' => "Login of the user to assign, 'me' for the current user, or '' to remove the assignee."),
					),
					'required' => array('ref', 'user_login'),
				),
			),
			array(
				'name' => 'infomatical_ticket_set_status',
				'description' => "Change the status of an open support ticket. 'closed' marks it solved, 'canceled' abandons it; closed or canceled tickets cannot be changed here. To assign a ticket use infomatical_ticket_assign instead. The user must confirm before anything is written.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => $ref,
						'status' => array('type' => 'string', 'enum' => array_keys(self::STATUSES), 'description' => "New status. 'waiting' (on hold) exists only when the on-hold status is enabled in ticket setup."),
					),
					'required' => array('ref', 'status'),
				),
			),
			array(
				'name' => 'infomatical_ticket_update',
				'description' => "Change the severity and/or the progress percentage of an open support ticket. Give at least one of them. The user must confirm before anything is written.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => $ref,
						'severity_code' => array('type' => 'string', 'description' => "Severity code from the ticket severity dictionary, usually LOW, NORMAL, HIGH or BLOCKING."),
						'progress' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Progress in percent.'),
					),
					'required' => array('ref'),
				),
			),
			array(
				'name' => 'infomatical_ticket_add_note',
				'description' => "Add a private (internal) note to a support ticket. It is never shown to or emailed to the customer. The user must confirm before anything is written.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => $ref,
						'message' => array('type' => 'string', 'description' => 'Text of the note (plain text).'),
					),
					'required' => array('ref', 'message'),
				),
			),
		);
	}

	/**
	 * Rights needed, mirroring ticket/card.php: assigning and changing status need the "manage" right
	 * (ticket write, or ticket manage_advance with advanced permissions), editing needs ticket write,
	 * adding a message needs ticket read.
	 *
	 * @param string $toolName Tool being executed
	 * @return array<int,array<int,string>>|string
	 */
	public function getRequiredRights(string $toolName)
	{
		switch ($toolName) {
			case 'infomatical_ticket_assign':
			case 'infomatical_ticket_set_status':
				return getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? array(array('ticket', 'manage_advance')) : array(array('ticket', 'write'));
			case 'infomatical_ticket_update':
				return array(array('ticket', 'write'));
			case 'infomatical_ticket_add_note':
				return array(array('ticket', 'read'));
		}

		return self::RIGHTS_UNDECLARED;
	}

	/**
	 * Intent categories: the assistant routes ticket questions to 'thirdparty' and 'project'.
	 *
	 * @return array<string>
	 */
	public function getCategories(): array
	{
		return array('thirdparty', 'project');
	}

	/**
	 * Refuse, before the user is asked to confirm, a change that cannot be applied.
	 *
	 * @param string              $toolName Tool that would run
	 * @param array<string,mixed> $args     Arguments it would run with
	 * @return string Error message, or '' when the change may proceed to confirmation
	 */
	public function writePrecheck(string $toolName, array $args)
	{
		$ticket = $this->loadTicket($args, $error);
		if (!$ticket) {
			return $error;
		}
		$isclosed = in_array((int) $ticket->status, array(Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELED), true);

		switch ($toolName) {
			case 'infomatical_ticket_assign':
				if ($isclosed) {
					return "Ticket ".$ticket->ref." is closed or canceled. Nothing was written.";
				}
				$assignee = $this->loadAssignee((string) ($args['user_login'] ?? ''), $error);
				if ($assignee === null) {
					return $error;
				}
				if ((int) $ticket->fk_user_assign === ($assignee ? (int) $assignee->id : 0)) {
					return "Ticket ".$ticket->ref." already has this assignee. Nothing was written.";
				}
				return '';
			case 'infomatical_ticket_set_status':
				if ($isclosed) {
					return "Ticket ".$ticket->ref." is closed or canceled; reopen it from the ticket card. Nothing was written.";
				}
				$status = (string) ($args['status'] ?? '');
				if (!isset(self::STATUSES[$status]) || !isset($ticket->labelStatus[self::STATUSES[$status]])) {
					$allowed = array();
					foreach (self::STATUSES as $name => $code) {
						if (isset($ticket->labelStatus[$code])) {
							$allowed[] = $name;
						}
					}
					return "Unknown or disabled status '".$status."'. Allowed: ".implode(', ', $allowed).".";
				}
				if ((int) $ticket->status === self::STATUSES[$status]) {
					return "Ticket ".$ticket->ref." already has this status. Nothing was written.";
				}
				return '';
			case 'infomatical_ticket_update':
				if ($isclosed) {
					return "Ticket ".$ticket->ref." is closed or canceled. Nothing was written.";
				}
				if (!isset($args['severity_code']) && !isset($args['progress'])) {
					return "Give severity_code and/or progress. Nothing was written.";
				}
				if (isset($args['severity_code']) && $this->severityLabel((string) $args['severity_code'], $ticket) === '') {
					return "Unknown severity code '".$args['severity_code']."'. Allowed: ".implode(', ', array_keys($this->severities($ticket))).".";
				}
				if (isset($args['progress']) && (!is_numeric($args['progress']) || $args['progress'] < 0 || $args['progress'] > 100)) {
					return "progress must be a number from 0 to 100. Nothing was written.";
				}
				return '';
			case 'infomatical_ticket_add_note':
				if (trim((string) ($args['message'] ?? '')) === '') {
					return "The note is empty. Nothing was written.";
				}
				return '';
		}

		return "Tool function '".$toolName."' not found.";
	}

	/**
	 * Sentence the user approves before the change is applied.
	 *
	 * @param string              $toolName Tool that would run
	 * @param array<string,mixed> $args     Arguments it would run with
	 * @return string Preview text
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		global $langs;

		$langs->loadLangs(array('main', 'ticket', 'infomatical@infomatical'));

		// writePrecheck() runs right after this and refuses anything that does not load
		$ticket = $this->loadTicket($args, $error);
		$what = $ticket ? $ticket->ref.' ('.dol_trunc($ticket->subject, 60).')' : (string) ($args['ref'] ?? '');

		switch ($toolName) {
			case 'infomatical_ticket_assign':
				$assignee = $this->loadAssignee((string) ($args['user_login'] ?? ''), $error);
				if ($assignee) {
					return $langs->transnoentitiesnoconv('InfomaticalPreviewAssign', $what, $assignee->getFullName($langs));
				}
				return $langs->transnoentitiesnoconv('InfomaticalPreviewUnassign', $what);
			case 'infomatical_ticket_set_status':
				$status = self::STATUSES[(string) ($args['status'] ?? '')] ?? -1;
				return $langs->transnoentitiesnoconv('InfomaticalPreviewSetStatus', $what, $this->statusLabel($ticket, $ticket ? (int) $ticket->status : -1), $this->statusLabel($ticket, $status));
			case 'infomatical_ticket_update':
				$changes = array();
				if (isset($args['severity_code'])) {
					$changes[] = $langs->transnoentitiesnoconv('TicketSeverity').' → '.$this->severityLabel((string) $args['severity_code'], $ticket);
				}
				if (isset($args['progress'])) {
					$changes[] = $langs->transnoentitiesnoconv('Progression').' → '.((int) $args['progress']).'%';
				}
				return $langs->transnoentitiesnoconv('InfomaticalPreviewUpdate', $what, implode(', ', $changes));
			case 'infomatical_ticket_add_note':
				return $langs->transnoentitiesnoconv('InfomaticalPreviewAddNote', $what, dol_trunc(trim((string) ($args['message'] ?? '')), self::PREVIEW_NOTE_LENGTH));
		}

		return self::NO_WRITE;
	}

	/**
	 * Apply a confirmed change.
	 *
	 * @param string               $toolName Tool name
	 * @param array<string, mixed> $args     Arguments
	 * @return mixed
	 */
	public function execute(string $toolName, array $args)
	{
		// Same checks as before the confirmation: the ticket may have changed in between
		$refused = $this->writePrecheck($toolName, $args);
		if ($refused !== '') {
			return array('error' => $refused);
		}
		$ticket = $this->loadTicket($args, $error);

		switch ($toolName) {
			case 'infomatical_ticket_assign':
				return $this->assign($ticket, $this->loadAssignee((string) $args['user_login'], $error));
			case 'infomatical_ticket_set_status':
				return $this->setStatus($ticket, self::STATUSES[(string) $args['status']]);
			case 'infomatical_ticket_update':
				return $this->update($ticket, $args);
			case 'infomatical_ticket_add_note':
				return $this->addNote($ticket, (string) $args['message']);
		}

		return array('error' => "Tool function '".$toolName."' not found.");
	}

	/**
	 * Assign or unassign, then make sure the new assignee is a Ticket manager contact.
	 *
	 * @param Ticket     $ticket   Ticket
	 * @param User|false $assignee User to assign, false to unassign
	 * @return array<string,mixed>
	 */
	private function assign(Ticket $ticket, $assignee)
	{
		$this->db->begin();

		$userid = $assignee ? (int) $assignee->id : 0;
		$result = $ticket->assignUser($this->user, $userid);
		if ($result > 0 && $userid > 0 && !in_array($userid, $ticket->getIdTicketInternalContact())) {
			$result = $ticket->add_contact($userid, 'SUPPORTTEC', 'internal');
		}
		if ($result < 0) {
			$this->db->rollback();
			return array('error' => 'Failed to assign ticket '.$ticket->ref.': '.$this->errorOf($ticket));
		}

		$this->db->commit();
		return array('status' => 'success', 'ref' => $ticket->ref, 'fk_user_assign' => $userid ?: null);
	}

	/**
	 * Change status the way ticket/card.php does (close/abandon, or setStatut + read date).
	 *
	 * @param Ticket $ticket Ticket
	 * @param int    $status New status
	 * @return array<string,mixed>
	 */
	private function setStatus(Ticket $ticket, $status)
	{
		if ($status === Ticket::STATUS_CLOSED || $status === Ticket::STATUS_CANCELED) {
			$result = $ticket->close($this->user, $status === Ticket::STATUS_CANCELED ? 1 : 0);
		} else {
			$this->db->begin();
			$result = $ticket->setStatut($status, null, '', 'TICKET_MODIFY');
			if ($result > 0) {
				// Trigger already run by setStatut()
				$result = $ticket->setReadDate($this->user, 1);
			}
			if ($result < 0) {
				$this->db->rollback();
			} else {
				$this->db->commit();
			}
		}
		if ($result <= 0) {
			return array('error' => 'Failed to change status of ticket '.$ticket->ref.': '.$this->errorOf($ticket));
		}

		return array('status' => 'success', 'ref' => $ticket->ref, 'ticket_status' => $status);
	}

	/**
	 * Update severity and/or progress.
	 *
	 * @param Ticket              $ticket Ticket
	 * @param array<string,mixed> $args   Arguments
	 * @return array<string,mixed>
	 */
	private function update(Ticket $ticket, array $args)
	{
		$this->db->begin();

		$result = 1;
		if (isset($args['severity_code'])) {
			$ticket->severity_code = (string) $args['severity_code'];
			$result = $ticket->update($this->user);
		}
		if ($result > 0 && isset($args['progress'])) {
			$result = $ticket->setProgression((int) $args['progress']);
		}
		if ($result < 0) {
			$this->db->rollback();
			return array('error' => 'Failed to update ticket '.$ticket->ref.': '.$this->errorOf($ticket));
		}

		$this->db->commit();
		return array('status' => 'success', 'ref' => $ticket->ref);
	}

	/**
	 * Add a private message, as "Add message" with "private" ticked on the ticket card, without email.
	 *
	 * @param Ticket $ticket  Ticket
	 * @param string $message Plain-text note
	 * @return array<string,mixed>
	 */
	private function addNote(Ticket $ticket, $message)
	{
		$ticket->private = 1;
		$ticket->message = dol_nl2br(dol_escape_htmltag(trim($message)));

		$id = $ticket->createTicketMessage($this->user);
		if ($id <= 0) {
			return array('error' => 'Failed to add the note to ticket '.$ticket->ref.': '.$this->errorOf($ticket));
		}

		return array('status' => 'success', 'ref' => $ticket->ref, 'event_id' => (int) $id);
	}

	/**
	 * Load the ticket named by $args['ref'] if the user may access it (same rule as ticket/card.php).
	 *
	 * @param array<string,mixed> $args  Arguments
	 * @param string              $error Set to the reason when null is returned
	 * @return Ticket|null
	 */
	private function loadTicket(array $args, &$error)
	{
		$error = '';
		$ref = trim((string) ($args['ref'] ?? ''));
		$ticket = new Ticket($this->db);
		if ($ref === '' || $ticket->fetch(0, $ref) <= 0) {
			$error = "No ticket found with ref '".$ref."'. Look it up with infomatical_my_open_tickets or api_tickets_list first.";
			return null;
		}

		$canmanage = getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? $this->user->hasRight('ticket', 'manage_advance') : $this->user->hasRight('ticket', 'write');
		$limitedtoassigned = !$this->user->socid && getDolGlobalString('TICKET_LIMIT_VIEW_ASSIGNED_ONLY') && (int) $ticket->fk_user_assign !== (int) $this->user->id && !$canmanage;
		if (!restrictedArea($this->user, 'ticket', $ticket->id, '', '', 'fk_soc', 'rowid', 0, 1) || $limitedtoassigned) {
			$error = "You are not allowed to access ticket '".$ref."'.";
			return null;
		}

		return $ticket;
	}

	/**
	 * Resolve the user to assign.
	 *
	 * @param string $login Login, 'me', or '' to unassign
	 * @param string $error Set to the reason when null is returned
	 * @return User|false|null User to assign, false to unassign, null when the login is not usable
	 */
	private function loadAssignee($login, &$error)
	{
		$error = '';
		$login = trim($login);
		if ($login === '') {
			return false;
		}
		if (dol_strtolower($login) === 'me') {
			$assignee = $this->user;
		} else {
			$assignee = new User($this->db);
			if ($assignee->fetch(0, $login) <= 0) {
				$error = "No user found with login '".$login."'.";
				return null;
			}
		}
		if ((int) $assignee->status !== 1 || $assignee->socid > 0) {
			$error = "User '".$login."' is disabled or external and cannot be assigned tickets.";
			return null;
		}

		return $assignee;
	}

	/**
	 * Active severities, code => label.
	 *
	 * @param Ticket $ticket Any ticket object, used to load the dictionary cache
	 * @return array<string,string>
	 */
	private function severities(Ticket $ticket)
	{
		global $conf;

		$ticket->loadCacheSeveritiesTickets();
		$severities = array();
		foreach ((array) ($conf->cache['severity_tickets'] ?? array()) as $sev) {
			$severities[$sev['code']] = $sev['label'];
		}

		return $severities;
	}

	/**
	 * Label of a severity code, '' when the code is unknown.
	 *
	 * @param string      $code   Severity code
	 * @param Ticket|null $ticket Ticket used to load the dictionary
	 * @return string
	 */
	private function severityLabel($code, $ticket)
	{
		if (!$ticket) {
			return $code;
		}

		return $this->severities($ticket)[$code] ?? '';
	}

	/**
	 * Translated label of a ticket status.
	 *
	 * @param Ticket|null $ticket Ticket (holds the label keys)
	 * @param int         $status Status
	 * @return string
	 */
	private function statusLabel($ticket, $status)
	{
		global $langs;

		$key = $ticket ? ($ticket->labelStatus[$status] ?? '') : '';

		return $key !== '' ? $langs->transnoentitiesnoconv($key) : (string) $status;
	}

	/**
	 * Error text of a failed core call.
	 *
	 * @param Ticket $ticket Ticket
	 * @return string
	 */
	private function errorOf(Ticket $ticket)
	{
		return $ticket->error ?: implode(', ', (array) $ticket->errors);
	}
}
