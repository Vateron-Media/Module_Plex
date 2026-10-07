<?php

/**
 * Plex settings (Bootstrap 5, new-UI). Setup thread/scan tuning plus per-genre
 * Category + Bouquet mapping for Movie (type 3) and TV (type 4) libraries.
 * Body-only view: the controller renders the unified admin shell around it and
 * includes settings_scripts.php afterwards. Posts to api?action=settings_plex_save.
 */

use XcVm\Domain\Stream\CategoryService;

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0">Plex Settings</h4>
</div>

<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Plex settings successfully updated!
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form id="plex-settings-form" method="POST" action="api?action=settings_plex_save" autocomplete="off">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#setup"><i class="icon-base ti tabler-id me-1"></i>Setup</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#categories"><i class="icon-base ti tabler-movie me-1"></i>Movie Categories</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#categories-tv"><i class="icon-base ti tabler-device-tv me-1"></i>TV Categories</button></li>
            </ul>

            <div class="tab-content p-4 border border-top-0 rounded-bottom">
                <!-- Setup -->
                <div class="tab-pane fade show active" id="setup">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="thread_count_movie">Movie Thread Count <i title="Number of threads to run simultaneously for movies." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="thread_count_movie" name="thread_count_movie" value="<?= htmlspecialchars($rSettings['thread_count_movie']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="thread_count_show">Series Thread Count <i title="Number of threads to run simultaneously for TV series. This should be lower as the series thread will be responsible for grabbing all episodes. So this is the number of TV series to scan at once rather than episodes. Try 1/10th of movie thread limit." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="thread_count_show" name="thread_count_show" value="<?= htmlspecialchars($rSettings['thread_count_show']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="scan_seconds">Scan Frequency <i title="Scan a library every X seconds." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="scan_seconds" name="scan_seconds" value="<?= htmlspecialchars($rSettings['scan_seconds']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="max_genres">Max Categories &amp; Bouquets <i title="Maximum number of TMDb genres to convert into categories and bouquets. Enter 0 for unlimited." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="max_genres" name="max_genres" value="<?= htmlspecialchars($rSettings['max_genres']); ?>">
                        </div>
                    </div>
                </div>

                <?php
                // Tab id + form field prefixes per library type; PlexService::editPlexSettings() reads them back.
                $rGenreTabs = array(
                    'movie' => array('categories', 'genre', 'bouquet'),
                    'series' => array('categories-tv', 'genretv', 'bouquettv'),
                );
                foreach ($rGenreTabs as $rType => list($rTab, $rGenreField, $rBouquetField)) :
                    $rCategories = CategoryService::getAllByType($rType);
                    $rRows = $rGenres[$rType];
                    ksort($rRows);
                ?>
                    <div class="tab-pane fade" id="<?= $rTab; ?>">
                        <p class="text-body-secondary">Select a Category and / or Bouquet to apply to each Genre.</p>
                        <?php foreach ($rRows as $rRow) :
                            $rGenreID = intval($rRow['genre_id']);
                            $rRowBouquets = (array) json_decode($rRow['bouquets'], true);
                        ?>
                            <div class="row mb-4">
                                <label class="col-md-2 col-form-label" for="<?= $rGenreField . '_' . $rGenreID; ?>"><?= htmlspecialchars($rRow['genre']); ?></label>
                                <div class="col-md-4">
                                    <select name="<?= $rGenreField . '_' . $rGenreID; ?>" id="<?= $rGenreField . '_' . $rGenreID; ?>" class="form-select select2">
                                        <option value="0" <?= intval($rRow['category_id']) == 0 ? 'selected' : ''; ?>>Do Not Use</option>
                                        <?php foreach ($rCategories as $rCategory) : ?>
                                            <option value="<?= intval($rCategory['id']); ?>" <?= intval($rRow['category_id']) == intval($rCategory['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($rCategory['category_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-md-2 col-form-label" for="<?= $rBouquetField . '_' . $rGenreID; ?>">Bouquets</label>
                                <div class="col-md-4">
                                    <select name="<?= $rBouquetField . '_' . $rGenreID; ?>[]" id="<?= $rBouquetField . '_' . $rGenreID; ?>" class="form-select select2" multiple="multiple" data-placeholder="Choose...">
                                        <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
                                            <option value="<?= intval($rBouquet['id']); ?>" <?= in_array(intval($rBouquet['id']), $rRowBouquets) ? 'selected' : ''; ?>><?= htmlspecialchars($rBouquet['bouquet_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-end mt-4">
                <button type="submit" name="submit_settings" id="save-settings" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
