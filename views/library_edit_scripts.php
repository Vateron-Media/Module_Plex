<?php include __DIR__ . '/common_scripts.php'; ?>
<script>
    (function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }

        function evaluateDirectSource() {
            var disabled = $("#direct_proxy").is(":checked");
            ["read_native", "movie_symlink", "auto_encode", "auto_upgrade", "remove_subtitles", "target_container", "transcode_profile_id"].forEach(function (rElement) {
                var el = $("#" + rElement);
                el.prop("disabled", disabled);
                if (el.hasClass("select2-hidden-accessible")) {
                    el.trigger("change.select2");
                }
            });
        }

        $(function () {
            // Each control lives inside a .tab-pane, so anchor the dropdown there.
            $("select").each(function () {
                var opts = { width: "100%" };
                var pane = $(this).closest(".tab-pane");
                if (pane.length) {
                    opts.dropdownParent = pane;
                }
                $(this).select2(opts);
            });

            $("#scanPlex").click(function () {
                if (($("#plex_ip").val().length > 0) && ($("#plex_port").val().length > 0) && ($("#username").val().length > 0) && ($("#password").val().length > 0)) {
                    $("#library_id").empty().trigger("change");
                    $.getJSON("./api?action=plex_sections&ip=" + encodeURIComponent($("#plex_ip").val()) + "&port=" + encodeURIComponent($("#plex_port").val()) + "&username=" + encodeURIComponent($("#username").val()) + "&password=" + encodeURIComponent($("#password").val()), function (data) {
                        var rLibraries = [];
                        if (data.result == true) {
                            for (var i in data.data) {
                                rLibraries.push({
                                    "key": data.data[i]["@attributes"]["key"],
                                    "title": data.data[i]["@attributes"]["title"]
                                });
                                $("#library_id").append(new Option(data.data[i]["@attributes"]["title"], data.data[i]["@attributes"]["key"])).trigger('change');
                            }
                            window.xcToast("Libraries have been scanned and added to the list.");
                        } else {
                            window.xcToast("Failed to get libraries! Check your server credentials.", "error");
                        }
                        $("#libraries").val(JSON.stringify(rLibraries));
                    });
                } else {
                    window.xcToast("Please fill in all Plex server information and credentials.", "warning");
                }
            });
            $("#direct_proxy").change(function () {
                evaluateDirectSource();
            });
            evaluateDirectSource();
            $("#library-form").submit(function (e) {
                e.preventDefault();
                // New-UI submit: POST to the module's plex_library_save action (PlexService::processPlexSync).
                plexSubmitForm(this, 'plex_library_save', 'An error occurred while processing your request.');
            });
            $("#plex_port").inputFilter(function (value) {
                return /^\d*$/.test(value);
            });
        });
    })();
</script>
</body>

</html>
