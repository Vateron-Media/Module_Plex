<?php

use PHPUnit\Framework\TestCase;

/**
 * The sign-in to plex.tv sends the Plex account's password: its request
 * checks the server's certificate. It was switched off, so anyone on the path
 * between the panel and plex.tv could answer as plex.tv and read it.
 */
final class PlexAuthTlsTest extends TestCase {
    public function testNoRequestSwitchesCertificateChecksOff(): void {
        foreach (glob(dirname(__DIR__, 2) . '/*.php') as $rFile) {
            $rSource = (string) file_get_contents($rFile);
            $this->assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFY(PEER|HOST)\s*=>\s*(false|0)\b/', $rSource, basename($rFile));
        }
    }
}
