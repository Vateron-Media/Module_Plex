<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Module\Plex\PlexItem;
use XcVm\Module\Watchfolder\WatchService;
use XcVm\Tests\Support\InstallSchema;

/**
 * End-to-end import of one Plex item (what a `plex_item` worker does): metadata
 * from FakePlex over HTTP, rows into the real install schema on TestDb.
 */
final class PlexItemImportTest extends TestCase {

    private static FakePlex $plex;

    private TestDb $db;

    private string $media;

    public static function setUpBeforeClass(): void {
        self::$plex = new FakePlex();
    }

    protected function setUp(): void {
        $this->db = new TestDb();
        foreach (array('streams', 'streams_servers', 'streams_episodes', 'streams_series') as $rTable) {
            $this->db->exec(InstallSchema::table($rTable));
        }
        $this->db->exec('CREATE TABLE `watch_logs` (`id` int AUTO_INCREMENT PRIMARY KEY, `type` int DEFAULT 0, `server_id` int DEFAULT 0, `filename` varchar(4096), `title` varchar(1024), `status` int DEFAULT 0, `stream_id` int DEFAULT 0);');
        PlexItem::setDb($this->db);
        VodItemImporter::setDb($this->db);
        WatchService::setDb($this->db);
        $GLOBALS['db'] = $this->db; // QueryHelper::verifyPostTable() reads the global handle

        array_map('unlink', glob(WATCH_TMP_PATH . '*'));
        $this->media = sys_get_temp_dir() . '/plex_media_' . uniqid();
        mkdir($this->media);
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->media));
    }

    // --- fixtures -------------------------------------------------------

    private function thread(string $rType, string $rKey, array $rOverride = array()): array {
        return $rOverride + array(
            'folder_id' => 1, 'type' => $rType, 'key' => $rKey, 'uuid' => '1_' . $rKey,
            'plex_categories' => array(
                3 => array('Drama' => array('genre' => 'Drama', 'category_id' => 7, 'bouquets' => '[9]')),
                4 => array('Drama' => array('genre' => 'Drama', 'category_id' => 8, 'bouquets' => '[10]')),
            ),
            'read_native' => 0, 'movie_symlink' => 0, 'remove_subtitles' => 0, 'auto_encode' => 0, 'auto_upgrade' => 1,
            'transcode_profile_id' => 0, 'max_genres' => 5, 'plex' => true,
            'ip' => '127.0.0.1', 'port' => self::$plex->port, 'token' => 'tok',
            'fb_bouquets' => '[]', 'store_categories' => 1, 'category_id' => 0, 'bouquets' => '[]', 'fb_category_id' => 0,
            'check_tmdb' => 1, 'target_container' => 'auto', 'server_add' => '[2]', 'direct_proxy' => 0,
        );
    }

    private function file(string $rName): string {
        touch($this->media . '/' . $rName);
        return $this->media . '/' . $rName;
    }

    private function putMovie(string $rFile, string $rGenres = '<Genre tag="Drama"/><Genre tag="Comedy"/>'): void {
        self::$plex->put('/library/metadata/101', '<MediaContainer size="1"><Video ratingKey="101" type="movie" title="Oak Street" year="2024" summary=" Plot " rating="7.5" duration="5400000" originallyAvailableAt="2024-01-02" thumb="/library/metadata/101/thumb/1">'
            . '<Media id="1"><Part id="11" key="/library/parts/11/file.mkv" file="' . $rFile . '" size="1000"/></Media>'
            . $rGenres . '<Director tag="Dir One"/><Role tag="Actor A"/><Country tag="Hungary"/><Guid id="tmdb://555"/></Video></MediaContainer>');
    }

    private function putShow(string $rEp1, string $rEp2): void {
        self::$plex->put('/library/metadata/200', '<MediaContainer size="1"><Directory ratingKey="200" type="show" title="Show" summary="S plot" rating="8" duration="1800000" originallyAvailableAt="2020-05-01" thumb="/library/metadata/200/thumb/1" art="/library/metadata/200/art/1">'
            . '<Genre tag="Drama"/><Role tag="A"/><Guid id="tmdb://777"/></Directory></MediaContainer>');
        self::$plex->put('/library/metadata/200/children', '<MediaContainer size="1"><Directory ratingKey="201" index="1" title="Season 1" leafCount="2" thumb="/library/metadata/201/thumb/1"/></MediaContainer>');
        $rEpisode = function ($rIndex, $rTitle, $rFile) {
            return '<Video ratingKey="21' . $rIndex . '" type="episode" title="' . $rTitle . '" parentIndex="1" index="' . $rIndex . '" summary="E" duration="1800000" originallyAvailableAt="2020-05-0' . $rIndex . '" thumb="/library/metadata/21' . $rIndex . '/thumb/1">'
                . '<Media id="2' . $rIndex . '"><Part key="/library/parts/2' . $rIndex . '/file.mkv" file="' . $rFile . '" size="10"/></Media></Video>';
        };
        self::$plex->put('/library/metadata/200/allLeaves', '<MediaContainer size="2">' . $rEpisode(1, 'Pilot', $rEp1) . $rEpisode(2, 'Second', $rEp2) . '</MediaContainer>');
    }

    private function import(array $rThread, array $rStreamDatabase = array()): void {
        ob_start();
        try {
            PlexItem::run($rThread, $rStreamDatabase);
        } finally {
            ob_end_clean();
        }
    }

    private static function source(string $rFile): string {
        return json_encode(array('s:1:' . $rFile));
    }

    private function rows(string $rQuery, ...$rArgs): array {
        $this->db->query($rQuery, ...$rArgs);
        return $this->db->get_rows();
    }

    private function tmpFiles(string $rExt): array {
        return array_map(fn($rFile) => json_decode(file_get_contents($rFile), true), glob(WATCH_TMP_PATH . '*.' . $rExt));
    }

    // --- movies ----------------------------------------------------------

    public function testNewMovieIsImportedWithMetadataServersAndLog(): void {
        $rFile = $this->file('oak.mkv');
        $this->putMovie($rFile);

        $this->import($this->thread('movie', '101', array('bouquets' => '[4]')));

        $rStreams = $this->rows('SELECT * FROM `streams`;');
        $this->assertCount(1, $rStreams);
        $rStream = $rStreams[0];
        $this->assertSame(2, (int) $rStream['type']);
        $this->assertSame('Oak Street', $rStream['stream_display_name']);
        $this->assertSame(2024, (int) $rStream['year']);
        $this->assertSame('1_101', $rStream['plex_uuid']);
        $this->assertSame(self::source($rFile), $rStream['stream_source']);
        $this->assertSame('mkv', $rStream['target_container']);
        $this->assertSame('[7]', $rStream['category_id']);
        $this->assertSame(0, (int) $rStream['direct_source']);

        $rProps = json_decode($rStream['movie_properties'], true);
        $this->assertSame(555, $rProps['tmdb_id']);
        $this->assertSame('Drama, Comedy', $rProps['genre']);
        $this->assertSame('Dir One', $rProps['director']);
        $this->assertSame('Actor A', $rProps['cast']);
        $this->assertSame('Plot', $rProps['plot']);
        $this->assertSame('01:30:00', $rProps['duration']);
        $this->assertSame('Hungary', $rProps['country']);

        $this->assertSame(array(1, 2), array_map('intval', array_column($this->rows('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ? ORDER BY `server_id`;', $rStream['id']), 'server_id')));
        $this->assertSame(array(array('status' => 1, 'stream_id' => $rStream['id'])), $this->rows('SELECT `status`, `stream_id` FROM `watch_logs`;'));
        $this->assertSame(array(array('type' => 'movie', 'bouquet_id' => 4, 'id' => (int) $rStream['id'])), $this->tmpFiles('pbouquet'));
        $this->assertSame(array(array('type' => 'movie', 'title' => 'Comedy')), $this->tmpFiles('pcat'));
    }

    public function testMovieWhoseSourceIsKnownIsSkipped(): void {
        $rFile = $this->file('oak.mkv');
        $this->putMovie($rFile);

        $this->import($this->thread('movie', '101'), array(self::source($rFile)));

        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams`;'));
        $this->assertSame(array(), $this->rows('SELECT `id` FROM `watch_logs`;'));
    }

    public function testMovieWithoutCategoryIsLoggedAndNotImported(): void {
        $rFile = $this->file('oak.mkv');
        $this->putMovie($rFile, '<Genre tag="Comedy"/>');

        $this->import($this->thread('movie', '101'));

        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams`;'));
        $this->assertSame(array(array('status' => 3, 'filename' => $rFile)), $this->rows('SELECT `status`, `filename` FROM `watch_logs`;'));
    }

    public function testMovieFallsBackToFallbackCategory(): void {
        $this->putMovie($this->file('oak.mkv'), '<Genre tag="Comedy"/>');

        $this->import($this->thread('movie', '101', array('fb_category_id' => 6)));

        $this->assertSame('[6]', $this->rows('SELECT `category_id` FROM `streams`;')[0]['category_id']);
    }

    public function testMovieOverrideCategoryWins(): void {
        $this->putMovie($this->file('oak.mkv'));

        $this->import($this->thread('movie', '101', array('category_id' => 3)));

        $this->assertSame('[3]', $this->rows('SELECT `category_id` FROM `streams`;')[0]['category_id']);
    }

    public function testMovieWithInaccessibleFileIsLogged(): void {
        $this->putMovie('/nonexistent/oak.mkv');

        $this->import($this->thread('movie', '101'));

        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams`;'));
        $this->assertSame(array(array('status' => 5, 'filename' => '/nonexistent/oak.mkv')), $this->rows('SELECT `status`, `filename` FROM `watch_logs`;'));
    }

    public function testDirectProxyMovieStreamsFromPlex(): void {
        $this->putMovie('/remote/oak.mkv');

        $this->import($this->thread('movie', '101', array('direct_proxy' => 1)));

        $rStream = $this->rows('SELECT `stream_source`, `direct_source`, `direct_proxy` FROM `streams`;')[0];
        $this->assertSame('["http:\/\/127.0.0.1:' . self::$plex->port . '\/library\/parts\/11\/file.mkv?X-Plex-Token=tok"]', $rStream['stream_source']);
        $this->assertSame(array(1, 1), array((int) $rStream['direct_source'], (int) $rStream['direct_proxy']));
    }

    public function testKnownMovieWithNewFileIsUpgradedInPlace(): void {
        $rFile = $this->file('oak-4k.mkv');
        $this->putMovie($rFile);
        $this->db->query("INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES (50, 2, '[\"s:1:/old.mkv\"]');");
        $this->db->query('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `stream_status`) VALUES (50, 1, 1);');
        file_put_contents(WATCH_TMP_PATH . 'movie_1_101.pcache', json_encode(array('id' => 50, 'source' => '/old.mkv')));

        $this->import($this->thread('movie', '101'));

        $rStreams = $this->rows('SELECT `id`, `stream_source` FROM `streams`;');
        $this->assertSame(array(array('id' => 50, 'stream_source' => self::source($rFile))), $rStreams);
        $this->assertSame(0, $this->rows('SELECT `stream_status` FROM `streams_servers` WHERE `stream_id` = 50;')[0]['stream_status']);
        $this->assertSame(array(array('status' => 6)), $this->rows('SELECT `status` FROM `watch_logs`;'));
    }

    public function testKnownMovieWithSameFileIsLeftAlone(): void {
        $rFile = $this->file('oak.mkv');
        $this->putMovie($rFile);
        file_put_contents(WATCH_TMP_PATH . 'movie_1_101.pcache', json_encode(array('id' => 50, 'source' => $rFile)));

        $this->import($this->thread('movie', '101'));

        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams`;'));
        $this->assertSame(array(), $this->rows('SELECT `id` FROM `watch_logs`;'));
    }

    public function testMovieWithoutOverrideBouquetsUsesItsGenreBouquets(): void {
        $this->putMovie($this->file('oak.mkv'));

        $this->import($this->thread('movie', '101', array('fb_bouquets' => '[5]')));

        $this->assertSame(array(9), array_column($this->tmpFiles('pbouquet'), 'bouquet_id'));
    }

    public function testMovieWithoutGenreBouquetsUsesFallbackBouquets(): void {
        $this->putMovie($this->file('oak.mkv'), '<Genre tag="Comedy"/>');

        $this->import($this->thread('movie', '101', array('fb_bouquets' => '[5]', 'fb_category_id' => 6)));

        $this->assertSame(array(5), array_column($this->tmpFiles('pbouquet'), 'bouquet_id'));
    }

    public function testMovieMaxGenresZeroMeansAllGenres(): void {
        $this->putMovie($this->file('oak.mkv'), '<Genre tag="Comedy"/><Genre tag="Drama"/>');

        $this->import($this->thread('movie', '101', array('max_genres' => 0)));

        $rStream = $this->rows('SELECT `category_id`, `movie_properties` FROM `streams`;')[0];
        $this->assertSame('[7]', $rStream['category_id']);
        $this->assertSame('Comedy, Drama', json_decode($rStream['movie_properties'], true)['genre']);
    }

    public function testMovieTakesTheFolderStreamSettings(): void {
        $this->putMovie($this->file('oak.mkv'));

        $this->import($this->thread('movie', '101', array('movie_symlink' => 1, 'read_native' => 1, 'target_container' => 'mp4')));

        $rStream = $this->rows('SELECT `movie_symlink`, `read_native`, `target_container` FROM `streams`;')[0];
        $this->assertSame(array(1, 1, 'mp4'), array((int) $rStream['movie_symlink'], (int) $rStream['read_native'], $rStream['target_container']));
    }

    public function testRepeatedBrokenFileKeepsOneLogRow(): void {
        $this->putMovie('/nonexistent/oak.mkv');

        $this->import($this->thread('movie', '101'));
        $this->import($this->thread('movie', '101'));

        $this->assertCount(1, $this->rows('SELECT `id` FROM `watch_logs`;'));
    }

    public function testMultiPartMovieTakesTheLargestAccessiblePart(): void {
        $rSmall = $this->file('cd1.mkv');
        $rLarge = $this->file('cd2.avi');
        self::$plex->put('/library/metadata/101', '<MediaContainer><Video ratingKey="101" type="movie" title="Oak Street"><Media id="1">'
            . '<Part key="/p/1" file="' . $rSmall . '" size="10"/><Part key="/p/2" file="' . $rLarge . '" size="20"/><Part key="/p/3" file="/gone.mkv" size="99"/>'
            . '</Media><Genre tag="Drama"/></Video></MediaContainer>');

        $this->import($this->thread('movie', '101'));

        $this->assertSame(array(array('stream_source' => self::source($rLarge), 'target_container' => 'avi')), $this->rows('SELECT `stream_source`, `target_container` FROM `streams`;'));
    }

    // --- shows -----------------------------------------------------------

    public function testShowWithoutCategoryImportsNoOrphanEpisodes(): void {
        $this->putShow($this->file('e1.mkv'), $this->file('e2.mkv'));

        $this->import($this->thread('show', '200', array('plex_categories' => array(3 => array(), 4 => array()))));

        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams_series`;'));
        $this->assertSame(array(), $this->rows('SELECT `id` FROM `streams`;'));
    }

    public function testUpgradedEpisodeGetsItsOwnNewFile(): void {
        $rEp1 = $this->file('e1-4k.mkv');
        $rEp2 = $this->file('e2.mkv');
        $this->putShow($rEp1, $rEp2);
        $this->db->query("INSERT INTO `streams_series` (`id`, `title`, `plex_uuid`, `cover`, `seasons`) VALUES (30, 'Show', '1_200', 'cover.jpg', '[]');");
        $this->db->query("INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES (60, 5, '[\"s:1:/old-e1.mkv\"]');");
        file_put_contents(WATCH_TMP_PATH . 'series_1_200.pcache', json_encode(array('1_1' => array('id' => 60, 'source' => 's:1:/old-e1.mkv'))));

        $this->import($this->thread('show', '200'));

        $this->assertSame(self::source($rEp1), $this->rows('SELECT `stream_source` FROM `streams` WHERE `id` = 60;')[0]['stream_source']);
        $this->assertContains(6, array_column($this->rows('SELECT `status` FROM `watch_logs`;'), 'status'));
        $this->assertSame(self::source($rEp2), $this->rows('SELECT `stream_source` FROM `streams` WHERE `id` <> 60;')[0]['stream_source']);
    }

    public function testExistingShowWithoutCoverGetsPosterAndBackdrop(): void {
        $this->putShow($this->file('e1.mkv'), $this->file('e2.mkv'));
        $this->db->query("INSERT INTO `streams_series` (`id`, `title`, `plex_uuid`, `cover`, `seasons`) VALUES (30, 'Show', '1_200', '', '[]');");

        $this->import($this->thread('show', '200'));

        $rSeries = $this->rows('SELECT `cover`, `backdrop_path` FROM `streams_series` WHERE `id` = 30;')[0];
        $this->assertStringContainsString('/library/metadata/200/thumb/1', $rSeries['cover']);
        $this->assertStringContainsString('/library/metadata/200/art/1', json_decode($rSeries['backdrop_path'], true)[0]);
    }

    public function testNewShowCreatesSeriesAndEpisodes(): void {
        $this->putShow($this->file('e1.mkv'), $this->file('e2.mp4'));

        $this->import($this->thread('show', '200'));

        $rSeries = $this->rows('SELECT * FROM `streams_series`;');
        $this->assertCount(1, $rSeries);
        $this->assertSame('Show', $rSeries[0]['title']);
        $this->assertSame(777, (int) $rSeries[0]['tmdb_id']);
        $this->assertSame('1_200', $rSeries[0]['plex_uuid']);
        $this->assertSame('[8]', $rSeries[0]['category_id']);
        $this->assertSame(2020, (int) $rSeries[0]['year']);
        $this->assertSame('Season 1', json_decode($rSeries[0]['seasons'], true)[0]['name']);
        $this->assertSame(array(array('type' => 'series', 'bouquet_id' => 10, 'id' => (int) $rSeries[0]['id'])), $this->tmpFiles('pbouquet'));

        $rEpisodes = $this->rows('SELECT `id`, `type`, `stream_display_name`, `series_no`, `target_container` FROM `streams` ORDER BY `id`;');
        $this->assertSame(array('Show - S01E01 - Pilot', 'Show - S01E02 - Second'), array_column($rEpisodes, 'stream_display_name'));
        $this->assertSame(array('mkv', 'mp4'), array_column($rEpisodes, 'target_container'));
        $this->assertSame(array(5, 5), array_map('intval', array_column($rEpisodes, 'type')));
        $this->assertSame(array($rSeries[0]['id'], $rSeries[0]['id']), array_column($rEpisodes, 'series_no'));
        $this->assertSame(array('1_1', '1_2'), array_map(fn($r) => $r['season_num'] . '_' . $r['episode_num'], $this->rows('SELECT `season_num`, `episode_num` FROM `streams_episodes` ORDER BY `episode_num`;')));
        $this->assertCount(4, $this->rows('SELECT `stream_id` FROM `streams_servers`;'));
        $this->assertSame(array(1, 1), array_column($this->rows('SELECT `status` FROM `watch_logs`;'), 'status'));
    }

    public function testExistingShowUpdatesSeasonsAndAddsOnlyNewEpisodes(): void {
        $rEp1 = $this->file('e1.mkv');
        $this->putShow($rEp1, $this->file('e2.mkv'));
        $this->db->query("INSERT INTO `streams_series` (`id`, `title`, `plex_uuid`, `cover`, `seasons`) VALUES (30, 'Show', '1_200', 'cover.jpg', '[]');");

        $this->import($this->thread('show', '200'), array(self::source($rEp1)));

        $this->assertSame('Season 1', json_decode($this->rows('SELECT `seasons` FROM `streams_series` WHERE `id` = 30;')[0]['seasons'], true)[0]['name']);
        $rEpisodes = $this->rows('SELECT `stream_display_name`, `series_no` FROM `streams`;');
        $this->assertSame(array(array('stream_display_name' => 'Show - S01E02 - Second', 'series_no' => 30)), $rEpisodes);
    }
}
