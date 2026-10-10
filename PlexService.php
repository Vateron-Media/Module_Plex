<?php

namespace XcVm\Module\Plex;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Module\Watchfolder\WatchService;

/**
 * PlexService — plex service
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexService {

    use \XcVm\Infrastructure\Database\DatabaseAware;

	/** "genre_<id>" / "genretv_<id>" form fields → watch_categories.type; their bouquets come in "bouquet_<id>" / "bouquettv_<id>". */
	private const GENRE_FIELDS = array('genre' => 3, 'genretv' => 4);

	public static function editPlexSettings($rData) {
		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if (isset(self::GENRE_FIELDS[$rSplit[0]], $rSplit[1])) {
				$rBouquets = $rData[str_replace('genre', 'bouquet', $rSplit[0]) . '_' . $rSplit[1]] ?? array();
				self::db()->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = ?;', $rValue, self::idList($rBouquets), $rSplit[1], self::GENRE_FIELDS[$rSplit[0]]);
			}
		}

		self::db()->query('UPDATE `settings` SET `scan_seconds` = ?, `max_genres` = ?, `thread_count_movie` = ?, `thread_count_show` = ?;', $rData['scan_seconds'], $rData['max_genres'], $rData['thread_count_movie'], $rData['thread_count_show']);
		SettingsManager::clearCache();
		return array('status' => STATUS_SUCCESS);
	}

	public static function processPlexSync($rData) {
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(WatchService::getWatchFolder($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('watch_folders', $rData);
			unset($rArray['id']);
		}

		if (is_array($rData['server_id'])) {
			$rServers = $rData['server_id'];
			$rArray['server_id'] = intval(array_shift($rServers));
			$rArray['server_add'] = self::idList($rServers);
		} else {
			$rArray['server_id'] = intval($rData['server_id']);
			$rArray['server_add'] = null;
		}

		self::db()->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `plex_ip` = ? AND `id` <> ?;', $rData['library_id'], $rArray['server_id'], $rData['plex_ip'], intval($rArray['id'] ?? 0));

		if (0 < self::db()->get_row()['count']) {
			return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
		}

		$rArray['type'] = 'plex';
		$rArray['directory'] = $rData['library_id'];
		$rArray['plex_ip'] = $rData['plex_ip'];
		$rArray['plex_port'] = $rData['plex_port'];
		$rArray['plex_libraries'] = $rData['libraries'];
		$rArray['plex_username'] = $rData['username'];
		$rArray['direct_proxy'] = isset($rData['direct_proxy']) ? 1 : 0;
		if (0 < strlen($rData['password'])) {
			$rArray['plex_password'] = $rData['password'];
		}

		foreach (array('remove_subtitles', 'check_tmdb', 'store_categories', 'scan_missing', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'active') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		$rArray['category_id'] = intval($rData['override_category']);
		$rArray['fb_category_id'] = intval($rData['fallback_category']);
		$rArray['bouquets'] = self::idList($rData['override_bouquets'] ?? array());
		$rArray['fb_bouquets'] = self::idList($rData['fallback_bouquets'] ?? array());
		$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if (self::db()->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = self::db()->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}
		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function forcePlex($rServerID, $rPlexID) {
		ApiClient::systemRequest($rServerID, array('action' => 'plex_force', 'id' => $rPlexID));
	}

	/**
	 * Kill the running Plex sync on every server with an active Plex library
	 * (moved here from core's ServerService::killPlexSync()).
	 *
	 * @return bool
	 */
	public static function killSync() {
		$db = self::db();
		$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 1 AND `type` = 'plex';");
		$rServers = ServerRepository::getAll();
		foreach ($db->get_rows() as $rRow) {
			if (!empty($rServers[$rRow['server_id']]['server_online'])) {
				NodeRpc::request($rRow['server_id'], ['action' => 'kill_plex']);
			}
		}
		return true;
	}

	/**
	 * @param array $rIDs
	 * @return string JSON list of ints, as stored in the *_bouquets / server_add columns.
	 */
	private static function idList($rIDs) {
		return '[' . implode(',', array_map('intval', (array) $rIDs)) . ']';
	}
}
