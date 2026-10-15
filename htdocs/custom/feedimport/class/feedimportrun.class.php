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
 * \file    feedimport/class/feedimportrun.class.php
 * \ingroup feedimport
 * \brief   A run of a feed (table llx_feedimport_run)
 */

/**
 * A run of a feed: the file imported, the counts of what changed, and its log
 */
class FeedImportRun
{
	const STATUS_RUNNING = 'running';
	const STATUS_OK = 'ok';
	/** The file was the same as the last one imported: nothing done */
	const STATUS_UNCHANGED = 'unchanged';
	/** Imported, but something needs a look: rows with errors, items to review, zeroing skipped by a guard */
	const STATUS_WARNING = 'warning';
	/** Not imported: no file, invalid file, connection or database error */
	const STATUS_ERROR = 'error';

	/** Longest log kept, in bytes: the start and the end of a longer one are kept */
	const MAX_LOG = 200000;

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	/** @var int */
	public $id = 0;
	/** @var int */
	public $entity;
	/** @var int */
	public $fk_feed = 0;
	/** @var string cron, manual, email, upload */
	public $trigger_type = '';
	/** @var string */
	public $status = self::STATUS_RUNNING;
	/** @var string */
	public $filename = '';
	/** @var string */
	public $remote_name = '';
	/** @var string */
	public $file_hash = '';
	/** @var int */
	public $nb_rows = 0;
	/** @var int */
	public $nb_created = 0;
	/** @var int */
	public $nb_updated = 0;
	/** @var int */
	public $nb_unchanged = 0;
	/** @var int */
	public $nb_review = 0;
	/** @var int */
	public $nb_skipped = 0;
	/** @var int */
	public $nb_zeroed = 0;
	/** @var int */
	public $nb_warnings = 0;
	/** @var int */
	public $nb_errors = 0;
	/** @var string */
	public $log = '';
	/** @var int */
	public $date_start;
	/** @var int|null */
	public $date_end;
	/** @var int */
	public $fk_user = 0;

	/** @var string[] Counters saved with the run */
	public static $counters = array('nb_rows', 'nb_created', 'nb_updated', 'nb_unchanged', 'nb_review', 'nb_skipped', 'nb_zeroed', 'nb_warnings', 'nb_errors');

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
	 * Insert the run (status running)
	 *
	 * @param	?User	$user	User who started it (null for the scheduled job and email)
	 * @return	int				Id, or <0 on error
	 */
	public function create($user = null)
	{
		global $conf;

		$this->entity = (int) $conf->entity;
		$this->date_start = dol_now();
		$this->fk_user = ($user && $user->id > 0) ? (int) $user->id : 0;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."feedimport_run (entity, fk_feed, trigger_type, status, date_start, fk_user)";
		$sql .= " VALUES (".((int) $this->entity).", ".((int) $this->fk_feed).", '".$this->db->escape($this->trigger_type)."',";
		$sql .= " '".$this->db->escape($this->status)."', '".$this->db->idate($this->date_start)."', ".($this->fk_user ? (int) $this->fk_user : 'NULL').")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'feedimport_run');
		return $this->id;
	}

	/**
	 * Save the counters, file, status and log
	 *
	 * @return	int		>0 if OK, <0 on error
	 */
	public function update()
	{
		$log = $this->log;
		if (strlen($log) > self::MAX_LOG) {
			$log = dol_trunc($log, (int) (self::MAX_LOG / 2), 'right', 'UTF-8', 1)."\n[...]\n".substr($log, -(int) (self::MAX_LOG / 2));
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_run SET";
		$sql .= " status = '".$this->db->escape($this->status)."'";
		$sql .= ", filename = ".($this->filename !== '' ? "'".$this->db->escape($this->filename)."'" : 'NULL');
		$sql .= ", remote_name = ".($this->remote_name !== '' ? "'".$this->db->escape(dol_trunc($this->remote_name, 250, 'right', 'UTF-8', 1))."'" : 'NULL');
		$sql .= ", file_hash = ".($this->file_hash !== '' ? "'".$this->db->escape($this->file_hash)."'" : 'NULL');
		foreach (self::$counters as $counter) {
			$sql .= ", ".$counter." = ".((int) $this->$counter);
		}
		$sql .= ", log = '".$this->db->escape($log)."'";
		$sql .= ", date_end = ".($this->date_end ? "'".$this->db->idate($this->date_end)."'" : 'NULL');
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Load a run
	 *
	 * @param	int		$id		Id
	 * @return	int				1 if found, 0 if not, <0 on error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, fk_feed, trigger_type, status, filename, remote_name, file_hash, ".implode(', ', self::$counters).",";
		$sql .= " log, date_start, date_end, fk_user FROM ".MAIN_DB_PREFIX."feedimport_run WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_feed = (int) $obj->fk_feed;
		$this->trigger_type = (string) $obj->trigger_type;
		$this->status = (string) $obj->status;
		$this->filename = (string) $obj->filename;
		$this->remote_name = (string) $obj->remote_name;
		$this->file_hash = (string) $obj->file_hash;
		foreach (self::$counters as $counter) {
			$this->$counter = (int) $obj->$counter;
		}
		$this->log = (string) $obj->log;
		$this->date_start = $this->db->jdate($obj->date_start);
		$this->date_end = $obj->date_end ? $this->db->jdate($obj->date_end) : null;
		$this->fk_user = (int) $obj->fk_user;
		return 1;
	}

	/**
	 * Add a line to the log
	 *
	 * @param	string	$message	Message (plain text)
	 * @param	string	$level		'' info, 'warning' (counted), 'error' (counted)
	 * @return	void
	 */
	public function log($message, $level = '')
	{
		if ($level == 'warning') {
			$this->nb_warnings++;
		} elseif ($level == 'error') {
			$this->nb_errors++;
		}
		$this->log .= dol_print_date(dol_now(), '%H:%M:%S', 'tzserver').' '.($level ? strtoupper($level).' ' : '').$message."\n";
		dol_syslog('FeedImport run '.$this->id.' feed '.$this->fk_feed.': '.$message, $level == 'error' ? LOG_WARNING : LOG_DEBUG);
	}

	/**
	 * One-line summary of the counts
	 *
	 * @return	string
	 */
	public function summary()
	{
		global $langs;

		$langs->load('feedimport@feedimport');
		// Translate::trans() passes at most 4 parameters to sprintf(): one label per counter
		$labels = array('nb_rows' => 'FeedImportNbRows', 'nb_created' => 'FeedImportNbCreated', 'nb_updated' => 'FeedImportNbUpdated', 'nb_unchanged' => 'FeedImportNbUnchanged',
			'nb_review' => 'FeedImportNbReview', 'nb_skipped' => 'FeedImportNbSkipped', 'nb_zeroed' => 'FeedImportNbZeroed', 'nb_warnings' => 'FeedImportNbWarnings', 'nb_errors' => 'FeedImportNbErrors');
		$parts = array();
		foreach ($labels as $counter => $key) {
			$parts[] = $langs->transnoentitiesnoconv($key).': '.((int) $this->$counter);
		}
		return implode(', ', $parts);
	}
}
