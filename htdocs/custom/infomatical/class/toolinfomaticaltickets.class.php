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
 * \file    infomatical/class/toolinfomaticaltickets.class.php
 * \ingroup infomatical
 * \brief   AI (MCP) tools on tickets, registered through the addMcpTools hook
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
dol_include_once('/infomatical/class/infomaticalticketqueue.class.php');
dol_include_once('/infomatical/lib/infomatical.lib.php');

/**
 * Ticket tools for the AI assistant and the MCP server.
 */
class ToolInfomaticalTickets extends McpTool
{
	/**
	 * Return the tool definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDefinitions(): array
	{
		return array(
			array(
				'name' => 'infomatical_my_open_tickets',
				'description' => "List the open support tickets the current user is responsible for (assigned to them, or internal 'Ticket manager' contact), most urgent first: highest severity, then oldest. Use it to answer 'what is on my plate', 'my tickets', or to help a technician plan their day. Status codes: 0=not read, 1=read, 2=assigned, 3=in progress, 5=needs more info, 7=waiting.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'limit' => array(
							'type' => 'integer',
							'description' => 'Maximum number of tickets to return (default 50, max '.InfomaticalTicketQueue::MAX_ROWS.').',
							'default' => 50,
						),
					),
				),
			),
		);
	}

	/**
	 * Rights needed to run the tools.
	 *
	 * @param string $toolName Tool being executed
	 * @return array<int,array<int,string>>|string
	 */
	public function getRequiredRights(string $toolName)
	{
		return array(array('ticket', 'read'));
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
	 * Execute a tool.
	 *
	 * @param string               $toolName Tool name
	 * @param array<string, mixed> $args     Arguments
	 * @return mixed
	 */
	public function execute(string $toolName, array $args)
	{
		$missing = infomaticalAiMissingRight($this->user, $this->getRequiredRights($toolName));
		if ($missing !== '') {
			return array('error' => "Permission denied: this tool needs the right '".$missing."'.");
		}

		switch ($toolName) {
			case 'infomatical_my_open_tickets':
				$queue = new InfomaticalTicketQueue($this->db);
				$rows = $queue->fetchOpenForUser($this->user, (int) ($args['limit'] ?? 50));
				if (!is_array($rows)) {
					return array('error' => 'Failed to load tickets.');
				}
				foreach ($rows as &$row) {
					$row['datec'] = dol_print_date($row['datec'], 'dayhourrfc', 'gmt');
					$row['date_last_msg_sent'] = $row['date_last_msg_sent'] ? dol_print_date($row['date_last_msg_sent'], 'dayhourrfc', 'gmt') : null;
				}
				unset($row);
				return array('count' => count($rows), 'tickets' => $rows);
			default:
				return array('error' => "Tool function '".$toolName."' not found.");
		}
	}
}
