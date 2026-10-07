<?php

namespace XcVm\Module\Plex;

/**
 * PlexClient — HTTP + XML access to a Plex Media Server, shared by the cron,
 * the item workers and the admin pages.
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexClient {

	/**
	 * A server URL: http://ip:port<path>?[<query>&]X-Plex-Token=<token>.
	 * A part URL built this way is stored as a direct stream_source and compared
	 * against the stored ones, so its shape must not change.
	 *
	 * @param string $rIP
	 * @param int|string $rPort
	 * @param string $rToken
	 * @param string $rPath
	 * @param string $rQuery Raw query string, without the token.
	 * @return string
	 */
	public static function url($rIP, $rPort, $rToken, $rPath, $rQuery = '') {
		return 'http://' . $rIP . ':' . $rPort . $rPath . '?' . ($rQuery === '' ? '' : $rQuery . '&') . 'X-Plex-Token=' . $rToken;
	}

	/**
	 * GET a server URL and decode its XML: elements become arrays, attributes
	 * sit under '@attributes'.
	 *
	 * @param string $rURL
	 * @return array Empty when the request or the XML failed.
	 */
	public static function get($rURL) {
		$rXML = @simplexml_load_string((string) self::readURL($rURL));
		return ($rXML === false ? array() : json_decode(json_encode($rXML), true));
	}

	/**
	 * @param string $rURL
	 * @return string|false
	 */
	public static function readURL($rURL) {
		$rCurl = curl_init($rURL);
		curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
		return curl_exec($rCurl);
	}

	/**
	 * A repeatable element as a list: decoded XML gives a single occurrence as
	 * the bare element and a missing one as null.
	 *
	 * @param mixed $rArray
	 * @return array
	 */
	public static function makeArray($rArray) {
		if (!is_array($rArray)) {
			return array();
		}
		return (isset($rArray['@attributes']) ? array($rArray) : $rArray);
	}
}
