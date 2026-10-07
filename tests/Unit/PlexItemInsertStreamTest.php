<?php

use PHPUnit\Framework\TestCase;
use XcVm\Module\Plex\PlexItem;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * PlexItem::insertStream() against the real install schema: a stream must never
 * outlive a failed server/episode link. Such a server-less row is invisible to
 * PlexCron's cache (built through JOIN streams_servers), so the next scan
 * imported the same movie again — the "No Server" duplicate.
 */
final class PlexItemInsertStreamTest extends TestCase {

    private QueryLogDb $db;

    private const INSERT = 'REPLACE INTO `streams`(`type`, `stream_display_name`) VALUES(?, ?);';

    protected function setUp(): void {
        $rInner = new TestDb();
        foreach (array('streams', 'streams_servers', 'streams_episodes') as $rTable) {
            $rInner->exec(InstallSchema::table($rTable));
        }
        $this->db = new QueryLogDb($rInner);
        // Panel's Database::query() reports SQL errors as false instead of throwing.
        $this->db->rFailLikeThePanel = true;
        PlexItem::setDb($this->db);
    }

    private function rowCount(string $rTable): int {
        $this->db->query('SELECT COUNT(*) FROM `' . $rTable . '`;');
        return (int) $this->db->get_col();
    }

    public function testNewStreamIsLinkedToEveryServer(): void {
        $rID = PlexItem::insertStream(self::INSERT, array(2, 'Movie'), array(1, 2));

        $this->assertGreaterThan(0, (int) $rID);
        $this->db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ? ORDER BY `server_id`;', $rID);
        $this->assertSame(array(1, 2), array_map('intval', array_column($this->db->get_rows(), 'server_id')));
    }

    public function testFailedServerLinkLeavesNoServerlessStream(): void {
        $this->db->rRefuse = '/streams_servers/';

        $this->assertFalse(PlexItem::insertStream(self::INSERT, array(2, 'Movie'), array(1)));
        $this->assertSame(0, $this->rowCount('streams'));
    }

    public function testRepeatedServerViolatesUniqueKeyAndRollsBack(): void {
        // Why PlexItem::run() de-duplicates $rServers before calling in.
        $this->assertFalse(PlexItem::insertStream(self::INSERT, array(2, 'Movie'), array(1, 1)));
        $this->assertSame(0, $this->rowCount('streams'));
        $this->assertSame(0, $this->rowCount('streams_servers'));
    }

    public function testEpisodeRowIsWrittenWithTheStream(): void {
        $rID = PlexItem::insertStream(self::INSERT, array(5, 'Episode'), array(1), array(2, 7, 3));

        $this->db->query('SELECT `season_num`, `series_id`, `episode_num` FROM `streams_episodes` WHERE `stream_id` = ?;', $rID);
        $this->assertSame(array('season_num' => 2, 'series_id' => 7, 'episode_num' => 3), array_map('intval', $this->db->get_row()));
    }

    public function testFailedEpisodeRowRollsBackStreamAndServers(): void {
        $this->db->rRefuse = '/streams_episodes/';

        $this->assertFalse(PlexItem::insertStream(self::INSERT, array(5, 'Episode'), array(1), array(2, 7, 3)));
        $this->assertSame(0, $this->rowCount('streams'));
        $this->assertSame(0, $this->rowCount('streams_servers'));
    }

    public function testInsideCallerTransactionCommitIsLeftToTheCaller(): void {
        $this->db->beginTransaction();

        $this->assertGreaterThan(0, (int) PlexItem::insertStream(self::INSERT, array(2, 'Movie'), array(1)));
        $this->db->rollback();

        $this->assertSame(0, $this->rowCount('streams'));
    }
}
