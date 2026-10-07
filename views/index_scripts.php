<script id="scripts">
	(function() {
		var toast = window.xcToast || function() {};
		var confirmDialog = window.xcConfirm || function(msg) {
			return Promise.resolve(confirm(msg));
		};

		var confirmed = function(question, run) {
			confirmDialog(question).then(function(ok) {
				if (ok) {
					run();
				}
			});
		};
		// Topbar buttons (PlexModule::registerTopbar): confirm, call the api action, toast.
		var bulkAction = function(question, action, done) {
			return function() {
				confirmed(question, function() {
					$.getJSON('./api?action=' + action, function() {
						toast(done);
					});
				});
			};
		};
		window.disableAll = bulkAction('Are you sure you want to disable all libraries?', 'disable_plex', 'Libraries have been disabled.');
		window.enableAll = bulkAction('Are you sure you want to enable all libraries?', 'enable_plex', 'Libraries have been enabled.');
		window.killPlexSync = bulkAction('Are you sure you want to kill all processes?', 'kill_plex', 'Plex Sync processes have been killed.');

		var questions = {
			delete: 'Are you sure you want to delete this library?',
			force: 'Are you sure you want to force this library to run now?'
		};
		var done = {
			delete: 'Library successfully deleted.',
			force: 'Library has been forced to sync in the background.'
		};
		window.api = function(rID, rType) {
			var fail = function() {
				toast('An error occured while processing your request.', 'error');
			};
			var run = function() {
				$.getJSON('./api?action=library&sub=' + rType + '&folder_id=' + rID, function(data) {
					if (data.result !== true) {
						return fail();
					}
					if (rType === 'delete') {
						$('#datatable').DataTable().row('#folder-' + rID).remove().draw(false);
					}
					if (done[rType]) {
						toast(done[rType]);
					}
				}).fail(fail);
			};
			if (questions[rType]) {
				confirmed(questions[rType], run);
			} else {
				run();
			}
		};

		$(function() {
			$('#datatable').DataTable({
				order: [
					[5, 'desc']
				],
				columnDefs: [{
					visible: false,
					targets: [0]
				}],
				layout: {
					topStart: 'pageLength',
					topEnd: 'search'
				}
			});
		});
	})();
</script>
</body>

</html>
