<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Module\Plex\PlexCron;
use XcVm\Tests\Support\InstallSchema;

/**
 * PlexCron's post-processing of what `plex_item` workers leave in WATCH_TMP_PATH:
 * new genres (.pcat) and bouquet additions (.pbouquet).
 */
final class PlexCronTest extends TestCase {

    private TestDb $db;

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec(InstallSchema::table('watch_categories'));
        $this->db->exec(InstallSchema::table('bouquets'));
        PlexCron::setDb($this->db);
        array_map('unlink', glob(WATCH_TMP_PATH . '*'));
    }

    private function rows(string $rQuery): array {
        $this->db->query($rQuery);
        return $this->db->get_rows();
    }

    private function tmp(string $rName, array $rData): void {
        file_put_contents(WATCH_TMP_PATH . $rName, json_encode($rData));
    }

    public function testNewGenresAreAddedWithTheNextGenreIdPerType(): void {
        $this->db->query("INSERT INTO `watch_categories` (`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES (3, 4, 'Drama', 7, '[]'), (4, 2, 'Drama', 8, '[]');");
        $this->tmp('a.pcat', array('type' => 'movie', 'title' => 'Comedy'));
        $this->tmp('b.pcat', array('type' => 'movie', 'title' => 'Drama'));
        $this->tmp('c.pcat', array('type' => 'show', 'title' => 'Crime'));

        PlexCron::checkCategories();

        $this->assertSame(
            array('3:4:Drama', '3:5:Comedy', '4:2:Drama', '4:3:Crime'),
            array_map(fn($r) => $r['type'] . ':' . $r['genre_id'] . ':' . $r['genre'], $this->rows('SELECT * FROM `watch_categories` ORDER BY `type`, `genre_id`;'))
        );
        $this->assertSame(array(), glob(WATCH_TMP_PATH . '*.pcat'));
    }

    public function testGetPlexCategoriesKeysRowsByGenre(): void {
        $this->db->query("INSERT INTO `watch_categories` (`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES (3, 1, 'Drama', 7, '[]'), (4, 1, 'Crime', 8, '[]');");

        $this->assertSame(array('Drama'), array_keys(PlexCron::getPlexCategories(3)));
        $this->assertSame(array('Drama', 'Crime'), array_keys(PlexCron::getPlexCategories()));
    }

    public function testBouquetFilesAreMergedIntoTheirBouquetsOnce(): void {
        $this->db->query("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_movies`, `bouquet_series`) VALUES (4, 'B', '[1]', '[]');");
        $this->tmp('a.pbouquet', array('type' => 'movie', 'bouquet_id' => 4, 'id' => 2));
        $this->tmp('b.pbouquet', array('type' => 'movie', 'bouquet_id' => 4, 'id' => 1));
        $this->tmp('c.pbouquet', array('type' => 'series', 'bouquet_id' => 4, 'id' => 9));
        $this->tmp('d.pbouquet', array('type' => 'movie', 'bouquet_id' => 99, 'id' => 3));

        PlexCron::checkBouquets();

        $this->assertSame(array(array('bouquet_movies' => '[1,2]', 'bouquet_series' => '[9]')), $this->rows('SELECT `bouquet_movies`, `bouquet_series` FROM `bouquets`;'));
        $this->assertSame(array(), glob(WATCH_TMP_PATH . '*.pbouquet'));
    }

    // --- scanFolder: which library items get queued for a worker -------------

    private function folder(FakePlex $rPlex, string $rDirectory, array $rOverride = array()): array {
        return $rOverride + array(
            'id' => 5, 'directory' => $rDirectory, 'plex_ip' => '127.0.0.1', 'plex_port' => $rPlex->port,
            'last_run' => 1000, 'scan_missing' => 1,
            'read_native' => 0, 'movie_symlink' => 1, 'remove_subtitles' => 0, 'auto_encode' => 1, 'auto_upgrade' => 1,
            'transcode_profile_id' => 0, 'fb_bouquets' => '[]', 'store_categories' => 1, 'category_id' => 0, 'bouquets' => '[4]',
            'fb_category_id' => 0, 'check_tmdb' => 1, 'target_container' => 'auto', 'server_add' => '[]', 'direct_proxy' => 1,
        );
    }

    private function plexLibrary(): FakePlex {
        $rPlex = new FakePlex();
        $rPlex->put('/library/sections', '<MediaContainer><Directory key="1" type="movie" title="Movies"/><Directory key="2" type="show" title="TV"/></MediaContainer>');
        // totalSize 150 → two pages; FakePlex ignores paging, so the same items come back on both.
        $rPlex->put('/library/sections/1/all', '<MediaContainer totalSize="150" size="3"><Video ratingKey="101" updatedAt="2000"/><Video ratingKey="102" updatedAt="500"/><Video ratingKey="103" updatedAt="500"/></MediaContainer>');
        $rPlex->put('/library/sections/2/all', '<MediaContainer totalSize="2" size="2"><Directory ratingKey="201" updatedAt="500" leafCount="4"/><Directory ratingKey="202" updatedAt="500" leafCount="2"/></MediaContainer>');
        return $rPlex;
    }

    private function scan(array $rFolder, array $rKnown, $rForce = null) {
        ob_start();
        try {
            return PlexCron::scanFolder($rFolder, 'tok', $rKnown + array('uuids' => array(), 'leaf_counts' => array()), array(3 => array(), 4 => array()), $rForce);
        } finally {
            ob_end_clean();
        }
    }

    public function testMovieScanQueuesUpdatedAndMissingItemsOnceEach(): void {
        SettingsManager::set(array('thread_count_movie' => 7, 'max_genres' => 3));
        $rPlex = $this->plexLibrary();

        $rScan = $this->scan($this->folder($rPlex, '1'), array('uuids' => array('1_102')));

        $this->assertSame(7, $rScan['threads']);
        $this->assertSame(array('1_101', '1_103'), array_keys($rScan['items']));
        $rItem = $rScan['items']['1_101'];
        $this->assertSame(array('movie', '101', '127.0.0.1', $rPlex->port, 'tok', 3, '[4]', 1), array($rItem['type'], $rItem['key'], $rItem['ip'], $rItem['port'], $rItem['token'], $rItem['max_genres'], $rItem['bouquets'], $rItem['direct_proxy']));
    }

    public function testWithoutScanMissingOnlyUpdatedMoviesAreQueued(): void {
        $rPlex = $this->plexLibrary();

        $this->assertSame(array('1_101'), array_keys($this->scan($this->folder($rPlex, '1', array('scan_missing' => 0)), array())['items']));
    }

    public function testForcedScanQueuesEverything(): void {
        $rPlex = $this->plexLibrary();

        $this->assertSame(array('1_101', '1_102', '1_103'), array_keys($this->scan($this->folder($rPlex, '1', array('scan_missing' => 0)), array(), 5)['items']));
    }

    public function testShowIsQueuedWhenItsEpisodeCountChanged(): void {
        $rPlex = $this->plexLibrary();

        $rScan = $this->scan($this->folder($rPlex, '2'), array('leaf_counts' => array('2_201' => 3, '2_202' => 2)));

        $this->assertSame(array('2_201'), array_keys($rScan['items']));
        $this->assertSame('show', $rScan['items']['2_201']['type']);
    }

    public function testUnknownSectionQueuesNothing(): void {
        $rPlex = $this->plexLibrary();

        $this->assertSame(array('threads' => 1, 'items' => array()), $this->scan($this->folder($rPlex, '9'), array()));
    }

    public function testUnreachableServerIsReportedAsNoSections(): void {
        $rPlex = $this->plexLibrary();

        $this->assertNull($this->scan($this->folder($rPlex, '1', array('plex_port' => 1)), array()));
    }
}
