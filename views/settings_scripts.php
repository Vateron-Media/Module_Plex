<?php include __DIR__ . '/common_scripts.php'; ?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        $(function() {
            // Numeric-only tuning inputs.
            $('#scan_seconds, #max_genres, #thread_count_movie, #thread_count_show').inputFilter(function(value) {
                return /^\d*$/.test(value);
            });

            // Category / bouquet pickers (full-page tabs, no modal → no dropdownParent).
            if ($.fn.select2) {
                $('.select2').select2({
                    width: '100%'
                });
            }

            // Save → the module's settings_plex_save action (same JSON contract as post.php had).
            $('#plex-settings-form').on('submit', function(e) {
                e.preventDefault();
                plexSubmitForm(this, 'settings_plex_save', 'Failed to save Plex settings.');
            });
        });
    })();
</script>
</body>

</html>
