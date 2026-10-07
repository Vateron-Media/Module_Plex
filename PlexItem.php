<?php

namespace XcVm\Module\Plex;

use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Module\Watch\WatchService;

/**
 * PlexItem — imports one Plex item (a movie, or a show with its episodes):
 * the body of the `plex_item` worker PlexCron starts.
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexItem {
    use \XcVm\Infrastructure\Database\DatabaseAware;

    /** streams.type per Plex library type. */
    private const STREAM_TYPES = array('movie' => 2, 'show' => 5);

    /** watch_logs.type per Plex library type. */
    private const LOG_TYPES = array('movie' => 1, 'show' => 2);

    /** watch_categories.type per Plex library type. */
    private const CATEGORY_TYPES = array('movie' => 3, 'show' => 4);

    /** Folder settings copied onto every imported stream. */
    private const STREAM_SETTINGS = array('read_native', 'movie_symlink', 'remove_subtitles', 'transcode_profile_id');

    /**
     * Import one Plex item.
     *
     * @param array $rThread         Job queued by PlexCron::scanSection().
     * @param array $rStreamDatabase Known stream_source values (stream_database.pcache).
     */
    public static function run(array $rThread, array $rStreamDatabase = array()) {
        if ($rThread['type'] == 'movie') {
            self::importMovie($rThread, $rStreamDatabase);
        } elseif ($rThread['type'] == 'show') {
            self::importShow($rThread, $rStreamDatabase);
        }
    }

    // =================================================================
    // Movies
    // =================================================================

    private static function importMovie(array $rThread, array $rStreamDatabase) {
        echo "=== [MOVIE] Processing movie: {$rThread['key']} ===\n";

        $rVideo = self::metadata($rThread)['Video'] ?? null;
        if (!$rVideo) {
            echo "ERROR: Failed to get movie metadata from Plex!\n";
            return;
        }
        $rInfo = $rVideo['@attributes'];
        echo "LOG: Movie title: {$rInfo['title']} (" . ($rInfo['year'] ?? 'No year') . ")\n";

        $rTMDBID = self::getTmdbIdFromPlex($rVideo)['tmdb_id'];
        $rFile = self::pickFile($rThread, $rVideo);
        if (!$rFile) {
            return;
        }

        $rSources = self::sources($rThread, $rFile);
        if (self::isKnown($rStreamDatabase, $rSources)) {
            echo "LOG: Movie already exists in database (source match) — skipping import\n";
            return;
        }

        $rGenres = self::tags($rVideo, 'Genre', $rThread['max_genres']);
        $rCategoryIDs = self::categories($rThread, $rGenres);
        $rImported = self::findImported($rThread, $rTMDBID);

        if ($rImported) {
            if (!self::shouldUpgrade($rThread, $rImported, $rFile, $rSources)) {
                return;
            }
        } elseif (!$rCategoryIDs) {
            echo "LOG: No categories assigned → logging as failed\n";
            self::log($rThread, $rFile['file'], VodImportResultEvent::STATUS_NO_CATEGORY);
            return;
        }

        $rStream = self::newStream($rThread, $rFile, $rSources);
        $rStream['stream_display_name'] = $rInfo['title'];
        $rStream['year'] = (empty($rInfo['year']) ? null : intval($rInfo['year']));
        $rStream['tmdb_id'] = ($rTMDBID ?: null);
        $rStream['rating'] = self::rating($rInfo);
        $rStream['movie_properties'] = self::movieProperties($rThread, $rVideo, $rTMDBID, $rGenres);
        $rStream['plex_uuid'] = $rThread['uuid'];
        $rStream['category_id'] = self::idList($rCategoryIDs);

        if ($rImported) {
            $rStream['id'] = $rImported['id'];
            $rPrepare = QueryHelper::prepareArray($rStream);
            if (self::db()->query('REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');', ...$rPrepare['data'])) {
                self::upgraded($rThread, $rImported['id'], $rFile);
            } else {
                self::log($rThread, $rFile['file'], VodImportResultEvent::STATUS_INSERT_FAILED);
            }
            return;
        }

        $rStreamID = self::addStream($rThread, $rStream, $rFile);
        if ($rStreamID) {
            foreach (self::bouquets($rThread, $rGenres) as $rBouquetID) {
                self::addToBouquet($rThread, 'movie', $rBouquetID, $rStreamID);
            }
        }
    }

    private static function movieProperties(array $rThread, array $rVideo, $rTMDBID, array $rGenres) {
        $rInfo = $rVideo['@attributes'];
        $rThumb = self::downloadArt($rThread, $rInfo['thumb'] ?? null, 300, 450);
        $rBackdrop = self::downloadArt($rThread, $rInfo['art'] ?? null, 1280, 720);
        list($rSeconds, $rDuration) = self::duration($rInfo['duration'] ?? 0);
        $rCast = implode(', ', self::tags($rVideo, 'Role', 5));

        return array(
            'tmdb_id' => $rTMDBID,
            'release_date' => $rInfo['originallyAvailableAt'] ?? null,
            'plot' => trim($rInfo['summary'] ?? ''),
            'duration_secs' => $rSeconds,
            'duration' => $rDuration,
            'movie_image' => $rThumb,
            'cover_big' => $rThumb,
            'backdrop_path' => ($rBackdrop ? array($rBackdrop) : array()),
            'director' => implode(', ', self::tags($rVideo, 'Director', 3)),
            'actors' => $rCast,
            'cast' => $rCast,
            'genre' => implode(', ', $rGenres),
            'country' => self::tags($rVideo, 'Country', 1)[0] ?? null,
            'rating' => self::rating($rInfo),
        );
    }

    // =================================================================
    // Shows
    // =================================================================

    private static function importShow(array $rThread, array $rStreamDatabase) {
        echo "=== [SHOW] Processing TV show: {$rThread['key']} ===\n";

        $rShow = self::metadata($rThread)['Directory'] ?? null;
        if (!$rShow) {
            echo "ERROR: Failed to get show metadata from Plex!\n";
            return;
        }
        echo "Show title: {$rShow['@attributes']['title']}\n";

        $rTMDB = self::getTmdbIdFromPlex($rShow);
        $rSeasons = PlexClient::makeArray(self::metadata($rThread, '/children')['Directory'] ?? null);
        $rEpisodes = PlexClient::makeArray(self::metadata($rThread, '/allLeaves')['Video'] ?? null);
        echo 'Found ' . count($rSeasons) . ' season(s), ' . count($rEpisodes) . " episode(s)\n";
        $rSeasonData = self::seasons($rThread, $rShow, $rSeasons, $rEpisodes);

        $rSeries = self::findSeries($rThread['uuid'], $rTMDB['tmdb_id']);
        if ($rSeries) {
            self::refreshSeries($rThread, $rSeries, $rShow, $rSeasonData);
        } else {
            $rSeries = self::createSeries($rThread, $rShow, $rTMDB, $rSeasonData);
        }
        if (!$rSeries) {
            // Without a series the episodes would get series_no = NULL and an empty show name.
            echo "Series not created — skipping its episodes\n";
            return;
        }

        foreach ($rEpisodes as $rEpisode) {
            self::importEpisode($rThread, $rSeries, $rEpisode, $rTMDB, $rStreamDatabase);
        }
        echo "=== [SHOW] TV show processing completed ===\n";
    }

    /**
     * Seasons for streams_series.seasons: cover and first episode's air date.
     */
    private static function seasons(array $rThread, array $rShow, array $rSeasons, array $rEpisodes) {
        $rAirDates = array();
        foreach ($rEpisodes as $rEpisode) {
            $rSeason = $rEpisode['@attributes']['parentIndex'] ?? '';
            if (!array_key_exists($rSeason, $rAirDates)) {
                $rAirDates[$rSeason] = $rEpisode['@attributes']['originallyAvailableAt'] ?? null;
            }
        }

        $rData = array();
        foreach ($rSeasons as $rSeason) {
            $rInfo = $rSeason['@attributes'];
            if (empty($rInfo['index'])) {
                continue;
            }
            $rCover = self::downloadArt($rThread, $rInfo['thumb'] ?? null, 300, 450);
            $rData[] = array(
                'name' => $rInfo['title'],
                'air_date' => ($rAirDates[$rInfo['index']] ?? '') ?: '',
                'overview' => trim($rShow['@attributes']['summary'] ?? ''),
                'cover_big' => $rCover,
                'cover' => $rCover,
                'episode_count' => $rInfo['leafCount'] ?? null,
                'season_number' => $rInfo['index'],
                'id' => $rInfo['ratingKey'],
            );
        }
        return $rData;
    }

    /**
     * @return array|null
     */
    private static function findSeries($rPlexID, $rTMDBID) {
        $db = self::db();
        $db->query('SELECT * FROM `streams_series` WHERE `plex_uuid` = ? OR `tmdb_id` = ?;', $rPlexID, $rTMDBID);
        return ($db->num_rows() == 1 ? $db->get_row() : null);
    }

    /**
     * @return array|null The new streams_series row; null when it has no category or the insert failed.
     */
    private static function createSeries(array $rThread, array $rShow, array $rTMDB, array $rSeasonData) {
        $rInfo = $rShow['@attributes'];
        $rGenres = self::tags($rShow, 'Genre', $rThread['max_genres']);
        $rCategoryIDs = self::categories($rThread, $rGenres);
        if (!$rCategoryIDs) {
            echo "No categories for this show\n";
            return null;
        }

        $rThumb = self::downloadArt($rThread, $rInfo['thumb'] ?? null, 300, 450);
        $rBackdrop = self::downloadArt($rThread, $rInfo['art'] ?? null, 1280, 720);
        $rReleaseDate = $rInfo['originallyAvailableAt'] ?? null;
        $rPrepare = QueryHelper::prepareArray(array(
            'title' => $rInfo['title'],
            'category_id' => self::idList($rCategoryIDs),
            'episode_run_time' => intval(($rInfo['duration'] ?? 0) / 1000 / 60),
            'tmdb_id' => $rTMDB['tmdb_id'],
            'tmdb_language' => $rTMDB['language'],
            'plex_uuid' => $rThread['uuid'],
            'cover' => $rThumb,
            'cover_big' => $rThumb,
            'backdrop_path' => ($rBackdrop ? array($rBackdrop) : array()),
            'genre' => implode(', ', self::tags($rShow, 'Genre', 3)),
            'plot' => trim($rInfo['summary'] ?? ''),
            'cast' => implode(', ', self::tags($rShow, 'Role', 5)),
            'director' => implode(', ', self::tags($rShow, 'Director', 3)),
            'rating' => self::rating($rInfo),
            'release_date' => $rReleaseDate,
            'year' => ($rReleaseDate ? intval(substr($rReleaseDate, 0, 4)) : null),
            'last_modified' => time(),
            'seasons' => $rSeasonData,
            'youtube_trailer' => '',
        ));

        $db = self::db();
        if (!$db->query('INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');', ...$rPrepare['data'])) {
            echo "ERROR: Failed to insert series into DB!\n";
            return null;
        }
        $rSeriesID = $db->last_insert_id();
        echo "Series created with ID = $rSeriesID\n";
        foreach (self::bouquets($rThread, $rGenres) as $rBouquetID) {
            self::addToBouquet($rThread, 'series', $rBouquetID, $rSeriesID);
        }
        return VodItemImporter::getSerie($rSeriesID);
    }

    /**
     * Existing series: refresh its seasons and fetch the cover if it has none.
     */
    private static function refreshSeries(array $rThread, array $rSeries, array $rShow, array $rSeasonData) {
        $db = self::db();
        $db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
        echo "Seasons updated for series ID {$rSeries['id']}\n";
        if ($rSeries['cover']) {
            return;
        }

        $rThumb = self::downloadArt($rThread, $rShow['@attributes']['thumb'] ?? null, 300, 450);
        $rBackdrop = self::downloadArt($rThread, $rShow['@attributes']['art'] ?? null, 1280, 720);
        if ($rThumb || $rBackdrop) {
            $db->query('UPDATE `streams_series` SET `cover` = ?, `cover_big` = ?, `backdrop_path` = ? WHERE `id` = ?;', $rThumb, $rThumb, json_encode($rBackdrop ? array($rBackdrop) : array()), $rSeries['id']);
        }
    }

    private static function importEpisode(array $rThread, array $rSeries, array $rEpisode, array $rTMDB, array &$rStreamDatabase) {
        $rInfo = $rEpisode['@attributes'];
        $rSeason = $rInfo['parentIndex'] ?? null;
        $rNumber = $rInfo['index'] ?? null;
        if (!$rSeason || !$rNumber) {
            return;
        }
        $rName = $rSeries['title'] . ' - S' . sprintf('%02d', $rSeason) . 'E' . sprintf('%02d', $rNumber) . ' - ' . $rInfo['title'];
        echo "Processing $rName\n";

        $rFile = self::pickFile($rThread, $rEpisode);
        if (!$rFile) {
            return;
        }
        $rSources = self::sources($rThread, $rFile);
        if (self::isKnown($rStreamDatabase, $rSources)) {
            echo "Episode already exists in database — skipping\n";
            return;
        }
        // Two episodes sharing one file within a show are imported once.
        foreach ($rSources as $rSource) {
            $rStreamDatabase[] = self::sourceJSON($rSource);
        }

        $rImported = self::findImported($rThread, $rTMDB['tmdb_id'], $rSeason . '_' . $rNumber);
        if ($rImported) {
            if (self::shouldUpgrade($rThread, $rImported, $rFile, $rSources)) {
                self::db()->query('UPDATE `streams` SET `plex_uuid` = ?, `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rThread['uuid'], self::streamSource($rThread, $rSources), self::targetContainer($rThread, $rFile['file']), $rImported['id']);
                self::upgraded($rThread, $rImported['id'], $rFile);
            }
            return;
        }

        list($rSeconds, $rDuration) = self::duration($rInfo['duration'] ?? 0);
        $rStream = self::newStream($rThread, $rFile, $rSources);
        $rStream['stream_display_name'] = $rName;
        $rStream['movie_properties'] = array(
            'tmdb_id' => ($rSeries['tmdb_id'] ?: null),
            'release_date' => $rInfo['originallyAvailableAt'] ?? null,
            'plot' => $rInfo['summary'] ?? null,
            'duration_secs' => $rSeconds,
            'duration' => $rDuration,
            'movie_image' => self::downloadArt($rThread, $rInfo['thumb'] ?? null, 450, 253),
            'video' => array(),
            'audio' => array(),
            'bitrate' => 0,
            'rating' => self::rating($rInfo, $rSeries['rating']),
            'season' => $rSeason,
        );
        $rStream['tmdb_language'] = $rTMDB['language'];
        $rStream['uuid'] = $rThread['uuid'];
        $rStream['series_no'] = $rSeries['id'];
        self::addStream($rThread, $rStream, $rFile, array($rSeason, $rSeries['id'], $rNumber));
    }

    // =================================================================
    // Writing streams
    // =================================================================

    /**
     * Insert a new stream with its server links (and episode row) in one transaction.
     * Without it a worker killed by its timeout left a stream with no server: the next
     * scan could not see it (the cache joins streams_servers) and imported a duplicate.
     *
     * @param array $rStream streams columns.
     * @param array $rServers
     * @param array|null $rEpisode [season_num, series_id, episode_num]
     * @return int|false Stream ID, or false
     */
    public static function insertStream(array $rStream, array $rServers, $rEpisode = null) {
        $db = self::db();
        $rPrepare = QueryHelper::prepareArray($rStream);
        // Refused begin (already inside a transaction): the writes join the caller's, which owns commit.
        $rOwn = $db->beginTransaction();
        if ($db->query('INSERT INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');', ...$rPrepare['data'])) {
            $rInsertID = $db->last_insert_id();
            $rLinked = true;
            foreach ($rServers as $rServerID) {
                $rLinked = $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID) && $rLinked;
            }
            if ($rEpisode) {
                $rLinked = $db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rEpisode[0], $rEpisode[1], $rInsertID, $rEpisode[2]) && $rLinked;
            }
            if ($rLinked && (!$rOwn || $db->commit())) {
                return $rInsertID;
            }
        }
        if ($rOwn) {
            $db->rollback();
        }
        return false;
    }

    /**
     * New stream: insert, queue encoding, log to watch_logs.
     *
     * @return int|false
     */
    private static function addStream(array $rThread, array $rStream, array $rFile, $rEpisode = null) {
        $rServers = self::servers($rThread);
        $rStreamID = self::insertStream($rStream, $rServers, $rEpisode);
        if (!$rStreamID) {
            echo "ERROR: Failed to insert into `streams`!\n";
            self::log($rThread, $rFile['file'], VodImportResultEvent::STATUS_INSERT_FAILED);
            return false;
        }
        echo "Imported successfully! Stream ID = $rStreamID\n";
        self::queueEncode($rThread, $rStreamID, $rServers);
        self::log($rThread, $rFile['file'], VodImportResultEvent::STATUS_IMPORTED, $rStreamID);
        return $rStreamID;
    }

    /**
     * An imported stream got a new file: reset its server state and re-encode.
     */
    private static function upgraded(array $rThread, $rStreamID, array $rFile) {
        echo "Upgraded stream $rStreamID to {$rFile['file']}\n";
        $rServers = self::servers($rThread);
        foreach ($rServers as $rServerID) {
            self::db()->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, $rServerID);
        }
        self::queueEncode($rThread, $rStreamID, $rServers);
        self::log($rThread, $rFile['file'], VodImportResultEvent::STATUS_UPGRADED);
    }

    /**
     * Whether an imported stream should be upgraded to the new file.
     */
    private static function shouldUpgrade(array $rThread, array $rImported, array $rFile, array $rSources) {
        if (in_array($rImported['source'], array($rFile['file'], $rSources['local'], $rSources['direct']), true)) {
            echo "Same source file — no changes needed\n";
            return false;
        }
        if (!$rThread['auto_upgrade']) {
            echo "Auto-upgrade disabled — skipping\n";
            return false;
        }
        return true;
    }

    /**
     * Fields every new stream shares: type, source, container, folder settings.
     */
    private static function newStream(array $rThread, array $rFile, array $rSources) {
        $rStream = QueryHelper::verifyPostTable('streams');
        $rStream['type'] = self::STREAM_TYPES[$rThread['type']];
        $rStream['target_container'] = self::targetContainer($rThread, $rFile['file']);
        foreach (self::STREAM_SETTINGS as $rKey) {
            $rStream[$rKey] = $rThread[$rKey];
        }
        $rStream['stream_source'] = self::streamSource($rThread, $rSources);
        $rStream['direct_source'] = $rStream['direct_proxy'] = ($rThread['direct_proxy'] ? 1 : 0);
        $rStream['order'] = VodItemImporter::getNextOrder();
        $rStream['added'] = time();
        return $rStream;
    }

    private static function queueEncode(array $rThread, $rStreamID, array $rServers) {
        if ($rThread['auto_encode']) {
            foreach ($rServers as $rServerID) {
                StreamProcess::queueMovie($rStreamID, $rServerID);
            }
        }
    }

    /**
     * The worker's server plus the folder's extra servers.
     *
     * @return int[]
     */
    private static function servers(array $rThread) {
        $rServers = array(SERVER_ID);
        foreach ((json_decode($rThread['server_add'] ?? '[]', true) ?: array()) as $rServerID) {
            $rServers[] = intval($rServerID);
        }
        // UNIQUE (stream_id, server_id): a repeated server would fail the insert and roll the import back.
        return array_values(array_unique($rServers));
    }

    // =================================================================
    // Files and sources
    // =================================================================

    /**
     * The largest accessible file part (local, or any part with direct proxy).
     * When none is accessible, logs the first one as broken.
     *
     * @return array|null ['file', 'size', 'key']
     */
    private static function pickFile(array $rThread, array $rItem) {
        $rBest = $rFirst = null;
        foreach (PlexClient::makeArray($rItem['Media'] ?? null) as $rMedia) {
            foreach (PlexClient::makeArray($rMedia['Part'] ?? null) as $rPart) {
                $rPath = $rPart['@attributes']['file'];
                $rSize = intval($rPart['@attributes']['size'] ?? 0);
                $rFirst = $rFirst ?? $rPath;
                if ((file_exists($rPath) || $rThread['direct_proxy']) && (!$rBest || $rSize > $rBest['size'])) {
                    $rBest = array('file' => $rPath, 'size' => $rSize, 'key' => $rPart['@attributes']['key']);
                }
            }
        }
        if (!$rBest && $rFirst) {
            echo "No accessible file parts (possibly not mounted): $rFirst\n";
            self::log($rThread, $rFirst, VodImportResultEvent::STATUS_BROKEN_FILE);
        }
        return $rBest;
    }

    /**
     * Both forms a file can take in streams.stream_source.
     *
     * @return array ['local' => 's:<server>:<path>', 'direct' => the part's Plex URL]
     */
    private static function sources(array $rThread, array $rFile) {
        return array(
            'local' => 's:' . SERVER_ID . ':' . $rFile['file'],
            'direct' => self::plexURL($rThread, $rFile['key']),
        );
    }

    private static function streamSource(array $rThread, array $rSources) {
        return self::sourceJSON($rThread['direct_proxy'] ? $rSources['direct'] : $rSources['local']);
    }

    private static function sourceJSON($rSource) {
        return json_encode(array($rSource), JSON_UNESCAPED_UNICODE);
    }

    private static function isKnown(array $rStreamDatabase, array $rSources) {
        return in_array(self::sourceJSON($rSources['local']), $rStreamDatabase) || in_array(self::sourceJSON($rSources['direct']), $rStreamDatabase);
    }

    private static function targetContainer(array $rThread, $rFile) {
        if ($rThread['target_container'] != 'auto' && $rThread['target_container'] && !$rThread['direct_proxy']) {
            return $rThread['target_container'];
        }
        return (pathinfo($rFile, PATHINFO_EXTENSION) ?: 'mp4');
    }

    /**
     * The already imported stream from PlexCron's cache: by Plex UUID, then TMDB ID.
     *
     * @param string|null $rEpisodeKey "<season>_<episode>" for a show
     * @return array|null ['id', 'source']
     */
    private static function findImported(array $rThread, $rTMDBID, $rEpisodeKey = null) {
        $rPrefix = ($rThread['type'] == 'movie' ? 'movie_' : 'series_');
        foreach (array($rThread['uuid'], ($rThread['check_tmdb'] ? $rTMDBID : null)) as $rID) {
            $rFile = WATCH_TMP_PATH . $rPrefix . $rID . '.pcache';
            if (!$rID || !file_exists($rFile)) {
                continue;
            }
            $rData = json_decode(file_get_contents($rFile), true);
            if ($rEpisodeKey === null) {
                return $rData;
            }
            if (isset($rData[$rEpisodeKey])) {
                return $rData[$rEpisodeKey];
            }
        }
        return null;
    }

    // =================================================================
    // Categories and bouquets
    // =================================================================

    /**
     * Categories: the folder's override, else by genre, else the fallback.
     * Unknown genres are saved for Plex Settings (store_categories).
     *
     * @return int[]
     */
    private static function categories(array $rThread, array $rGenres) {
        if (0 < intval($rThread['category_id'] ?? 0)) {
            return array(intval($rThread['category_id']));
        }
        $rMap = $rThread['plex_categories'][self::CATEGORY_TYPES[$rThread['type']]] ?? array();
        $rIDs = array();
        foreach ($rGenres as $rGenre) {
            if (isset($rMap[$rGenre])) {
                $rID = intval($rMap[$rGenre]['category_id']);
                if (0 < $rID && !in_array($rID, $rIDs)) {
                    $rIDs[] = $rID;
                }
            } elseif ($rThread['store_categories']) {
                self::addCategory($rThread['type'], $rGenre);
            }
        }
        if (!$rIDs && 0 < intval($rThread['fb_category_id'] ?? 0)) {
            $rIDs = array(intval($rThread['fb_category_id']));
        }
        return $rIDs;
    }

    /**
     * Bouquets: the folder's override, else by genre, else the fallback.
     *
     * @return int[]
     */
    private static function bouquets(array $rThread, array $rGenres) {
        $rIDs = json_decode($rThread['bouquets'] ?? '[]', true) ?: array();
        if (!$rIDs) {
            $rMap = $rThread['plex_categories'][self::CATEGORY_TYPES[$rThread['type']]] ?? array();
            foreach ($rGenres as $rGenre) {
                $rIDs = array_merge($rIDs, json_decode($rMap[$rGenre]['bouquets'] ?? '[]', true) ?: array());
            }
        }
        if (!$rIDs) {
            $rIDs = json_decode($rThread['fb_bouquets'] ?? '[]', true) ?: array();
        }
        return array_values(array_unique(array_map('intval', $rIDs)));
    }

    /**
     * Write a bouquet file for PlexCron::checkBouquets().
     */
    private static function addToBouquet(array $rThread, $rType, $rBouquetID, $rID) {
        file_put_contents(WATCH_TMP_PATH . md5($rThread['uuid'] . '_' . $rThread['key'] . '_' . $rType . '_' . $rBouquetID . '_' . $rID) . '.pbouquet', json_encode(array('type' => $rType, 'bouquet_id' => $rBouquetID, 'id' => $rID)));
    }

    /**
     * Write a new genre for PlexCron::checkCategories().
     */
    private static function addCategory($rType, $rGenre) {
        file_put_contents(WATCH_TMP_PATH . md5($rType . '_' . $rGenre) . '.pcat', json_encode(array('type' => $rType, 'title' => $rGenre)));
    }

    private static function idList(array $rIDs) {
        return '[' . implode(',', array_map('intval', $rIDs)) . ']';
    }

    // =================================================================
    // Plex metadata
    // =================================================================

    private static function plexURL(array $rThread, $rPath, $rQuery = '') {
        return PlexClient::url($rThread['ip'], $rThread['port'], $rThread['token'], $rPath, $rQuery);
    }

    /**
     * /library/metadata/<key><suffix> of the current item.
     */
    private static function metadata(array $rThread, $rSuffix = '') {
        return PlexClient::get(self::plexURL($rThread, '/library/metadata/' . $rThread['key'] . $rSuffix));
    }

    /**
     * Download a Plex image (poster, backdrop, cover) at the given size.
     *
     * @return string|null
     */
    private static function downloadArt(array $rThread, $rPath, $rWidth, $rHeight) {
        if (!$rPath) {
            return null;
        }
        return ImageUtils::downloadImage(self::plexURL($rThread, '/photo/:/transcode', 'width=' . $rWidth . '&height=' . $rHeight . '&minSize=1&quality=100&upscale=1&url=' . $rPath));
    }

    /**
     * The tag values of child elements (Genre, Role, Director, Country).
     *
     * @param int $rLimit 0 for no limit.
     * @return string[]
     */
    private static function tags(array $rItem, $rTag, $rLimit = 0) {
        $rTags = array();
        foreach (PlexClient::makeArray($rItem[$rTag] ?? null) as $rElement) {
            $rTags[] = $rElement['@attributes']['tag'] ?? '';
        }
        return (0 < $rLimit ? array_slice($rTags, 0, $rLimit) : $rTags);
    }

    private static function rating(array $rInfo, $rDefault = 0) {
        return (floatval($rInfo['rating'] ?? 0) ?: floatval($rInfo['audienceRating'] ?? 0)) ?: $rDefault;
    }

    /**
     * @param int|string $rMilliseconds
     * @return array [seconds, 'HH:MM:SS']
     */
    private static function duration($rMilliseconds) {
        $rSeconds = intval($rMilliseconds / 1000);
        return array($rSeconds, sprintf('%02d:%02d:%02d', intdiv($rSeconds, 3600), intdiv($rSeconds % 3600, 60), $rSeconds % 60));
    }

    /**
     * TMDB ID (and language) from Plex guids: the new agent uses <Guid id="tmdb://N"/>,
     * the legacy one guid="com.plexapp.agents.themoviedb://N?lang=xx".
     *
     * @return array ['tmdb_id' => int|null, 'language' => string|null]
     */
    public static function getTmdbIdFromPlex(array $rItem) {
        foreach (PlexClient::makeArray($rItem['Guid'] ?? null) as $rGuid) {
            $rID = $rGuid['@attributes']['id'] ?? '';
            if (strpos($rID, 'tmdb://') === 0) {
                return array('tmdb_id' => intval(substr($rID, 7)), 'language' => null);
            }
        }

        $rGuid = $rItem['@attributes']['guid'] ?? '';
        if (preg_match('/com\.plexapp\.agents\.themoviedb:\/\/(\d+)/', $rGuid, $rMatch)) {
            $rLanguage = (strpos($rGuid, '?lang=') !== false ? substr($rGuid, strpos($rGuid, '?lang=') + 6) : null);
            return array('tmdb_id' => intval($rMatch[1]), 'language' => $rLanguage);
        }

        return array('tmdb_id' => null, 'language' => null);
    }

    private static function log(array $rThread, $rFile, $rStatus, $rStreamID = 0) {
        WatchService::logImportResult(self::LOG_TYPES[$rThread['type']], SERVER_ID, $rFile, $rStatus, $rStreamID);
    }
}
