<?php

namespace XcVm\Module\Plex;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Module\Watch\WatchService;

/**
 * Plex Module Controller
 *
 * Handles every Plex module route:
 * - Plex Sync server list (index)
 * - Add/edit a library (add)
 * - Plex settings (settings)
 * - API: enable/disable/kill/library/sections actions
 *
 * @see PlexService
 * @see PlexRepository
 * @see PlexModule
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexController {

    /**
     * Path to the module's views directory
     * @var string
     */
    protected $viewsPath;


    public function __construct() {
        $this->viewsPath = __DIR__ . '/views';
    }

    public function index() {
        $this->render('Plex Sync', 'index', array('rPlexServers' => PlexRepository::getPlexServers()));
    }

    public function add() {
        $rID = RequestManager::getAll()['id'] ?? null;
        $rFolder = ($rID === null ? null : WatchService::getWatchFolder($rID));
        if ($rID !== null && !$rFolder) {
            AdminHelpers::goHome();
        }
        $rVars = array('rBouquets' => BouquetService::getAllSimple());
        if ($rFolder) {
            $rVars['rFolder'] = $rFolder;
        }
        $this->render($rFolder ? 'Edit Library' : 'Add Library', 'library_edit', $rVars);
    }

    public function settings() {
        $this->render('Plex Settings', 'settings', array(
            'rBouquets' => BouquetService::getAllSimple(),
            'rGenres' => array('movie' => PlexCron::getPlexCategories(3), 'series' => PlexCron::getPlexCategories(4)),
        ));
    }

    /**
     * Admin shell around a module view, then its <view>_scripts.php.
     * $_STATUS (the ?status= banner) is a global set by core's AdminScopeBootstrap.
     */
    private function render($_TITLE, $rView, array $rVars) {
        global $rMobile, $rSettings, $rServers, $rPermissions, $_STATUS;
        extract($rVars);

        LayoutRenderer::renderHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/' . $rView . '.php';
        LayoutRenderer::renderFooter('admin');
        include $this->viewsPath . '/' . $rView . '_scripts.php';
    }

    // ───────────────────────────────────────────────────────────
    //  API actions (JSON)
    // ───────────────────────────────────────────────────────────

    /** action=settings_plex_save — save the Plex Settings form (POST only). */
    public function apiSaveSettings() {
        self::postOnly();
        self::reply(PlexService::editPlexSettings(RequestManager::getAll()), 'settings_plex');
    }

    /** action=plex_library_save — add or edit a library (POST only). */
    public function apiSaveLibrary() {
        self::postOnly();
        self::reply(PlexService::processPlexSync(RequestManager::getAll()), 'plex');
    }

    /**
     * State changes never run from a GET (a CSRF via <img src>): answer 405,
     * as core's post.php does.
     */
    private static function postOnly() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['result' => false, 'status' => 0, 'error' => 'Method Not Allowed']);
            exit();
        }
    }

    /** The JSON the forms expect: redirect to $rPage on success, else the error. */
    private static function reply(array $rReturn, string $rPage) {
        if ($rReturn['status'] == STATUS_SUCCESS) {
            self::json(['result' => true, 'location' => $rPage . '?status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
        }
        self::json(['result' => false, 'data' => $rReturn['data'] ?? null, 'status' => $rReturn['status']]);
    }

    private static function json(array $rData) {
        echo json_encode($rData);
        exit();
    }

    public function apiEnable() {
        PlexRepository::enableAll();
        self::json(['result' => true]);
    }

    public function apiDisable() {
        PlexRepository::disableAll();
        self::json(['result' => true]);
    }

    public function apiKill() {
        PlexService::killSync();
        self::json(['result' => true]);
    }

    public function apiLibrary() {
        $rRequest = RequestManager::getAll();
        $rFolderID = $rRequest['folder_id'] ?? 0;
        $rSub = $rRequest['sub'] ?? '';

        if ($rSub === 'delete') {
            WatchService::deleteWatchFolder($rFolderID);
            self::json(['result' => true]);
        }
        if ($rSub === 'force' && ($rFolder = WatchService::getWatchFolder($rFolderID))) {
            PlexService::forcePlex($rFolder['server_id'], $rFolder['id']);
            self::json(['result' => true]);
        }
        self::json(['result' => false]);
    }

    public function apiSections() {
        $rRequest = RequestManager::getAll();
        $rIP = $rRequest['ip'] ?? '';
        $rPort = $rRequest['port'] ?? '';

        $rToken = PlexAuth::getPlexToken($rIP, $rPort, $rRequest['username'] ?? '', $rRequest['password'] ?? '');
        $rSections = PlexRepository::getPlexSections($rIP, $rPort, $rToken);
        self::json($rSections ? ['result' => true, 'data' => $rSections] : ['result' => false]);
    }
}
