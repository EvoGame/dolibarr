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
 * \file    feedimport/class/feedimporttransport.class.php
 * \ingroup feedimport
 * \brief   Fetching a feed's file from an FTP, FTPS, SFTP or HTTP(S) server
 */

dol_include_once('/feedimport/lib/feedimport.lib.php');

/**
 * Fetches the file of a feed with PHP's curl, which speaks every protocol the module offers.
 *
 * Core's dol_ftp_connect() is not used: it chooses FTP, FTPS or SFTP from global settings, while each feed needs
 * its own, and its SFTP needs the ssh2 extension.
 */
class FeedImportTransport
{
	/** @var string Last error */
	public $error = '';

	/** @var string Protocol: ftp, ftpes (explicit TLS), ftps (implicit TLS), sftp, http */
	public $method;
	/** @var array<string,mixed> Access settings of the feed (host, port, login, password, directory, pattern, url...) */
	public $params;

	/** @var int Seconds to connect */
	public $connecttimeout = 30;
	/** @var int Seconds for a whole transfer */
	public $timeout = 900;

	/**
	 * Constructor
	 *
	 * @param	string					$method		Protocol
	 * @param	array<string,mixed>		$params		Access settings, password decrypted
	 */
	public function __construct($method, $params)
	{
		$this->method = $method;
		$this->params = $params;
	}

	/**
	 * Whether this PHP can use the protocol
	 *
	 * @param	string	$method		Protocol
	 * @return	bool
	 */
	public static function isSupported($method)
	{
		if (!function_exists('curl_init')) {
			return false;
		}
		$version = curl_version();
		$protocols = isset($version['protocols']) ? $version['protocols'] : array();
		$needed = array('ftp' => 'ftp', 'ftpes' => 'ftp', 'ftps' => 'ftps', 'sftp' => 'sftp', 'http' => 'https');
		return isset($needed[$method]) && in_array($needed[$method], $protocols);
	}

	/**
	 * Check the access: list the directory (FTP/SFTP) or ask for the file's headers (HTTP)
	 *
	 * @return	string[]|null		File names found (HTTP: the file), null on error
	 */
	public function test()
	{
		if ($this->method == 'http') {
			$info = $this->remoteInfo($this->httpUrl());
			if ($info === null) {
				return null;
			}
			return array(basename((string) parse_url($this->httpUrl(), PHP_URL_PATH)));
		}
		return $this->listFiles();
	}

	/**
	 * Name of the file to fetch: the pattern itself if it has no wildcard, else the newest matching file
	 *
	 * @param	int		$now	Date for the date tokens
	 * @return	string|null		Null if none (error set)
	 */
	public function selectFile($now)
	{
		if ($this->method == 'http') {
			return $this->httpUrl($now);
		}
		$pattern = feedimportExpandDateTokens(trim((string) $this->params['pattern']), $now);
		if ($pattern === '') {
			$this->error = 'No file name or pattern is set';
			return null;
		}
		if (!preg_match('/[*?\[]/', $pattern)) {
			return $pattern;
		}
		$files = $this->listFiles();
		if ($files === null) {
			return null;
		}
		$candidates = array();
		foreach ($files as $name) {
			if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
				$candidates[] = $name;
			}
		}
		if (empty($candidates)) {
			$this->error = 'No file matching '.$pattern.' in '.$this->dirLabel().' ('.count($files).' files there)';
			return null;
		}
		// Newest by modification date; by name (dates in names sort) when the server does not give dates
		$best = null;
		$besttime = -2;
		sort($candidates);
		foreach ($candidates as $name) {
			$info = (count($candidates) > 1) ? $this->remoteInfo($this->fileUrl($name)) : null;
			$time = ($info && $info['filetime'] > 0) ? (int) $info['filetime'] : -1;
			if ($time >= $besttime) {
				$best = $name;
				$besttime = $time;
			}
		}
		return $best;
	}

	/**
	 * Download a file
	 *
	 * @param	string	$name		Name returned by selectFile()
	 * @param	string	$localfile	Where to write it
	 * @return	int					Bytes written, or <0 on error
	 */
	public function download($name, $localfile)
	{
		$url = ($this->method == 'http') ? $name : $this->fileUrl($name);
		$fh = @fopen($localfile, 'w');
		if (!$fh) {
			$this->error = 'Cannot write '.$localfile;
			return -1;
		}
		$ch = $this->curl($url);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		$ok = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = self::curlError($ch);
		curl_close($ch);
		fclose($fh);
		if ($ok === false || ($this->method == 'http' && $code >= 400)) {
			$this->error = 'Download of '.$this->safeUrl($url).' failed: '.($error ? $error : 'HTTP '.$code);
			@unlink($localfile);
			return -1;
		}
		return (int) filesize($localfile);
	}

	/**
	 * MD5 fingerprint of the SFTP server's key, learnt by connecting with a fingerprint that cannot match:
	 * libcurl refuses the connection and names the server's fingerprint. Nothing is sent but the SSH handshake.
	 *
	 * @return	string	Fingerprint as aa:bb:..., '' if it cannot be learnt
	 */
	public function probeHostKeyMd5()
	{
		if ($this->method != 'sftp') {
			return '';
		}
		$probe = new self('sftp', array_merge($this->params, array('hostkey' => str_repeat('0', 32), 'login' => '', 'password' => '')));
		$ch = $probe->curl($probe->dirUrl());
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
		curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);
		if (preg_match('/Remote ([0-9a-f]{32})/i', $error, $m)) {
			return implode(':', str_split(strtolower($m[1]), 2));
		}
		return '';
	}

	/**
	 * Fingerprint as the 32 hexadecimal characters libcurl wants, if it is an MD5 one (aa:bb:..., MD5:aa:bb:..., aabb...)
	 *
	 * @param	string	$fingerprint	Fingerprint as typed
	 * @return	string					'' if not MD5
	 */
	public static function md5Fingerprint($fingerprint)
	{
		$hex = strtolower(str_replace(':', '', preg_replace('/^MD5:/i', '', trim((string) $fingerprint))));
		return preg_match('/^[0-9a-f]{32}$/', $hex) ? $hex : '';
	}

	/**
	 * Whether this PHP's libcurl can check SHA256 fingerprints of SFTP servers (libssh2 builds only)
	 *
	 * @return	bool
	 */
	public static function canCheckSha256()
	{
		if (!function_exists('curl_version') || !defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
			return false;
		}
		$version = curl_version();
		return isset($version['libssh_version']) && stripos((string) $version['libssh_version'], 'libssh2') !== false;
	}

	/**
	 * Error of a curl handle: its message, else the description of its code
	 *
	 * @param	CurlHandle|resource	$ch		Handle
	 * @return	string
	 */
	private static function curlError($ch)
	{
		$error = curl_error($ch);
		if ($error === '' && curl_errno($ch)) {
			$error = curl_strerror(curl_errno($ch));
		}
		return $error;
	}

	/**
	 * Names of the files in the directory
	 *
	 * @return	string[]|null	Null on error
	 */
	public function listFiles()
	{
		$url = $this->dirUrl();
		$ch = $this->curl($url);
		curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out = curl_exec($ch);
		$error = self::curlError($ch);
		curl_close($ch);
		if ($out === false) {
			$this->error = 'Cannot list '.$this->safeUrl($url).': '.$error;
			return null;
		}
		$files = array();
		foreach (preg_split('/\r?\n/', (string) $out) as $line) {
			$name = basename(trim($line));
			if ($name !== '' && $name !== '.' && $name !== '..') {
				$files[] = $name;
			}
		}
		return $files;
	}

	/**
	 * Size and date of a remote file, without downloading it
	 *
	 * @param	string	$url	URL
	 * @return	array{size:int,filetime:int}|null
	 */
	private function remoteInfo($url)
	{
		$ch = $this->curl($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_FILETIME, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$ok = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$info = array('size' => (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD), 'filetime' => (int) curl_getinfo($ch, CURLINFO_FILETIME));
		$error = self::curlError($ch);
		curl_close($ch);
		if ($ok === false || ($this->method == 'http' && $code >= 400)) {
			$this->error = 'Cannot reach '.$this->safeUrl($url).': '.($error ? $error : 'HTTP '.$code);
			return null;
		}
		return $info;
	}

	/**
	 * A curl handle with the feed's connection settings
	 *
	 * @param	string	$url	URL
	 * @return	CurlHandle|resource
	 */
	private function curl($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connecttimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		$login = (string) (isset($this->params['login']) ? $this->params['login'] : '');
		$password = (string) (isset($this->params['password']) ? $this->params['password'] : '');
		if ($login !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, $login.':'.$password);
		}
		$insecure = !empty($this->params['insecure']);

		switch ($this->method) {
			case 'http':
				curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
				curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
				curl_setopt($ch, CURLOPT_USERAGENT, 'Dolibarr feedimport');
				if (getDolGlobalString('MAIN_PROXY_USE')) {
					curl_setopt($ch, CURLOPT_PROXY, getDolGlobalString('MAIN_PROXY_HOST').':'.getDolGlobalString('MAIN_PROXY_PORT'));
					if (getDolGlobalString('MAIN_PROXY_USER')) {
						curl_setopt($ch, CURLOPT_PROXYUSERPWD, getDolGlobalString('MAIN_PROXY_USER').':'.getDolGlobalString('MAIN_PROXY_PASS'));
					}
				}
				break;
			case 'sftp':
				curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
				$fingerprint = trim((string) (isset($this->params['hostkey']) ? $this->params['hostkey'] : ''));
				$md5 = self::md5Fingerprint($fingerprint);
				if ($md5 !== '') {
					curl_setopt($ch, CURLOPT_SSH_HOST_PUBLIC_KEY_MD5, $md5);
				} elseif ($fingerprint !== '') {
					// SHA256 is only checked by a libcurl built with libssh2. With libssh, the connection is refused
					// (no known_hosts entry): it fails closed, and the setup page asks for the MD5 fingerprint.
					if (defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
						curl_setopt($ch, constant('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256'), preg_replace('/^SHA256:/i', '', $fingerprint));
					}
				} else {
					// No fingerprint: the server's key is not checked (libcurl would otherwise need it in ~/.ssh/known_hosts)
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
				}
				break;
			default:	// ftp, ftpes, ftps
				curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
				if ($this->method == 'ftpes') {
					curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
				}
				if (empty($this->params['passive'])) {
					curl_setopt($ch, CURLOPT_FTPPORT, '-');
				}
				break;
		}
		if ($insecure) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		}
		return $ch;
	}

	/**
	 * URL of the directory, ending with /
	 *
	 * @return	string
	 */
	private function dirUrl()
	{
		$schemes = array('ftp' => 'ftp', 'ftpes' => 'ftp', 'ftps' => 'ftps', 'sftp' => 'sftp');
		$defaultports = array('ftp' => 21, 'ftpes' => 21, 'ftps' => 990, 'sftp' => 22);
		$host = trim((string) $this->params['host']);
		$port = (int) (isset($this->params['port']) ? $this->params['port'] : 0);
		$url = $schemes[$this->method].'://'.$host.($port > 0 && $port != $defaultports[$this->method] ? ':'.$port : '').'/';

		$dir = trim(str_replace('\\', '/', (string) (isset($this->params['directory']) ? $this->params['directory'] : '')));
		$absolute = (strpos($dir, '/') === 0);
		$segments = array();
		foreach (explode('/', $dir) as $segment) {
			if ($segment !== '' && $segment !== '.') {
				$segments[] = rawurlencode($segment);
			}
		}
		if ($this->method == 'sftp') {
			// SFTP paths are from the root; a relative directory is from the login's home
			$url .= ($absolute ? '' : '~/');
		} elseif ($absolute) {
			// FTP paths are relative to the login directory; %2F starts from the root
			$url .= '%2F';
		}
		return $url.(empty($segments) ? '' : implode('/', $segments).'/');
	}

	/**
	 * URL of a file of the directory
	 *
	 * @param	string	$name	File name
	 * @return	string
	 */
	private function fileUrl($name)
	{
		return $this->dirUrl().rawurlencode(basename($name));
	}

	/**
	 * URL of an HTTP feed, with its date tokens replaced
	 *
	 * @param	?int	$now	Date for the tokens (default now)
	 * @return	string
	 */
	private function httpUrl($now = null)
	{
		return feedimportExpandDateTokens(trim((string) $this->params['url']), $now === null ? dol_now() : $now);
	}

	/**
	 * Directory, for messages
	 *
	 * @return	string
	 */
	private function dirLabel()
	{
		return $this->safeUrl($this->dirUrl());
	}

	/**
	 * URL without its query string (which may hold a key), for messages
	 *
	 * @param	string	$url	URL
	 * @return	string
	 */
	private function safeUrl($url)
	{
		return preg_replace('/\?.*$/', '?…', rawurldecode($url));
	}
}
