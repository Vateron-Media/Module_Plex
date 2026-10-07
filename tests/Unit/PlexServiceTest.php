<?php

use PHPUnit\Framework\TestCase;
use XcVm\Module\Plex\PlexService;
use XcVm\Tests\Support\InstallSchema;

/**
 * The Plex Settings save: per-genre category + bouquets for movie and TV genres.
 */
final class PlexServiceTest extends TestCase {

    private TestDb $db;

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec(InstallSchema::table('watch_categories'));
        $this->db->exec(InstallSchema::table('settings'));
        PlexService::setDb($this->db);
    }

    public function testGenreMappingsAreSavedPerLibraryType(): void {
        $this->db->query("INSERT INTO `watch_categories` (`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES (3, 1, 'Drama', 0, '[]'), (4, 1, 'Drama', 0, '[]'), (3, 2, 'Comedy', 5, '[1]');");

        $rResult = PlexService::editPlexSettings(array(
            'genre_1' => '7', 'bouquet_1' => array('4', '9'),
            'genretv_1' => '8', 'bouquettv_1' => array('10'),
            'genre_2' => '0',
            'scan_seconds' => 3600, 'max_genres' => 3, 'thread_count_movie' => 25, 'thread_count_show' => 5,
        ));

        $this->assertSame(STATUS_SUCCESS, $rResult['status']);
        $this->db->query('SELECT `type`, `genre_id`, `category_id`, `bouquets` FROM `watch_categories` ORDER BY `type`, `genre_id`;');
        $this->assertSame(array(
            array('type' => 3, 'genre_id' => 1, 'category_id' => 7, 'bouquets' => '[4,9]'),
            array('type' => 3, 'genre_id' => 2, 'category_id' => 0, 'bouquets' => '[]'),
            array('type' => 4, 'genre_id' => 1, 'category_id' => 8, 'bouquets' => '[10]'),
        ), array_map(fn($r) => array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $r), $this->db->get_rows()));
    }
}
