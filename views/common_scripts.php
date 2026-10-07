<script>
    // Shared by the library add/edit and Plex settings forms.
    (function($) {
        if (!$) {
            return;
        }

        // Lightweight input filter (the legacy shell helper is gone in the new UI).
        $.fn.inputFilter = function(inputFilter) {
            return this.on('input keydown keyup mousedown mouseup select contextmenu drop', function() {
                if (inputFilter(this.value)) {
                    this.oldValue = this.value;
                    this.oldSelectionStart = this.selectionStart;
                    this.oldSelectionEnd = this.selectionEnd;
                } else if (this.hasOwnProperty('oldValue')) {
                    this.value = this.oldValue;
                    this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                }
            });
        };

        // POST a form to a module api action (PlexController::reply() JSON): follow
        // `location` on success, otherwise re-enable the submit buttons and toast.
        window.plexSubmitForm = function(form, action, errorText) {
            var buttons = $(form).find(':submit').prop('disabled', true);
            var fail = function() {
                buttons.prop('disabled', false);
                if (window.xcToast) {
                    window.xcToast(errorText, 'error');
                }
            };
            fetch('./api?action=' + action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    if (d && d.result && d.location) {
                        window.location.href = d.location;
                    } else {
                        fail();
                    }
                })
                .catch(fail);
        };
    })(window.jQuery);
</script>
