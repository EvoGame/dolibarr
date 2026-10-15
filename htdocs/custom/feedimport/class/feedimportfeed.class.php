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
 * \file    feedimport/class/feedimportfeed.class.php
 * \ingroup feedimport
 * \brief   A supplier feed: its settings, its schedule, and running it
 */

dol_include_once('/feedimport/lib/feedimport.lib.php');
dol_include_once('/feedimport/class/feedimportrun.class.php');
dol_include_once('/feedimport/class/feedimportparser.class.php');
dol_include_once('/feedimport/class/feedimporttransport.class.php');
dol_include_once('/feedimport/class/feedimportimporter.class.php');

/**
 * A supplier feed (table llx_feedimport_feed).
 *
 * A run (run()) claims the feed, gets the file (from the server, or as given by an email or an upload), skips it if
 * it is the file imported last time, imports it (FeedImportImporter), keeps it, and records a FeedImportRun.
 */
class FeedImportFeed
{
	/** Retrieval methods */
	const METHODS = array('ftp', 'ftpes', 'ftps', 'sftp', 'http', 'email', 'upload');
	/** Methods for which the module fetches the file itself */
	const REMOTE_METHODS = array('ftp', 'ftpes', 'ftps', 'sftp', 'http');

	/** A run still marked in progress after this many seconds is considered dead (killed process) */
	const LOCK_TIMEOUT = 7200;

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var string Output of the cron job */
	public $output = '';

	/** @var int */
	public $id = 0;
	/** @var int */
	public $entity;
	/** @var string */
	public $label = '';
	/** @var int 1 enabled, 0 disabled */
	public $status = 1;
	/** @var string see METHODS */
	public $method = 'ftp';
	/** @var string csv or xml */
	public $filetype = 'csv';
	/** @var int Supplier */
	public $fk_soc = 0;
	/** @var int Warehouse of the supplier's stock */
	public $fk_entrepot = 0;
	/** @var string manual, daily, hours */
	public $schedule_type = 'manual';
	/** @var string HH:MM */
	public $schedule_time = '';
	/** @var int */
	public $schedule_hours = 0;
	/** @var int|null */
	public $next_run;
	/** @var array<string,mixed> Settings, see defaultParams() */
	public $params = array();
	/** @var array<string,string> feed field => Dolibarr field */
	public $mapping = array();
	/** @var int|null */
	public $lock_date;
	/** @var int|null */
	public $last_run;
	/** @var string */
	public $last_status = '';
	/** @var string */
	public $last_file_hash = '';
	/** @var int */
	public $last_count = 0;
	/** @var int */
	public $date_creation;
	/** @var int */
	public $fk_user_creat = 0;
	/** @var int */
	public $fk_user_modif = 0;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->params = self::defaultParams();
	}

	/**
	 * Settings of a new feed
	 *
	 * @return array<string,mixed>
	 */
	public static function defaultParams()
	{
		return array(
			// Access (FTP, FTPS, SFTP)
			'host' => '',
			'port' => 0,
			'login' => '',
			'password' => '',		// encrypted with dolEncrypt()
			'directory' => '',
			'pattern' => '',		// file name or pattern; for email feeds, the attachment's name pattern
			'passive' => 1,
			'insecure' => 0,		// do not check the server's TLS certificate
			'hostkey' => '',		// SFTP: fingerprint of the server's key (SHA256 base64, or MD5 hex)
			// Access (HTTP)
			'url' => '',
			// File format
			'delimiter' => '',		// '' detect
			'enclosure' => '"',
			'encoding' => 'UTF-8',
			'headerline' => 1,
			'itempath' => '',
			'decimal' => '.',
			'schema_file' => '',	// file in documents/feedimport/feed_<id>/schema
			'schema_type' => '',	// xsd or mapping
			'fieldtypes' => array(),	// feed field => XSD type, from the XSD
			'fields' => array(),	// feed fields known (from the schema or the last file)
			// Import
			'matchkey' => 'supplierref',	// supplierref, ref, barcode
			'create' => 1,
			'refprefix' => '',
			'fk_category' => 0,
			'vat' => '',
			'pricebase' => 'HT',
			'zero' => 1,
			'minratio' => 50,
			'maxnew' => 100,
			'keep' => 7,
			'notify' => '',
			// Email: file received and waiting for the scheduled job (name in files/)
			'inbox' => '',
			'inbox_date' => 0,
		);
	}

	/**
	 * Load a feed
	 *
	 * @param	int		$id		Id
	 * @return	int				1 if found, 0 if not, <0 on error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, label, status, method, filetype, fk_soc, fk_entrepot, schedule_type, schedule_time, schedule_hours,";
		$sql .= " next_run, params, mapping, lock_date, last_run, last_status, last_file_hash, last_count, date_creation, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE rowid = ".((int) $id)." AND entity IN (".getEntity('feedimport').")";
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
		$this->label = (string) $obj->label;
		$this->status = (int) $obj->status;
		$this->method = (string) $obj->method;
		$this->filetype = (string) $obj->filetype;
		$this->fk_soc = (int) $obj->fk_soc;
		$this->fk_entrepot = (int) $obj->fk_entrepot;
		$this->schedule_type = (string) $obj->schedule_type;
		$this->schedule_time = (string) $obj->schedule_time;
		$this->schedule_hours = (int) $obj->schedule_hours;
		$this->next_run = $obj->next_run ? $this->db->jdate($obj->next_run) : null;
		$params = json_decode((string) $obj->params, true);
		$this->params = array_merge(self::defaultParams(), is_array($params) ? $params : array());
		$mapping = json_decode((string) $obj->mapping, true);
		$this->mapping = is_array($mapping) ? $mapping : array();
		$this->lock_date = $obj->lock_date ? $this->db->jdate($obj->lock_date) : null;
		$this->last_run = $obj->last_run ? $this->db->jdate($obj->last_run) : null;
		$this->last_status = (string) $obj->last_status;
		$this->last_file_hash = (string) $obj->last_file_hash;
		$this->last_count = (int) $obj->last_count;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = (int) $obj->fk_user_modif;
		return 1;
	}

	/**
	 * Insert the feed
	 *
	 * @param	User	$user	User
	 * @return	int				Id, or <0 on error
	 */
	public function create($user)
	{
		global $conf;

		$this->entity = (int) $conf->entity;
		$this->date_creation = dol_now();
		$this->next_run = $this->computeNextRun(dol_now());

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."feedimport_feed (entity, label, status, method, filetype, fk_soc, fk_entrepot,";
		$sql .= " schedule_type, schedule_time, schedule_hours, next_run, params, mapping, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($this->label)."', ".((int) $this->status).",";
		$sql .= " '".$this->db->escape($this->method)."', '".$this->db->escape($this->filetype)."',";
		$sql .= " ".($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL').", ".($this->fk_entrepot > 0 ? (int) $this->fk_entrepot : 'NULL').",";
		$sql .= " '".$this->db->escape($this->schedule_type)."', ".($this->schedule_time !== '' ? "'".$this->db->escape($this->schedule_time)."'" : 'NULL').",";
		$sql .= " ".((int) $this->schedule_hours).", ".($this->next_run ? "'".$this->db->idate($this->next_run)."'" : 'NULL').",";
		$sql .= " '".$this->db->escape((string) json_encode($this->params))."', '".$this->db->escape((string) json_encode($this->mapping))."',";
		$sql .= " '".$this->db->idate($this->date_creation)."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'feedimport_feed');
		return $this->id;
	}

	/**
	 * Save the settings (not the run state: see finishRun())
	 *
	 * @param	User	$user	User
	 * @return	int				>0 if OK, <0 on error
	 */
	public function update($user)
	{
		$this->next_run = $this->computeNextRun(dol_now());

		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_feed SET";
		$sql .= " label = '".$this->db->escape($this->label)."'";
		$sql .= ", status = ".((int) $this->status);
		$sql .= ", method = '".$this->db->escape($this->method)."'";
		$sql .= ", filetype = '".$this->db->escape($this->filetype)."'";
		$sql .= ", fk_soc = ".($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL');
		$sql .= ", fk_entrepot = ".($this->fk_entrepot > 0 ? (int) $this->fk_entrepot : 'NULL');
		$sql .= ", schedule_type = '".$this->db->escape($this->schedule_type)."'";
		$sql .= ", schedule_time = ".($this->schedule_time !== '' ? "'".$this->db->escape($this->schedule_time)."'" : 'NULL');
		$sql .= ", schedule_hours = ".((int) $this->schedule_hours);
		$sql .= ", next_run = ".($this->next_run ? "'".$this->db->idate($this->next_run)."'" : 'NULL');
		$sql .= ", params = '".$this->db->escape((string) json_encode($this->params))."'";
		$sql .= ", mapping = '".$this->db->escape((string) json_encode($this->mapping))."'";
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Delete the feed, its items, its runs and its files. Products are kept.
	 *
	 * @return	int		>0 if OK, <0 on error
	 */
	public function delete()
	{
		$this->db->begin();
		foreach (array('feedimport_item', 'feedimport_run') as $table) {
			if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$table." WHERE fk_feed = ".((int) $this->id))) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE rowid = ".((int) $this->id))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		dol_delete_dir_recursive(dirname(feedimportFeedDir($this->id, 'files')));
		return 1;
	}

	/**
	 * Password of the access, decrypted
	 *
	 * @return	string
	 */
	public function getPassword()
	{
		$password = (string) $this->params['password'];
		return $password === '' ? '' : dolDecrypt($password);
	}

	/**
	 * Set the password of the access (stored encrypted)
	 *
	 * @param	string	$password	Clear password
	 * @return	void
	 */
	public function setPassword($password)
	{
		$this->params['password'] = ((string) $password === '') ? '' : dolEncrypt((string) $password);
	}

	/**
	 * Transport to the feed's server
	 *
	 * @return	FeedImportTransport
	 */
	public function getTransport()
	{
		$params = $this->params;
		$params['password'] = $this->getPassword();
		return new FeedImportTransport($this->method, $params);
	}

	/**
	 * Parser set up with the feed's file format
	 *
	 * @return	FeedImportParser
	 */
	public function getParser()
	{
		$parser = new FeedImportParser();
		$parser->filetype = $this->filetype;
		$parser->delimiter = (string) $this->params['delimiter'];
		$parser->enclosure = ((string) $this->params['enclosure'] === '') ? '"' : (string) $this->params['enclosure'];
		$parser->encoding = (string) $this->params['encoding'];
		$parser->headerline = max(1, (int) $this->params['headerline']);
		$parser->itempath = (string) $this->params['itempath'];
		return $parser;
	}

	/**
	 * Path of the XSD of the feed, if it has one
	 *
	 * @return	string	'' if none
	 */
	public function getXsdPath()
	{
		if ($this->filetype != 'xml' || $this->params['schema_type'] != 'xsd' || $this->params['schema_file'] === '') {
			return '';
		}
		$path = feedimportFeedDir($this->id, 'schema').'/'.$this->params['schema_file'];
		return is_file($path) ? $path : '';
	}

	/**
	 * When the scheduled job should run the feed next
	 *
	 * @param	int		$from	Timestamp after which to look
	 * @return	int|null		Null if the feed is not scheduled
	 */
	public function computeNextRun($from)
	{
		if (!in_array($this->method, self::REMOTE_METHODS)) {
			// email and upload feeds are imported when the file arrives: now, if one is waiting
			return ($this->method == 'email' && $this->params['inbox'] !== '') ? $from : null;
		}
		if ($this->schedule_type == 'hours' && $this->schedule_hours > 0) {
			return $from + $this->schedule_hours * 3600;
		}
		if ($this->schedule_type == 'daily' && preg_match('/^(\d{1,2}):(\d{2})$/', $this->schedule_time, $m)) {
			$tz = new DateTimeZone(date_default_timezone_get());
			$date = new DateTime('@'.$from);
			$date->setTimezone($tz);
			$date->setTime((int) $m[1], (int) $m[2], 0);
			if ($date->getTimestamp() <= $from) {
				$date->modify('+1 day');
				$date->setTime((int) $m[1], (int) $m[2], 0);
			}
			return $date->getTimestamp();
		}
		return null;
	}

	/**
	 * Claim the feed for a run: only one run of a feed at a time (cron, run now and email may overlap)
	 *
	 * @return	bool	True if claimed
	 */
	public function claim()
	{
		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_feed SET lock_date = '".$this->db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND (lock_date IS NULL OR lock_date < '".$this->db->idate($now - self::LOCK_TIMEOUT)."')";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) != 1) {
			return false;
		}
		$this->lock_date = $now;
		return true;
	}

	/**
	 * Run the feed: get the file, import it, record the run
	 *
	 * @param	string				$trigger	cron, manual, email, upload
	 * @param	User				$user		User the products are created and updated as
	 * @param	array<string,mixed>	$options	'file' => path of the file to import (email, upload) instead of fetching it,
	 *											'remote_name' => its original name, 'force' => import even if unchanged,
	 *											'forcezero' => zero missing items even if the safety threshold trips
	 * @return	FeedImportRun|null				The run, or null if it could not start (error set)
	 */
	public function run($trigger, $user, $options = array())
	{
		global $langs;

		$langs->load('feedimport@feedimport');

		if (!$this->claim()) {
			$this->error = $langs->transnoentitiesnoconv('FeedImportErrorRunning', $this->label);
			if (!empty($options['file'])) {
				// keep a file received meanwhile, so it can be imported by hand
				$this->storeFile($options['file'], !empty($options['remote_name']) ? $options['remote_name'] : basename($options['file']));
			}
			return null;
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		@ignore_user_abort(true);

		$run = new FeedImportRun($this->db);
		$run->fk_feed = $this->id;
		$run->trigger_type = $trigger;
		if ($run->create($user) < 0) {
			$this->error = $run->error;
			$this->release();
			return null;
		}
		$run->log('Feed "'.$this->label.'" ('.$trigger.')');

		try {
			$this->doRun($run, $user, $options);
		} catch (Throwable $e) {
			$run->log('Unexpected error: '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(), 'error');
			$run->status = FeedImportRun::STATUS_ERROR;
			// a product method may have left a transaction open
			while ($this->db->transaction_opened > 0) {
				$this->db->rollback();
			}
		}

		$run->date_end = dol_now();
		$run->update();
		$this->finishRun($run);
		$this->notify($run);

		return $run;
	}

	/**
	 * The body of a run
	 *
	 * @param	FeedImportRun		$run		Run
	 * @param	User				$user		User
	 * @param	array<string,mixed>	$options	See run()
	 * @return	void
	 */
	private function doRun($run, $user, $options)
	{
		// 1. The file
		if (!empty($options['file'])) {
			$remote = !empty($options['remote_name']) ? (string) $options['remote_name'] : basename($options['file']);
			$file = $this->storeFile($options['file'], $remote);
			if ($file === '') {
				$run->log('Cannot keep the file '.$remote.': '.$this->error, 'error');
				$run->status = FeedImportRun::STATUS_ERROR;
				return;
			}
		} elseif ($this->params['inbox'] !== '' && is_file(feedimportFeedDir($this->id, 'files').'/'.$this->params['inbox'])) {
			// File received by email, waiting
			$file = feedimportFeedDir($this->id, 'files').'/'.$this->params['inbox'];
			$remote = preg_replace('/^\d{8}-\d{6}_/', '', (string) $this->params['inbox']);
			$run->log('File received by email on '.dol_print_date((int) $this->params['inbox_date'], 'dayhour', 'tzserver').': '.$remote);
			$this->setInbox('', 0);
		} elseif (in_array($this->method, self::REMOTE_METHODS)) {
			$transport = $this->getTransport();
			$remote = $transport->selectFile(dol_now());
			if ($remote === null) {
				$run->log($transport->error, 'error');
				$run->status = FeedImportRun::STATUS_ERROR;
				return;
			}
			$file = $this->newFilePath($this->method == 'http' ? basename((string) parse_url($remote, PHP_URL_PATH)) : $remote);
			$size = $transport->download($remote, $file);
			if ($size < 0) {
				$run->log($transport->error, 'error');
				$run->status = FeedImportRun::STATUS_ERROR;
				return;
			}
			$run->log('Downloaded '.($this->method == 'http' ? preg_replace('/\?.*$/', '', $remote) : $remote).' ('.$size.' bytes)');
		} else {
			$run->log($this->method == 'email' ? 'No file received by email is waiting' : 'This feed is imported by uploading its file: nothing to fetch', 'error');
			$run->status = FeedImportRun::STATUS_ERROR;
			return;
		}
		$run->remote_name = ($this->method == 'http' && empty($options['file'])) ? preg_replace('/\?.*$/', '', $remote) : $remote;
		$run->filename = basename($file);
		$run->file_hash = (string) hash_file('sha256', $file);

		if (filesize($file) == 0) {
			$run->log('The file is empty', 'error');
			$run->status = FeedImportRun::STATUS_ERROR;
			return;
		}

		// 2. Same file as last time: nothing to do
		if (empty($options['force']) && $this->last_file_hash !== '' && $run->file_hash === $this->last_file_hash) {
			$run->log('Same file as the last one imported: nothing to do');
			@unlink($file);
			$run->filename = '';
			$run->status = FeedImportRun::STATUS_UNCHANGED;
			return;
		}

		// 3. Import
		$importer = new FeedImportImporter($this->db, $this, $run, $user);
		$completed = $importer->import($file, !empty($options['forcezero']));

		if ($completed) {
			$this->last_file_hash = $run->file_hash;
			if (!$importer->countGuardTripped) {
				$this->last_count = $importer->nbItems;
			}
		}
		if (!$completed || $run->nb_errors > 0 && $run->nb_rows == 0) {
			$run->status = FeedImportRun::STATUS_ERROR;
		} elseif ($run->nb_warnings > 0 || $run->nb_errors > 0 || $run->nb_review > 0 || $importer->guardTripped) {
			$run->status = FeedImportRun::STATUS_WARNING;
		} else {
			$run->status = FeedImportRun::STATUS_OK;
		}
		$run->log($run->summary());
		$this->pruneFiles();
	}

	/**
	 * Save the state of the feed after a run and release it
	 *
	 * @param	FeedImportRun	$run	Run
	 * @return	void
	 */
	private function finishRun($run)
	{
		$now = dol_now();
		$this->last_run = $now;
		$this->last_status = $run->status;
		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_feed SET";
		$sql .= " lock_date = NULL";
		$sql .= ", last_run = '".$this->db->idate($now)."'";
		$sql .= ", last_status = '".$this->db->escape($run->status)."'";
		$sql .= ", last_file_hash = ".($this->last_file_hash !== '' ? "'".$this->db->escape($this->last_file_hash)."'" : 'NULL');
		$sql .= ", last_count = ".((int) $this->last_count);
		// Email feeds: next_run is set when a file arrives (queueFile()), possibly during this run
		if (in_array($this->method, self::REMOTE_METHODS)) {
			$this->next_run = $this->computeNextRun($now);
			$sql .= ", next_run = ".($this->next_run ? "'".$this->db->idate($this->next_run)."'" : 'NULL');
		}
		$sql .= " WHERE rowid = ".((int) $this->id);
		$this->db->query($sql);
		$this->lock_date = null;
	}

	/**
	 * Keep a file received by email until the scheduled job imports it.
	 *
	 * Called from Email Collector's hook, inside the transaction Email Collector opens for each email: importing
	 * there would make one huge transaction, rolled back by Email Collector's test mode. The file is imported by
	 * the next run of the scheduled job (or Run now).
	 *
	 * @param	string	$content	Content of the file
	 * @param	string	$name		Name of the attachment
	 * @return	int					>0 if OK, <0 on error
	 */
	public function queueFile($content, $name)
	{
		$path = $this->newFilePath($name);
		if (file_put_contents($path, $content) === false) {
			$this->error = 'Cannot write '.$path;
			return -1;
		}
		if ($this->params['inbox'] !== '' && $this->params['inbox'] !== basename($path)) {
			dol_syslog('FeedImport: feed '.$this->id.' received '.basename($path).' before '.$this->params['inbox'].' was imported: the newer file replaces it', LOG_WARNING);
		}
		return $this->setInbox(basename($path), dol_now()) ? 1 : -1;
	}

	/**
	 * Set the file waiting to be imported, and make the feed due now
	 *
	 * @param	string	$filename	File in files/, '' for none
	 * @param	int		$date		When it was received
	 * @return	bool
	 */
	private function setInbox($filename, $date)
	{
		$this->params['inbox'] = $filename;
		$this->params['inbox_date'] = $date;
		$sql = "UPDATE ".MAIN_DB_PREFIX."feedimport_feed SET params = '".$this->db->escape((string) json_encode($this->params))."'";
		$sql .= ", next_run = ".($filename !== '' ? "'".$this->db->idate(dol_now())."'" : 'NULL');
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}
		return true;
	}

	/**
	 * Release the feed without recording a run
	 *
	 * @return	void
	 */
	private function release()
	{
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."feedimport_feed SET lock_date = NULL WHERE rowid = ".((int) $this->id));
		$this->lock_date = null;
	}

	/**
	 * Path for a new file of the feed: files/<date>_<name>
	 *
	 * @param	string	$name	Original name
	 * @return	string
	 */
	private function newFilePath($name)
	{
		$name = dol_sanitizeFileName(basename((string) $name));
		if ($name === '' || $name === '.') {
			$name = 'feed.'.$this->filetype;
		}
		return feedimportFeedDir($this->id, 'files').'/'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver').'_'.$name;
	}

	/**
	 * Copy a file received (email, upload) into the feed's files
	 *
	 * @param	string	$source		Path
	 * @param	string	$name		Original name
	 * @return	string				Path of the copy, '' on error
	 */
	public function storeFile($source, $name)
	{
		$target = $this->newFilePath($name);
		if (realpath($source) === realpath(dirname($target)).'/'.basename($source)) {
			return $source;	// already there
		}
		if (!@copy($source, $target)) {
			$this->error = 'Cannot copy to '.$target;
			return '';
		}
		return $target;
	}

	/**
	 * Delete the oldest files of the feed beyond the number to keep
	 *
	 * @return	void
	 */
	private function pruneFiles()
	{
		$keep = max(1, (int) $this->params['keep']);
		$files = dol_dir_list(feedimportFeedDir($this->id, 'files'), 'files', 0, '', '', 'name', SORT_DESC);
		foreach (array_slice($files, $keep) as $file) {
			dol_delete_file($file['fullname'], 0, 0, 0, null, false, 0);
		}
	}

	/**
	 * Email the address of the feed when a run fails or needs a look
	 *
	 * @param	FeedImportRun	$run	Run
	 * @return	void
	 */
	private function notify($run)
	{
		global $langs;

		$to = trim((string) $this->params['notify']);
		if ($to === '' || !in_array($run->status, array(FeedImportRun::STATUS_ERROR, FeedImportRun::STATUS_WARNING))) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$subject = '['.$langs->transnoentitiesnoconv('FeedImportMenu').'] '.$this->label.': '.$langs->transnoentitiesnoconv('FeedImportRunStatus_'.$run->status);
		$url = dol_buildpath('/feedimport/run.php', 2).'?id='.$run->id;
		$log = $run->log;
		if (strlen($log) > 20000) {
			$log = substr($log, 0, 10000)."\n[...]\n".substr($log, -10000);
		}
		$message = $run->summary()."\n\n".$url."\n\n".$log;
		$mail = new CMailFile($subject, $to, getDolGlobalString('MAIN_MAIL_EMAIL_FROM'), $message, array(), array(), array(), '', '', 0, 0, '', '', 'feedimport'.$run->id, '', 'standard');
		if (!$mail->sendfile()) {
			dol_syslog('FeedImport: failed to send the notification to '.$to.': '.$mail->error, LOG_WARNING);
		}
	}

	/**
	 * Scheduled job: run the feeds whose time has come
	 *
	 * @return	int		0 if OK, 1 if a feed failed (the job shows the output)
	 */
	public function cronRunDue()
	{
		global $conf, $user, $langs;

		$langs->load('feedimport@feedimport');
		$this->output = '';
		$failed = 0;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."feedimport_feed";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = 1";
		$sql .= " AND method IN ('".implode("','", array_merge(self::REMOTE_METHODS, array('email')))."')";
		$sql .= " AND next_run IS NOT NULL AND next_run <= '".$this->db->idate(dol_now())."'";
		$sql .= " ORDER BY next_run";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = $this->error;
			return 1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}

		foreach ($ids as $id) {
			$feed = new FeedImportFeed($this->db);
			if ($feed->fetch($id) <= 0) {
				continue;
			}
			$run = $feed->run('cron', $user);
			if (!$run) {
				$this->output .= $feed->label.': '.$feed->error."\n";
				continue;
			}
			$this->output .= $feed->label.': '.$langs->transnoentitiesnoconv('FeedImportRunStatus_'.$run->status).' - '.$run->summary()."\n";
			if ($run->status == FeedImportRun::STATUS_ERROR) {
				$failed++;
			}
		}
		if (empty($ids)) {
			$this->output = $langs->transnoentitiesnoconv('FeedImportNothingDue');
		}
		return $failed ? 1 : 0;
	}

	/**
	 * Feeds of the entity
	 *
	 * @param	DoliDB	$db			Database
	 * @param	string	$method		Only this method ('' all)
	 * @return	array<int,string>	id => label
	 */
	public static function listFeeds($db, $method = '')
	{
		global $conf;

		$feeds = array();
		$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE entity = ".((int) $conf->entity);
		if ($method !== '') {
			$sql .= " AND method = '".$db->escape($method)."'";
		}
		$sql .= " ORDER BY label";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$feeds[(int) $obj->rowid] = (string) $obj->label;
			}
		}
		return $feeds;
	}
}
