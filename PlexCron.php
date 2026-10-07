<?php

namespace XcVm\Module\Plex;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\Multithread;

/**
 * PlexCron — the Plex sync cron job.
 *
 * Picks this server's Plex folders, caches what is already imported, scans the
 * library and hands new/changed items to `plex_item` workers.
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexCron {
    use \XcVm\Infrastructure\Database\DatabaseAware;

    /** Items per page when walking a library. */
    private const PAGE_SIZE = 100;

    /** Folder settings passed to the worker as-is. */
    private const FOLDER_SETTINGS = array(
        'read_native', 'movie_symlink', 'remove_subtitles', 'auto_encode', 'auto_upgrade', 'transcode_profile_id',
        'fb_bouquets', 'store_categories', 'category_id', 'bouquets', 'fb_category_id', 'check_tmdb',
        'target_container', 'server_add', 'direct_proxy',
    );

    /**
     * Plex genre categories from the DB, keyed by genre name.
     *
     * @param int|null $rType Category type (3=movie, 4=show)
     * @return array
     */
    public static function getPlexCategories($rType = null) {
        $db = self::db();
        if ($rType) {
            $db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
        } else {
            $db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
        }
        $rReturn = array();
        foreach ($db->get_rows() as $rRow) {
            $rReturn[$rRow['genre']] = $rRow;
        }
        return $rReturn;
    }

    /**
     * Add to watch_categories the genres workers wrote to *.pcat.
     */
    public static function checkCategories() {
        $db = self::db();
        $rTypes = array('movie' => 3, 'show' => 4);
        $rKnown = $rNextID = array();
        foreach ($rTypes as $rName => $rType) {
            $rKnown[$rName] = self::getPlexCategories($rType);
            $db->query('SELECT MAX(`genre_id`) AS `max` FROM `watch_categories` WHERE `type` = ?;', $rType);
            $rNextID[$rName] = intval($db->get_row()['max']);
        }

        foreach (glob(WATCH_TMP_PATH . '*.pcat') as $rFile) {
            $rCategory = json_decode(file_get_contents($rFile), true);
            if (!isset($rKnown[$rCategory['type']][$rCategory['title']])) {
                $rNextID[$rCategory['type']]++;
                $db->query("INSERT INTO `watch_categories` (`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES (?, ?, ?, 0, '[]');", $rTypes[$rCategory['type']], $rNextID[$rCategory['type']], $rCategory['title']);
            }
            unlink($rFile);
        }
    }

    /**
     * Add to bouquets the streams/series workers wrote to *.pbouquet.
     */
    public static function checkBouquets() {
        $rAdditions = array();
        foreach (glob(WATCH_TMP_PATH . '*.pbouquet') as $rFile) {
            $rBouquet = json_decode(file_get_contents($rFile), true);
            $rAdditions[$rBouquet['bouquet_id']][$rBouquet['type']][] = $rBouquet['id'];
            unlink($rFile);
        }

        $db = self::db();
        foreach ($rAdditions as $rBouquetID => $rByType) {
            $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquetID);
            if ($db->num_rows() != 1) {
                continue;
            }
            $rBouquet = $db->get_row();
            foreach ($rByType as $rType => $rIDs) {
                $rColumn = ($rType == 'movie' ? 'bouquet_movies' : 'bouquet_series');
                $rItems = json_decode($rBouquet[$rColumn], true) ?: array();
                foreach ($rIDs as $rID) {
                    if (0 < intval($rID) && !in_array($rID, $rItems)) {
                        $rItems[] = $rID;
                    }
                }
                $db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rItems)) . ']', $rBouquetID);
            }
        }
    }

    /**
     * Plex cron entry point.
     *
     * @param int|null $rForce Folder ID to scan regardless of schedule.
     */
    public static function run($rForce = null) {
        global $rScanOffset;

        // Some environments keep scan offset undefined/null; normalize to int.
        $rScanOffset = (is_numeric($rScanOffset) ? intval($rScanOffset) : 0);
        echo '[PlexCron] Start: server_id=' . SERVER_ID . ', force=' . intval($rForce ?: 0) . ', scan_offset=' . $rScanOffset . "\n";

        $rPlexCategories = array(3 => self::getPlexCategories(3), 4 => self::getPlexCategories(4));
        echo '[PlexCron] Categories loaded: movie=' . count($rPlexCategories[3]) . ', show=' . count($rPlexCategories[4]) . "\n";

        self::checkBouquets();
        self::checkCategories();
        echo "[PlexCron] Temp sync files processed (.pbouquet/.pcat).\n";

        $rFolders = self::selectFolders($rForce, $rScanOffset);
        echo '[PlexCron] Folders selected for scan: ' . count($rFolders) . "\n";
        if (!$rFolders) {
            echo "[PlexCron] Nothing to process. Exiting.\n";
            self::printDiagnostics($rForce, $rScanOffset);
            return;
        }

        shell_exec('rm -f ' . WATCH_TMP_PATH . '*.ppid');
        $rKnown = self::buildCache();
        foreach ($rFolders as $rFolder) {
            self::syncFolder($rFolder, $rKnown, $rPlexCategories, $rForce);
        }
        echo "[PlexCron] Run finished.\n";
    }

    /**
     * This server's Plex folders that are due for a scan (or the forced one).
     */
    private static function selectFolders($rForce, $rScanOffset) {
        $db = self::db();
        if ($rForce) {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' AND `server_id` = ? AND `id` = ?;", SERVER_ID, $rForce);
        } else {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' AND `server_id` = ? AND `active` = 1 AND (`last_run` IS NULL OR `last_run` = 0 OR UNIX_TIMESTAMP() - `last_run` > ?) ORDER BY `id` ASC;", SERVER_ID, $rScanOffset);
        }
        return $db->get_rows();
    }

    /**
     * Why no folder was selected — one line per Plex folder.
     */
    private static function printDiagnostics($rForce, $rScanOffset) {
        $db = self::db();
        $db->query("SELECT `id`, `server_id`, `active`, `last_run`, (UNIX_TIMESTAMP() - `last_run`) AS `age`, `directory`, `plex_ip`, `plex_port` FROM `watch_folders` WHERE `type` = 'plex' ORDER BY `id` ASC;");
        if ($db->num_rows() == 0) {
            echo "[PlexCron] Diagnostics: no records found in watch_folders with type=plex.\n";
            return;
        }

        echo "[PlexCron] Diagnostics: evaluating plex folders against current filters...\n";
        foreach ($db->get_rows() as $rRow) {
            $rReasons = array();
            if (intval($rRow['server_id']) != intval(SERVER_ID)) {
                $rReasons[] = 'server_mismatch';
            }
            if (intval($rRow['active']) != 1) {
                $rReasons[] = 'inactive';
            }
            if (!$rForce && 0 < intval($rRow['last_run']) && intval($rRow['age']) <= $rScanOffset) {
                $rReasons[] = 'waiting_scan_offset';
            }

            echo '[PlexCron]   id=' . intval($rRow['id'])
                . ' server_id=' . intval($rRow['server_id'])
                . ' active=' . intval($rRow['active'])
                . ' last_run=' . ($rRow['last_run'] ?? 'NULL')
                . ' age=' . ($rRow['age'] === null ? 'NULL' : intval($rRow['age']))
                . ' dir=' . $rRow['directory']
                . ' host=' . $rRow['plex_ip'] . ':' . $rRow['plex_port']
                . ' reason=' . implode(',', ($rReasons ?: array('eligible'))) . "\n";
        }
    }

    /**
     * Cache of what is already imported, for the workers (*.pcache in WATCH_TMP_PATH):
     * every stream_source, plus stream ID and source by Plex UUID and by TMDB ID.
     *
     * @return array ['uuids' => known Plex UUIDs, 'leaf_counts' => episodes per series UUID]
     */
    private static function buildCache() {
        echo "[PlexCron] Generating cache...\n";
        $db = self::db();
        $rKnown = array('uuids' => array(), 'leaf_counts' => array());
        $rSources = $rCache = $rSeriesTMDB = array();

        $db->query('SELECT `id`, `tmdb_id`, `plex_uuid` FROM `streams_series` WHERE `tmdb_id` IS NOT NULL AND `tmdb_id` > 0;');
        foreach ($db->get_rows() as $rRow) {
            $rSeriesTMDB[$rRow['id']] = $rRow['tmdb_id'];
            if (!empty($rRow['plex_uuid'])) {
                $rKnown['uuids'][] = $rRow['plex_uuid'];
            }
        }

        $db->query('SELECT `streams`.`id`, `streams_series`.`plex_uuid`, `streams_episodes`.`series_id`, `streams_episodes`.`season_num`, `streams_episodes`.`episode_num`, `streams`.`stream_source` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` WHERE `streams_servers`.`server_id` = ?;', SERVER_ID);
        foreach ($db->get_rows() as $rRow) {
            $rSources[] = $rRow['stream_source'];
            $rEntry = self::cacheEntry($rRow);
            $rEpisode = $rRow['season_num'] . '_' . $rRow['episode_num'];
            if (!empty($rSeriesTMDB[$rRow['series_id']])) {
                $rCache['series_' . $rSeriesTMDB[$rRow['series_id']]][$rEpisode] = $rEntry;
            }
            if (!empty($rRow['plex_uuid'])) {
                $rCache['series_' . $rRow['plex_uuid']][$rEpisode] = $rEntry;
                $rKnown['leaf_counts'][$rRow['plex_uuid']] = ($rKnown['leaf_counts'][$rRow['plex_uuid']] ?? 0) + 1;
            }
        }

        $db->query('SELECT `streams`.`id`, `streams`.`plex_uuid`, `streams`.`stream_source`, `streams`.`movie_properties` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 2 AND `streams_servers`.`server_id` = ?;', SERVER_ID);
        foreach ($db->get_rows() as $rRow) {
            $rSources[] = $rRow['stream_source'];
            $rEntry = self::cacheEntry($rRow);
            $rTMDBID = json_decode($rRow['movie_properties'], true)['tmdb_id'] ?? null;
            if ($rTMDBID) {
                $rCache['movie_' . $rTMDBID] = $rEntry;
            }
            if (!empty($rRow['plex_uuid'])) {
                $rCache['movie_' . $rRow['plex_uuid']] = $rEntry;
                $rKnown['uuids'][] = $rRow['plex_uuid'];
            }
        }
        echo '[PlexCron] Existing sources indexed: total_sources=' . count($rSources) . ', cache_files=' . count($rCache) . "\n";

        exec('find ' . WATCH_TMP_PATH . ' -maxdepth 1 -name "*.pcache" -print0 | xargs -0 rm');
        file_put_contents(WATCH_TMP_PATH . 'stream_database.pcache', json_encode($rSources));
        foreach ($rCache as $rName => $rData) {
            file_put_contents(WATCH_TMP_PATH . $rName . '.pcache', json_encode($rData));
        }
        echo "[PlexCron] Finished generating cache!\n";
        return $rKnown;
    }

    /**
     * @return array ['id', 'source' => first stream_source entry]
     */
    private static function cacheEntry(array $rRow) {
        return array('id' => $rRow['id'], 'source' => json_decode($rRow['stream_source'], true)[0] ?? null);
    }

    /**
     * Scan one folder and run its items through the workers.
     */
    private static function syncFolder(array $rFolder, array $rKnown, array $rPlexCategories, $rForce) {
        $rID = intval($rFolder['id']);
        echo '[PlexCron] Processing folder_id=' . $rID . ' host=' . $rFolder['plex_ip'] . ':' . $rFolder['plex_port'] . ' directory=' . $rFolder['directory'] . "\n";

        $rToken = PlexAuth::getPlexToken($rFolder['plex_ip'], $rFolder['plex_port'], $rFolder['plex_username'], $rFolder['plex_password']);
        echo '[PlexCron] ' . ($rToken ? 'Token obtained' : 'Failed to obtain Plex token') . " for folder_id=$rID.\n";

        self::db()->query('UPDATE `watch_folders` SET `last_run` = UNIX_TIMESTAMP() WHERE `id` = ?;', $rID);
        echo "[PlexCron] Updated last_run for folder_id=$rID.\n";

        $rScan = self::scanFolder($rFolder, $rToken, $rKnown, $rPlexCategories, $rForce);
        if ($rScan === null) {
            echo "[PlexCron] No sections returned for folder_id=$rID. Skipping folder.\n";
            return;
        }
        echo '[PlexCron] ' . ($rScan['items'] ? 'Scan complete. Items queued: ' . count($rScan['items']) : "No new/updated items found for folder_id=$rID") . ".\n";

        self::runWorkers($rScan['items'], $rScan['threads']);
        self::checkBouquets();
        self::checkCategories();
        echo "[PlexCron] Post-processing finished for folder_id=$rID.\n";
    }

    /**
     * Find the folder's section on the Plex server and collect worker jobs.
     *
     * @return array|null ['threads' => int, 'items' => jobs by UUID]; null when the server returned no sections.
     */
    public static function scanFolder(array $rFolder, $rToken, array $rKnown, array $rPlexCategories, $rForce = null) {
        $rSections = PlexClient::get(PlexClient::url($rFolder['plex_ip'], $rFolder['plex_port'], $rToken, '/library/sections'));
        if (!isset($rSections['Directory'])) {
            return null;
        }

        foreach (PlexClient::makeArray($rSections['Directory']) as $rSection) {
            if ($rSection['@attributes']['key'] == $rFolder['directory']) {
                $rType = $rSection['@attributes']['type'];
                $rThreads = self::threadCount($rType);
                echo '[PlexCron] Matched section key=' . $rFolder['directory'] . ' type=' . $rType . ' threads=' . $rThreads . "\n";
                return array('threads' => $rThreads, 'items' => self::scanSection($rFolder, $rToken, $rType, $rKnown, $rPlexCategories, $rForce));
            }
        }

        echo '[PlexCron] Target section key ' . $rFolder['directory'] . ' not found for folder_id=' . intval($rFolder['id']) . ".\n";
        return array('threads' => 1, 'items' => array());
    }

    private static function threadCount($rType) {
        $rSettings = SettingsManager::getAll();
        if ($rType == 'movie') {
            return (intval($rSettings['thread_count_movie'] ?? 0) ?: 25);
        }
        return (intval($rSettings['thread_count_show'] ?? 0) ?: 5);
    }

    /**
     * Page through the section (most recently updated first) and pick items to import.
     *
     * @return array Worker jobs by UUID.
     */
    private static function scanSection(array $rFolder, $rToken, $rType, array $rKnown, array $rPlexCategories, $rForce) {
        $rPath = '/library/sections/' . $rFolder['directory'] . '/all';
        $rTotal = PlexClient::get(PlexClient::url($rFolder['plex_ip'], $rFolder['plex_port'], $rToken, $rPath, 'X-Plex-Container-Start=0&X-Plex-Container-Size=1'));
        $rCount = intval($rTotal['@attributes']['totalSize'] ?? 0);
        echo '[PlexCron] Section item count: ' . $rCount . "\n";

        $rItems = array();
        for ($rStart = 0; $rStart < $rCount; $rStart += self::PAGE_SIZE) {
            $rPage = PlexClient::get(PlexClient::url($rFolder['plex_ip'], $rFolder['plex_port'], $rToken, $rPath, 'X-Plex-Container-Start=' . $rStart . '&X-Plex-Container-Size=' . self::PAGE_SIZE . '&sort=updatedAt%3Adesc'));
            foreach (PlexClient::makeArray($rPage['Video'] ?? $rPage['Directory'] ?? null) as $rItem) {
                $rRatingKey = $rItem['@attributes']['ratingKey'];
                $rUUID = $rFolder['directory'] . '_' . $rRatingKey;
                if (self::needsImport($rFolder, $rType, $rItem['@attributes'], $rUUID, $rKnown, $rForce)) {
                    // Keyed by UUID: paging by updatedAt shifts when Plex updates an item mid-scan, and the
                    // item on a page boundary comes back twice — two parallel workers would import it twice.
                    $rItems[$rUUID] = self::threadData($rFolder, $rType, $rRatingKey, $rUUID, $rToken, $rPlexCategories);
                }
            }
        }
        return $rItems;
    }

    /**
     * New or changed since the last scan; with scan_missing, also anything not imported yet.
     * A series whose episode count changed is rescanned too.
     */
    private static function needsImport(array $rFolder, $rType, array $rInfo, $rUUID, array $rKnown, $rForce) {
        $rUpdatedAt = intval($rInfo['updatedAt'] ?? 0);
        $rLastRun = intval($rFolder['last_run'] ?? 0);
        if ($rForce || !$rLastRun || $rUpdatedAt === 0 || $rLastRun < $rUpdatedAt) {
            return true;
        }
        if ($rType == 'movie') {
            return $rFolder['scan_missing'] && !in_array($rUUID, $rKnown['uuids'], true);
        }
        $rLeafCount = $rKnown['leaf_counts'][$rUUID] ?? 0;
        return intval($rInfo['leafCount'] ?? 0) != $rLeafCount || ($rFolder['scan_missing'] && !$rLeafCount);
    }

    /**
     * A `plex_item` worker job (see PlexItem::run()).
     */
    private static function threadData(array $rFolder, $rType, $rRatingKey, $rUUID, $rToken, array $rPlexCategories) {
        $rData = array(
            'folder_id' => $rFolder['id'],
            'type' => $rType,
            'key' => $rRatingKey,
            'uuid' => $rUUID,
            'plex_categories' => $rPlexCategories,
            'max_genres' => intval(SettingsManager::getAll()['max_genres'] ?? 5),
            'plex' => true,
            'ip' => $rFolder['plex_ip'],
            'port' => $rFolder['plex_port'],
            'token' => $rToken,
        );
        foreach (self::FOLDER_SETTINGS as $rKey) {
            $rData[$rKey] = $rFolder[$rKey];
        }
        return $rData;
    }

    /**
     * Run a `plex_item` worker per job, $rThreadCount at a time.
     */
    private static function runWorkers(array $rItems, $rThreadCount) {
        $rCommands = array();
        foreach ($rItems as $rData) {
            $rCommands[] = '/usr/bin/timeout ' . ($rData['type'] == 'movie' ? 60 : 300) . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php plex_item "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '"';
        }

        $db = self::db();
        $db->close_mysql();
        echo '[PlexCron] Starting worker execution. Commands=' . count($rCommands) . ', mode=' . ($rThreadCount <= 1 ? 'single' : 'multi') . ', threads=' . $rThreadCount . "\n";
        if ($rThreadCount <= 1) {
            foreach ($rCommands as $rCommand) {
                shell_exec($rCommand);
            }
        } else {
            (new Multithread($rCommands, $rThreadCount))->run();
        }
        $db->db_connect();
    }
}
