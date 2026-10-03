/**
 * Admin JavaScript for WooCommerce Core Pages Auto-Recovery.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Toggle manual assignment form row.
		$('.wpcalibrate-wcpr-assign-toggle').on('click', function(e) {
			e.preventDefault();
			var role = $(this).data('role');
			var $targetRow = $('#wpcalibrate-wcpr-assign-row-' + role);

			var isExpanded = $(this).attr('aria-expanded') === 'true';
			$(this).attr('aria-expanded', !isExpanded);

			$targetRow.toggle();

			if (!isExpanded) {
				$targetRow.find('select').focus();
			}
		});

		// Cancel manual assignment.
		$('.wpcalibrate-wcpr-assign-cancel').on('click', function(e) {
			e.preventDefault();
			var role = $(this).data('role');
			var $toggleBtn = $('.wpcalibrate-wcpr-assign-toggle[data-role="' + role + '"]');
			var $targetRow = $('#wpcalibrate-wcpr-assign-row-' + role);

			$targetRow.hide();
			$toggleBtn.attr('aria-expanded', 'false').focus();
		});

		// Confirmation on Repair All.
		$('.wpcalibrate-wcpr-repair-all-form').on('submit', function(e) {
			var msg = (window.wpcalibrateWcprData && window.wpcalibrateWcprData.confirmRepairAll)
				? window.wpcalibrateWcprData.confirmRepairAll
				: 'Are you sure you want to run automatic repair on missing core pages?';

			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});

		// Confirmation on Clear History.
		$('.wpcalibrate-wcpr-clear-history-form').on('submit', function(e) {
			var msg = (window.wpcalibrateWcprData && window.wpcalibrateWcprData.confirmClearHistory)
				? window.wpcalibrateWcprData.confirmClearHistory
				: 'Are you sure you want to clear the entire recovery history log?';

			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});
	});
})(jQuery);
