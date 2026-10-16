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
 * \file    feedimport/class/actions_feedimport.class.php
 * \ingroup feedimport
 * \brief   Hooks of module Feed Import: the "Feed import" operation of Email Collector rules
 */

dol_include_once('/feedimport/class/feedimportfeed.class.php');

/**
 * Hooks of module Feed Import.
 *
 * Email Collector keeps the mailbox, its filters and its schedule. A rule's operation "Feed import: import the
 * attached file" (type hookfeedimport, parameter: the feed id) hands the email's attachments to this class, which
 * keeps the one for the feed; the module's scheduled job imports it (see FeedImportFeed::queueFile()).
 */
class ActionsFeedImport
{
	/** Operation type in Email Collector rules. Email Collector runs every type starting with "hook" through doCollectImapOneCollector. */
	const OPERATION = 'hookfeedimport';

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';

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
	 * Email Collector card: add the operation to the operations a rule can have
	 *
	 * @param	array<string,mixed>		$parameters		Hook parameters (arrayoftypes)
	 * @param	CommonObject			$object			Email collector
	 * @param	string					$action			Action
	 * @param	HookManager				$hookmanager	Hook manager
	 * @return	int										0: the types returned are added to the core ones
	 */
	public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->reset();
		if (!isModEnabled('feedimport')) {
			return 0;
		}
		$langs->load('feedimport@feedimport');
		$this->results = array(self::OPERATION => 'FeedImportEmailOperation');
		return 0;
	}

	/**
	 * Email Collector: an email matched a rule whose operation is ours. Keep the attachment for the feed.
	 *
	 * @param	array<string,mixed>		$parameters		Hook parameters (actionparam, attachments, subject...)
	 * @param	CommonObject			$object			Email collector
	 * @param	string					$action			Operation type
	 * @param	HookManager				$hookmanager	Hook manager
	 * @return	int										0 if OK or not ours, -1 on error (resprints holds the message)
	 */
	public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->reset();
		if ($action !== self::OPERATION || !isModEnabled('feedimport')) {
			return 0;
		}
		$langs->load('feedimport@feedimport');

		$param = trim((string) (isset($parameters['actionparam']) ? $parameters['actionparam'] : ''));
		$feedid = 0;
		if (preg_match('/^(?:feed\s*=\s*)?(\d+)$/i', $param, $m)) {
			$feedid = (int) $m[1];
		}
		$feed = new FeedImportFeed($this->db);
		if ($feedid <= 0 || $feed->fetch($feedid) <= 0) {
			return $this->fail($langs->transnoentitiesnoconv('FeedImportEmailErrorFeed', $param));
		}
		if ($feed->method != 'email') {
			return $this->fail($langs->transnoentitiesnoconv('FeedImportEmailErrorNotEmail', $feed->label));
		}
		if (!$feed->status) {
			return $this->fail($langs->transnoentitiesnoconv('FeedImportEmailErrorDisabled', $feed->label));
		}

		$attachments = isset($parameters['attachments']) && is_array($parameters['attachments']) ? $parameters['attachments'] : array();
		$found = self::pickAttachment($attachments, $feed);
		if ($found === null) {
			$names = array();
			foreach (self::attachmentList($attachments) as $a) {
				$names[] = $a['name'];
			}
			return $this->fail($langs->transnoentitiesnoconv('FeedImportEmailErrorNoAttachment', self::attachmentPattern($feed), $feed->label, empty($names) ? '-' : implode(', ', $names)));
		}

		if ($feed->queueFile($found['content'], $found['name']) < 0) {
			return $this->fail($feed->error);
		}
		dol_syslog('FeedImport: '.$found['name'].' received by email for feed '.$feed->id.', waiting for the scheduled job');
		return 0;
	}

	/**
	 * The attachment of an email that is the feed's file: the first one matching the feed's pattern
	 *
	 * @param	array<int|string,mixed>		$attachments	Attachments as Email Collector gives them
	 * @param	FeedImportFeed				$feed			Feed
	 * @return	array{name:string,content:string}|null
	 */
	public static function pickAttachment($attachments, $feed)
	{
		$pattern = self::attachmentPattern($feed);
		foreach (self::attachmentList($attachments) as $a) {
			if ($a['content'] !== '' && fnmatch($pattern, $a['name'], FNM_CASEFOLD)) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Pattern the attachment's name must match: the feed's, else any file of its type
	 *
	 * @param	FeedImportFeed	$feed	Feed
	 * @return	string
	 */
	public static function attachmentPattern($feed)
	{
		$pattern = trim((string) $feed->params['pattern']);
		return $pattern !== '' ? feedimportExpandDateTokens($pattern, dol_now()) : '*.'.$feed->filetype;
	}

	/**
	 * Attachments as name and content, whatever the IMAP library: Webklex objects, or name => content
	 *
	 * @param	array<int|string,mixed>		$attachments	Attachments as Email Collector gives them
	 * @return	array<array{name:string,content:string}>
	 */
	private static function attachmentList($attachments)
	{
		$list = array();
		foreach ($attachments as $key => $attachment) {
			$name = '';
			$content = '';
			if (is_object($attachment)) {
				try {
					$name = (string) $attachment->getName();
					$content = (string) $attachment->getContent();
				} catch (Throwable $e) {
					continue;
				}
			} else {
				$name = (string) $key;
				$content = (string) $attachment;
			}
			$list[] = array('name' => basename($name), 'content' => $content);
		}
		return $list;
	}

	/**
	 * Clear what a previous call left: the hook manager keeps this instance, and takes any error left as a failure
	 *
	 * @return	void
	 */
	private function reset()
	{
		$this->error = '';
		$this->errors = array();
		$this->results = array();
		$this->resprints = '';
	}

	/**
	 * Report an error to Email Collector
	 *
	 * @param	string	$message	Message
	 * @return	int					-1
	 */
	private function fail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		$this->resprints = $message;
		dol_syslog('FeedImport email: '.$message, LOG_WARNING);
		return -1;
	}
}
