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
 * \defgroup   feedimport     Module Feed Import
 * \brief      Imports supplier product feeds (CSV, XML) received by FTP, SFTP, FTPS, HTTP or email into products.
 *
 * \file       feedimport/core/modules/modFeedImport.class.php
 * \ingroup    feedimport
 * \brief      Description and activation file for module Feed Import
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Feed Import
 */
class modFeedImport extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// 500000-599999 is the range for modules not published on DoliStore (see docs/maintenance.md)
		$this->numero = 591200;
		$this->rights_class = 'feedimport';
		$this->family = 'products';
		$this->module_position = '92';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleFeedImportDesc';
		$this->descriptionlong = 'ModuleFeedImportDesc';
		$this->editor_name = 'Infomatical';
		$this->editor_url = '';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-import';

		$this->module_parts = array(
			// 'emailcollectorcard': adds the operation "Feed import" to the operations of an email collector rule
			// 'emailcollector': receives the attachments when that operation runs (doCollectImapOneCollector)
			'hooks' => array(
				'emailcollectorcard',
				'emailcollector',
			),
			'moduleforexternal' => 0,
		);

		// documents/feedimport/feed_<id>/files (feeds received) and /schema (XSD or mapping file)
		$this->dirs = array('/feedimport/temp');
		$this->config_page_url = array('setup.php@feedimport');

		$this->hidden = false;
		$this->depends = array('modProduct');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('feedimport@feedimport');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(24, 0);

		$this->const = array();

		if (!isModEnabled('feedimport')) {
			$conf->feedimport = new stdClass();
			$conf->feedimport->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Runs the feeds whose scheduled time has come (each feed has its own schedule)
		$this->cronjobs = array(
			0 => array(
				'label' => 'FeedImportCronRun',
				'jobtype' => 'method',
				'class' => '/feedimport/class/feedimportfeed.class.php',
				'objectname' => 'FeedImportFeed',
				'method' => 'cronRunDue',
				'parameters' => '',
				'comment' => 'FeedImportCronRunHelp',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("feedimport")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero * 100 + 1;
		$this->rights[$r][1] = 'See supplier feed imports and the review queue';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + 2;
		$this->rights[$r][1] = 'Run supplier feeds and resolve the review queue';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left',
			'titre' => 'FeedImportMenu',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'products',
			'leftmenu' => 'feedimport',
			'url' => '/feedimport/runs.php',
			'langs' => 'feedimport@feedimport',
			'position' => 1200,
			'enabled' => 'isModEnabled("feedimport")',
			'perms' => '$user->hasRight("feedimport", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=feedimport',
			'type' => 'left',
			'titre' => 'FeedImportRuns',
			'mainmenu' => 'products',
			'leftmenu' => 'feedimport_runs',
			'url' => '/feedimport/runs.php',
			'langs' => 'feedimport@feedimport',
			'position' => 1201,
			'enabled' => 'isModEnabled("feedimport")',
			'perms' => '$user->hasRight("feedimport", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=feedimport',
			'type' => 'left',
			'titre' => 'FeedImportReview',
			'mainmenu' => 'products',
			'leftmenu' => 'feedimport_review',
			'url' => '/feedimport/review.php',
			'langs' => 'feedimport@feedimport',
			'position' => 1202,
			'enabled' => 'isModEnabled("feedimport")',
			'perms' => '$user->hasRight("feedimport", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=feedimport',
			'type' => 'left',
			'titre' => 'FeedImportSetupMenu',
			'mainmenu' => 'products',
			'leftmenu' => 'feedimport_setup',
			'url' => '/feedimport/admin/setup.php',
			'langs' => 'feedimport@feedimport',
			'position' => 1203,
			'enabled' => 'isModEnabled("feedimport")',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		// _load_tables() returns 0, not a negative value, when an SQL file fails
		$result = $this->_load_tables('/feedimport/sql/');
		if ($result <= 0) {
			return -1;
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
