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
 * \file    feedimport/lib/feedimport.lib.php
 * \ingroup feedimport
 * \brief   Library functions of module Feed Import
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Tabs of the module setup pages: the feeds list, one tab per feed, About
 *
 * @return array<array{string,string,string}>
 */
function feedimportAdminPrepareHead()
{
	global $db, $langs, $conf;

	$langs->load('feedimport@feedimport');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/feedimport/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('FeedImportFeeds');
	$head[$h][2] = 'feeds';
	$h++;

	$sql = "SELECT rowid, label, status FROM ".MAIN_DB_PREFIX."feedimport_feed";
	$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY rowid";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$head[$h][0] = dol_buildpath('/feedimport/admin/feed.php', 1).'?id='.((int) $obj->rowid);
			$head[$h][1] = dol_escape_htmltag($obj->label).($obj->status ? '' : ' <span class="opacitymedium">('.$langs->trans('Disabled').')</span>');
			$head[$h][2] = 'feed'.((int) $obj->rowid);
			$h++;
		}
	}

	$head[$h][0] = dol_buildpath('/feedimport/admin/feed.php', 1).'?action=create';
	$head[$h][1] = img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('FeedImportNewFeedTab');
	$head[$h][2] = 'create';
	$h++;

	$head[$h][0] = dol_buildpath('/feedimport/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'feedimport@feedimport');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'feedimport@feedimport', 'remove');

	return $head;
}

/**
 * Dolibarr fields a feed field can be mapped to, with their label. Product extrafields are added as options_<code>.
 *
 * @param	bool	$withextrafields	Add the product extrafields
 * @return	array<string,string>		code => translated label
 */
function feedimportTargetFields($withextrafields = true)
{
	global $db, $langs;

	$langs->loadLangs(array('products', 'stocks', 'feedimport@feedimport'));

	$fields = array(
		'sku' => $langs->trans('FeedImportFieldSku'),
		'ref' => $langs->trans('FeedImportFieldRef'),
		'label' => $langs->trans('Label'),
		'description' => $langs->trans('Description'),
		'barcode' => $langs->trans('BarCode'),
		'weight' => $langs->trans('FeedImportFieldWeight'),
		'sell_price' => $langs->trans('FeedImportFieldSellPrice'),
		'cost_price' => $langs->trans('CostPrice'),
		'buy_price' => $langs->trans('FeedImportFieldBuyPrice'),
		'min_qty' => $langs->trans('FeedImportFieldMinQty'),
		'vat_rate' => $langs->trans('VATRate'),
		'stock' => $langs->trans('FeedImportFieldStock'),
	);

	if ($withextrafields) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('product');
		if (!empty($extrafields->attributes['product']['label'])) {
			foreach ($extrafields->attributes['product']['label'] as $code => $label) {
				if (in_array($extrafields->attributes['product']['type'][$code], array('separate'))) {
					continue;
				}
				$fields['options_'.$code] = $langs->trans('FeedImportExtrafield').': '.$langs->trans($label);
			}
		}
	}

	return $fields;
}

/**
 * Code of a Dolibarr field from what a mapping file or an XSD annotation gives: a code (sku, buy_price...) or a label
 *
 * @param	string					$text		Code or label, any case
 * @param	array<string,string>	$fields		feedimportTargetFields()
 * @return	string							Code, or '' if none matches
 */
function feedimportResolveTargetField($text, $fields)
{
	$text = trim((string) $text);
	if ($text === '') {
		return '';
	}
	$lower = dol_strtolower($text);
	foreach ($fields as $code => $label) {
		if ($lower === strtolower($code) || $lower === dol_strtolower($label)) {
			return $code;
		}
	}
	// "options_" may be left out for an extrafield
	if (isset($fields['options_'.$lower])) {
		return 'options_'.$lower;
	}
	return '';
}

/**
 * Key used to compare SKUs regardless of case, spaces, punctuation and leading zeros: "AB-0123 " and "ab123" give "AB123"
 *
 * @param	string	$sku	SKU as found in the feed
 * @return	string
 */
function feedimportNormaliseKey($sku)
{
	// Leading zeros of each run of digits: AB-0123 = AB123, 000123 = 123
	$key = preg_replace('/(?<!\d)0+(?=\d)/', '', dol_strtoupper(trim((string) $sku)));
	return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $key);
}

/**
 * Number from a feed value: "12.50", "12,50" (with ',' as decimal separator), "1 234,50", "£12.50", ">100", "12.5 kg"
 *
 * @param	string			$value		Value as found in the feed
 * @param	string			$decimal	Decimal separator of the feed: '.' or ','
 * @return	float|null					Null when it is not a number (an empty value included)
 */
function feedimportParseNumber($value, $decimal = '.')
{
	$value = trim(str_replace(array("\xC2\xA0", "\xE2\x80\xAF", ' ', "'"), '', (string) $value));
	// Currency signs and units around the number, and stock written as ">100", "<5", "+20", "~10"
	$value = preg_replace('/^[^\d\-,.]*([\-]?)[^\d\-,.]*/u', '$1', $value);
	$value = preg_replace('/[^\d,.]+$/u', '', (string) $value);
	if ($value === '' || $value === '-') {
		return null;
	}
	if ($decimal == ',') {
		$value = str_replace('.', '', $value);
		$value = str_replace(',', '.', $value);
	} else {
		$value = str_replace(',', '', $value);
	}
	if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
		return null;
	}
	return (float) $value;
}

/**
 * Remote file name with its date tokens replaced: {YYYY} {YY} {MM} {DD} {YYYYMMDD}
 *
 * @param	string	$pattern	File name or pattern, e.g. stock_{YYYYMMDD}.csv
 * @param	int		$time		Timestamp of the date to use
 * @return	string
 */
function feedimportExpandDateTokens($pattern, $time)
{
	return strtr((string) $pattern, array(
		'{YYYYMMDD}' => dol_print_date($time, '%Y%m%d', 'tzserver'),
		'{YYYY}' => dol_print_date($time, '%Y', 'tzserver'),
		'{YY}' => dol_print_date($time, '%y', 'tzserver'),
		'{MM}' => dol_print_date($time, '%m', 'tzserver'),
		'{DD}' => dol_print_date($time, '%d', 'tzserver'),
	));
}

/**
 * Directory of the files of a feed (documents/feedimport/feed_<id>/<sub>), created if missing
 *
 * @param	int		$feedid		Feed id
 * @param	string	$sub		'files' (feeds received) or 'schema'
 * @return	string				Full path, without trailing slash
 */
function feedimportFeedDir($feedid, $sub)
{
	global $conf;

	$base = !empty($conf->feedimport->dir_output) ? $conf->feedimport->dir_output : DOL_DATA_ROOT.'/feedimport';
	$dir = $base.'/feed_'.((int) $feedid).'/'.$sub;
	if (!dol_is_dir($dir)) {
		dol_mkdir($dir);
	}
	return $dir;
}

/**
 * Badge of the status of a run
 *
 * @param	string	$status		FeedImportRun::STATUS_*
 * @return	string				HTML
 */
function feedimportRunStatusBadge($status)
{
	global $langs;

	$types = array('running' => 'status1', 'ok' => 'status4', 'unchanged' => 'status0', 'warning' => 'status1', 'error' => 'status8');
	$label = $langs->trans('FeedImportRunStatus_'.$status);
	return dolGetStatus($label, $label, '', isset($types[$status]) ? $types[$status] : 'status0', 5);
}

/**
 * When a feed runs, in words
 *
 * @param	FeedImportFeed	$feed	Feed
 * @return	string
 */
function feedimportScheduleLabel($feed)
{
	global $langs;

	if ($feed->method == 'email') {
		return $langs->trans('FeedImportScheduleEmail');
	}
	if ($feed->method == 'upload') {
		return $langs->trans('FeedImportScheduleUpload');
	}
	if ($feed->schedule_type == 'daily' && $feed->schedule_time !== '') {
		return $langs->trans('FeedImportScheduleDailyAt', $feed->schedule_time);
	}
	if ($feed->schedule_type == 'hours' && $feed->schedule_hours > 0) {
		return $langs->trans('FeedImportScheduleEveryHours', $feed->schedule_hours);
	}
	return $langs->trans('FeedImportScheduleManual');
}

/**
 * What prevents the feeds from working, given the modules enabled and the PHP extensions
 *
 * @return	string[]	Translated messages (HTML)
 */
function feedimportSetupProblems()
{
	global $db, $conf, $langs;

	$langs->load('feedimport@feedimport');
	$problems = array();
	$methods = array();
	$resql = $db->query("SELECT DISTINCT method FROM ".MAIN_DB_PREFIX."feedimport_feed WHERE status = 1 AND entity = ".((int) $conf->entity));
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$methods[] = (string) $obj->method;
		}
	}

	if (!isModEnabled('cron')) {
		$problems[] = $langs->trans('FeedImportProblemCron');
	}
	if (!isModEnabled('stock')) {
		$problems[] = $langs->trans('FeedImportProblemStock');
	}
	if (!isModEnabled('supplier_order') && !isModEnabled('supplier_invoice') && !isModEnabled('fournisseur')) {
		$problems[] = $langs->trans('FeedImportProblemSupplier');
	}
	if (in_array('email', $methods) && !isModEnabled('emailcollector')) {
		$problems[] = $langs->trans('FeedImportProblemEmailCollector');
	}
	if (!function_exists('curl_init')) {
		$problems[] = $langs->trans('FeedImportProblemCurl');
	} else {
		dol_include_once('/feedimport/class/feedimporttransport.class.php');
		foreach (array_intersect($methods, array('ftp', 'ftpes', 'ftps', 'sftp', 'http')) as $method) {
			if (!FeedImportTransport::isSupported($method)) {
				$problems[] = $langs->trans('FeedImportProblemProtocol', $langs->transnoentitiesnoconv('FeedImportMethod_'.$method));
			}
		}
	}
	if (!class_exists('XMLReader')) {
		$problems[] = $langs->trans('FeedImportProblemXml');
	}
	return $problems;
}

/**
 * Table of the last runs (of one feed or of all)
 *
 * @param	DoliDB	$db			Database
 * @param	int		$feedid		Feed, 0 for all
 * @param	int		$limit		Number of runs
 * @param	string	$status		Only runs with this status ('' all)
 * @param	int		$offset		First run shown
 * @return	string				HTML
 */
function feedimportRunsTable($db, $feedid, $limit, $status = '', $offset = 0)
{
	global $conf, $langs;

	$out = '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	$out .= '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td>';
	if (!$feedid) {
		$out .= '<td>'.$langs->trans('FeedImportFeed').'</td>';
	}
	$out .= '<td>'.$langs->trans('FeedImportTrigger').'</td><td>'.$langs->trans('File').'</td>';
	foreach (array('FeedImportNbRows', 'FeedImportNbCreated', 'FeedImportNbUpdated', 'FeedImportNbReview', 'FeedImportNbZeroed', 'FeedImportNbErrors') as $key) {
		$out .= '<td class="right">'.$langs->trans($key).'</td>';
	}
	$out .= '<td class="center">'.$langs->trans('Status').'</td></tr>';

	$sql = "SELECT r.rowid, r.fk_feed, r.trigger_type, r.status, r.remote_name, r.nb_rows, r.nb_created, r.nb_updated, r.nb_review, r.nb_zeroed,";
	$sql .= " r.nb_errors, r.nb_warnings, r.date_start, f.label";
	$sql .= " FROM ".MAIN_DB_PREFIX."feedimport_run as r INNER JOIN ".MAIN_DB_PREFIX."feedimport_feed as f ON f.rowid = r.fk_feed";
	$sql .= " WHERE r.entity = ".((int) $conf->entity).($feedid ? " AND r.fk_feed = ".((int) $feedid) : "");
	if ($status !== '') {
		$sql .= " AND r.status = '".$db->escape($status)."'";
	}
	$sql .= $db->order('r.date_start,r.rowid', 'DESC,DESC').$db->plimit($limit, $offset);
	$resql = $db->query($sql);
	$n = 0;
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$n++;
		$url = dol_buildpath('/feedimport/run.php', 1).'?id='.((int) $obj->rowid);
		$out .= '<tr class="oddeven"><td class="nowraponall"><a href="'.$url.'">'.img_picto('', 'fa-file-import', 'class="pictofixedwidth"').dol_print_date($db->jdate($obj->date_start), 'dayhour', 'tzuserrel').'</a></td>';
		if (!$feedid) {
			$out .= '<td><a href="'.dol_buildpath('/feedimport/admin/feed.php', 1).'?id='.((int) $obj->fk_feed).'">'.dol_escape_htmltag($obj->label).'</a></td>';
		}
		$out .= '<td>'.$langs->trans('FeedImportTrigger_'.$obj->trigger_type).'</td>';
		$out .= '<td class="tdoverflowmax200" title="'.dol_escape_htmltag((string) $obj->remote_name).'">'.dol_escape_htmltag((string) $obj->remote_name).'</td>';
		foreach (array('nb_rows', 'nb_created', 'nb_updated', 'nb_review', 'nb_zeroed', 'nb_errors') as $counter) {
			$out .= '<td class="right">'.((int) $obj->$counter ? (int) $obj->$counter : '<span class="opacitymedium">0</span>').'</td>';
		}
		$out .= '<td class="center">'.feedimportRunStatusBadge((string) $obj->status).'</td></tr>';
	}
	if ($n == 0) {
		$out .= '<tr class="oddeven"><td colspan="12"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	$out .= '</table></div>';
	return $out;
}
