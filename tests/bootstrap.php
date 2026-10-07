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
if (!defined('STATUS_SUCCESS')) {
    \XcVm\Core\Config\ConstantsInitializer::initStatus();
}
if (!defined('CACHE_TMP_PATH')) {
    define('CACHE_TMP_PATH', sys_get_temp_dir() . '/plex_module_tests/cache/');
}
if (!defined('WATCH_TMP_PATH')) {
    define('WATCH_TMP_PATH', sys_get_temp_dir() . '/plex_module_tests/tmp/');
}
if (!is_dir(WATCH_TMP_PATH)) {
    mkdir(WATCH_TMP_PATH, 0775, true);
}

require_once __DIR__ . '/Support/FakePlex.php';

// Deployed modules get ModuleLoader's per-module PSR-4 autoloader; mirror it for
// this module and its `watch` dependency (sibling Module_Watchfolder checkout).
spl_autoload_register(function (string $rClass): void {
    $rRoots = array('XcVm\\Module\\Plex\\' => dirname(__DIR__), 'XcVm\\Module\\Watch\\' => dirname(__DIR__, 2) . '/Module_Watchfolder');
    foreach ($rRoots as $rPrefix => $rDir) {
        if (strncmp($rClass, $rPrefix, strlen($rPrefix)) === 0 && is_file($rFile = $rDir . '/' . str_replace('\\', '/', substr($rClass, strlen($rPrefix))) . '.php')) {
            require_once $rFile;
        }
    }
});
