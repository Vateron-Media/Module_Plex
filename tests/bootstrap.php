<?php

/**
 * Test bootstrap for the standalone Module_Plex repository.
 *
 * Like Module_Watchfolder: the module only lives under XC_VM/src/Modules/ once
 * deployed, so this dev checkout reuses the sibling XC_VM's own test bootstrap
 * (composer autoload, MAIN_HOME, the TestDb MariaDB harness) and loads the
 * module's classes directly.
 */

$xcVmTestsDir = dirname(__DIR__, 2) . '/XC_VM/tests';

if (!file_exists($xcVmTestsDir . '/bootstrap.php')) {
    throw new RuntimeException('Expected a sibling XC_VM checkout at ' . dirname($xcVmTestsDir) . ' with its test bootstrap.');
}

require_once $xcVmTestsDir . '/bootstrap.php';

if (!defined('SERVER_ID')) {
    define('SERVER_ID', 1);
}
if (!defined('WATCH_TMP_PATH')) {
    define('WATCH_TMP_PATH', sys_get_temp_dir() . '/plex_module_tests/tmp/');
}
if (!is_dir(WATCH_TMP_PATH)) {
    mkdir(WATCH_TMP_PATH, 0775, true);
}

require_once dirname(__DIR__) . '/PlexItem.php';
