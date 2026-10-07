<?php

use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;

$rFolder = $rFolder ?? null;
$e = static fn($rValue): string => htmlspecialchars((string) $rValue, ENT_QUOTES, 'UTF-8');

// On/off switches: name => [label, on for a new library, help text]. Two per row.
$rSwitch = function (string $rName, array $rSpec) use ($rFolder, $e): void {
    list($rLabel, $rDefault, $rHelp) = $rSpec;
    $rChecked = ($rFolder ? $rFolder[$rName] : $rDefault);
    echo '<div class="col-md-6"><div class="form-check form-switch">'
        . '<input name="' . $rName . '" id="' . $rName . '" type="checkbox" value="1" class="form-check-input"' . ($rChecked ? ' checked' : '') . ' />'
        . '<label class="form-check-label" for="' . $rName . '">' . $rLabel
        . ($rHelp ? ' <i title="' . $e($rHelp) . '" class="icon-base ti tabler-help-circle text-secondary"></i>' : '')
        . '</label></div></div>';
};
$rSwitchRows = function (array $rSwitches) use ($rSwitch): void {
    foreach (array_chunk($rSwitches, 2, true) as $rRow) {
        echo '<div class="row g-3 mb-6">';
        foreach ($rRow as $rName => $rSpec) {
            $rSwitch($rName, $rSpec);
        }
        echo '</div>';
    }
};

// Selected servers first (main, then extra), then the rest.
$rAllServers = ServerRepository::getAll();
$rActiveServers = array();
if ($rFolder) {
    if ($rFolder['server_id']) {
        $rActiveServers[] = intval($rFolder['server_id']);
    }
    foreach ((json_decode($rFolder['server_add'] ?? '[]', true) ?: array()) as $rServerID) {
        $rActiveServers[] = intval($rServerID);
    }
}
$rLibraries = ($rFolder ? (json_decode($rFolder['plex_libraries'], true) ?: array()) : array());
$rCategoryGroups = array('Movies' => CategoryService::getAllByType('movie'), 'Series' => CategoryService::getAllByType('series'));
$rContainer = (($rFolder['target_container'] ?? null) ?: 'auto');
?>

<div class="d-flex align-items-center mb-4">
    <a href="plex" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rFolder ? 'Edit' : 'Add' ?> Library</h4>
</div>

<form action="#" method="POST" id="library-form" autocomplete="off">
    <?php if ($rFolder) : ?>
        <input type="hidden" name="edit" value="<?= intval($rFolder['id']); ?>" />
    <?php endif; ?>
    <input type="hidden" name="libraries" id="libraries" value="<?= $e($rFolder['plex_libraries'] ?? ''); ?>" />

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#folder-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i>Details</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#settings" role="tab"><i class="icon-base ti tabler-settings me-1"></i>Settings</button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="folder-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="server_id">Server Name</label>
                        <select name="server_id[]" id="server_id" class="form-select" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach ($rActiveServers as $rServerID) : ?>
                                <option value="<?= $rServerID; ?>" selected><?= $e($rAllServers[$rServerID]['server_name'] ?? $rServerID); ?></option>
                            <?php endforeach; ?>
                            <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer) : ?>
                                <?php if (!in_array(intval($rServer['id']), $rActiveServers)) : ?>
                                    <option value="<?= intval($rServer['id']); ?>"><?= $e($rServer['server_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-8">
                            <label class="form-label" for="plex_ip">Plex Server</label>
                            <input type="text" id="plex_ip" name="plex_ip" class="form-control" value="<?= $e($rFolder['plex_ip'] ?? ''); ?>" placeholder="Server IP" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="plex_port">Port</label>
                            <input type="text" id="plex_port" name="plex_port" class="form-control text-center" value="<?= $e($rFolder['plex_port'] ?? ''); ?>" placeholder="Port" required>
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" value="<?= $e($rFolder['plex_username'] ?? ''); ?>" placeholder="Username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" value="<?= $e($rFolder['plex_password'] ?? ''); ?>" placeholder="Password">
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="library_id">Library</label>
                        <div class="input-group">
                            <select id="library_id" name="library_id" class="form-select">
                                <?php foreach ($rLibraries as $rLibrary) : ?>
                                    <option value="<?= $e($rLibrary['key']); ?>" <?= $rFolder['directory'] == $rLibrary['key'] ? 'selected' : ''; ?>><?= $e($rLibrary['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="button" id="scanPlex"><i class="icon-base ti tabler-refresh"></i></button>
                        </div>
                    </div>
                    <?php $rSwitchRows(array(
                        'active' => array('Enabled', true, ''),
                        'direct_proxy' => array('Direct Stream', true, "When using direct source, hide the original Plex URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently."),
                    )); ?>
                </div>

                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <?php $rSwitchRows(array(
                        'read_native' => array('Native Frames', false, 'Read input video at native frame rate.'),
                        'movie_symlink' => array('Create Symlink', true, 'Generate a symlink to the original file instead of encoding. File needs to exist on all selected servers.'),
                        'auto_encode' => array('Auto-Encode', true, 'Start encoding as soon as the movie is added.'),
                        'scan_missing' => array("Scan Missing ID's", false, "Check all Plex ID's in the XC_VM database against Plex database and scan missing items too. If this is off, XC_VM will only request items modified after the last scan date. Turning this on will increase time taken to scan as the entire library needs to be scanned instead of the recent items."),
                        'auto_upgrade' => array('Auto-Upgrade Quality', true, 'Automatically upgrade quality if the system finds a new file with better quality that has the same Plex or TMDb ID.'),
                        'store_categories' => array('Store Categories', true, 'Save unrecognised categories to Plex Settings, this will allow you to allocate a category after the first run and it will then be added on the second run.'),
                        'check_tmdb' => array('Check Against TMDb', true, "If the item has a TMDb ID, check it against the database to ensure duplicates aren't created due to previous content in the XC_VM system."),
                        'remove_subtitles' => array('Remove Existing Subtitles', false, "Remove existing subtitles from file before encoding. You can't remove hardcoded subtitles using this method."),
                    )); ?>
                    <div class="mb-6">
                        <label class="form-label" for="target_container"><?= $language::get('target_container'); ?> <i title="Which container to use when transcoding files." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <select name="target_container" id="target_container" class="form-select">
                            <?php foreach (array('auto', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rOption) : ?>
                                <option value="<?= $rOption; ?>" <?= $rContainer == $rOption ? 'selected' : ''; ?>><?= $rOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach (array('override_bouquets' => array('Override Bouquets', 'bouquets'), 'fallback_bouquets' => array('Fallback Bouquets', 'fb_bouquets')) as $rField => list($rLabel, $rColumn)) :
                        $rSelected = (json_decode($rFolder[$rColumn] ?? '[]', true) ?: array());
                    ?>
                        <div class="mb-6">
                            <label class="form-label" for="<?= $rField; ?>"><?= $rLabel; ?></label>
                            <select name="<?= $rField; ?>[]" id="<?= $rField; ?>" class="form-select" multiple="multiple" data-placeholder="Choose...">
                                <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
                                    <option value="<?= intval($rBouquet['id']); ?>" <?= in_array(intval($rBouquet['id']), $rSelected) ? 'selected' : ''; ?>><?= $e($rBouquet['bouquet_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach (array('override_category' => array('Override Category', 'category_id'), 'fallback_category' => array('Fallback Category', 'fb_category_id')) as $rField => list($rLabel, $rColumn)) :
                        $rSelected = intval($rFolder[$rColumn] ?? 0);
                    ?>
                        <div class="mb-6">
                            <label class="form-label" for="<?= $rField; ?>"><?= $rLabel; ?></label>
                            <select name="<?= $rField; ?>" id="<?= $rField; ?>" class="form-select">
                                <option value="0" <?= $rSelected == 0 ? 'selected' : ''; ?>>Do Not Use</option>
                                <?php foreach ($rCategoryGroups as $rGroup => $rCategories) : ?>
                                    <optgroup label="<?= $rGroup; ?>">
                                        <?php foreach ($rCategories as $rCategory) : ?>
                                            <option value="<?= intval($rCategory['id']); ?>" <?= $rSelected == intval($rCategory['id']) ? 'selected' : ''; ?>><?= $e($rCategory['category_name']); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <div class="mb-6">
                        <label class="form-label" for="transcode_profile_id">Transcoding Profile <i title="Select a transcoding profile to autoamtically encode videos." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                            <option value="0" <?= intval($rFolder['transcode_profile_id'] ?? 0) == 0 ? 'selected' : ''; ?>>Transcoding Disabled</option>
                            <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile) : ?>
                                <option value="<?= intval($rProfile['profile_id']); ?>" <?= intval($rFolder['transcode_profile_id'] ?? 0) == intval($rProfile['profile_id']) ? 'selected' : ''; ?>><?= $e($rProfile['profile_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <input name="submit_folder" type="submit" class="btn btn-primary" value="<?= $rFolder ? 'Edit' : 'Add'; ?>" />
    </div>
</form>
