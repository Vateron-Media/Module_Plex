<?php

/**
 * A throwaway Plex Media Server: `php -S` serving XML fixtures put() under a temp dir,
 * so the import code runs its real HTTP + XML parsing paths.
 */
final class FakePlex {

    public string $dir;

    public int $port;

    /** @var resource */
    private $rProcess;

    public function __construct() {
        $this->dir = sys_get_temp_dir() . '/fake_plex_' . getmypid() . '_' . uniqid();
        mkdir($this->dir, 0775, true);

        $rSocket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($rSocket, false), ':'), 1);
        fclose($rSocket);

        $this->rProcess = proc_open(
            array(PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/fake_plex_router.php'),
            array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
            $rPipes,
            null,
            array('FAKE_PLEX_DIR' => $this->dir)
        );

        for ($i = 0; $i < 100; $i++) {
            $rConn = @fsockopen('127.0.0.1', $this->port);
            if ($rConn) {
                fclose($rConn);
                return;
            }
            usleep(20000);
        }
        throw new RuntimeException('FakePlex did not start on port ' . $this->port);
    }

    /** Serve $xml at request path $path (e.g. '/library/metadata/101'). */
    public function put(string $path, string $xml): void {
        $rFile = $this->dir . $path . '.xml';
        if (!is_dir(dirname($rFile))) {
            mkdir(dirname($rFile), 0775, true);
        }
        file_put_contents($rFile, $xml);
    }

    public function __destruct() {
        proc_terminate($this->rProcess);
        proc_close($this->rProcess);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }
}
