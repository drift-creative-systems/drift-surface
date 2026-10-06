/* global jQuery, wp, EncoreWebsite */
/**
 * Encore Website admin screen: media pickers and colour pickers (White Label), copy /
 * reveal buttons (Connection) and the Setup Wizard.
 */
(function ($) {
	'use strict';

	var i18n = (window.EncoreWebsite && EncoreWebsite.i18n) || {};

	function sprintf2(str, a, b) {
		return String(str).replace('%1$d', a).replace('%2$d', b);
	}

	/* ── Colour pickers ─────────────────────────────────────────────── */
	$(function () {
		if ($.fn.wpColorPicker) {
			$('.ew-colour-field').wpColorPicker();
		}
	});

	/* ── Media fields ───────────────────────────────────────────────── */
	$(document).on('click', '.ew-media-field__select', function (e) {
		e.preventDefault();
		var $field = $(this).closest('.ew-media-field');
		var frame = wp.media({
			title: i18n.selectImage || 'Select image',
			button: { text: i18n.useImage || 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var file = frame.state().get('selection').first().toJSON();
			var url = file.url;
			$field.find('input[type="hidden"]').val(url);
			$field.find('.ew-media-field__preview').html($('<img>').attr({ src: url, alt: '' })).show();
			$field.find('.ew-media-field__remove').show();
			$field.find('.ew-media-field__select').text(i18n.change || 'Change image');
		});
		frame.open();
	});

	$(document).on('click', '.ew-media-field__remove', function (e) {
		e.preventDefault();
		var $field = $(this).closest('.ew-media-field');
		$field.find('input[type="hidden"]').val('');
		$field.find('.ew-media-field__preview').empty().hide();
		$(this).hide();
	});

	/* ── Copy / reveal ──────────────────────────────────────────────── */
	$(document).on('click', '.ew-copy__btn', function () {
		var $btn = $(this);
		var text = String($btn.data('copy') || '');
		var done = function () {
			var label = $btn.text();
			$btn.text(i18n.copied || 'Copied');
			setTimeout(function () { $btn.text(label); }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var $tmp = $('<textarea readonly>').val(text).css({ position: 'absolute', left: '-9999px' }).appendTo('body');
			$tmp[0].select();
			document.execCommand('copy');
			$tmp.remove();
			done();
		}
	});

	$(document).on('click', '.ew-reveal', function () {
		var $code = $(this).siblings('.ew-secret');
		var shown = $code.data('shown');
		$code.text(shown ? '••••••••••••••••' : $code.data('secret'));
		$code.data('shown', !shown);
	});

	/* ── Setup Wizard ───────────────────────────────────────────────── */
	$(function () {
		var $wizard = $('.ew-wizard');
		if (!$wizard.length) {
			return;
		}

		var total = parseInt($wizard.data('total'), 10) || 0;
		var $log = $wizard.find('.ew-wizard__log');
		var $btn = $wizard.find('.ew-create');

		function boxes() {
			return $wizard.find('.ew-page input[type="checkbox"]:not(:disabled)');
		}

		function refreshProgress() {
			var done = $wizard.find('.ew-page.is-existing').length;
			$wizard.find('.ew-progress span').css('width', (total ? Math.round(done / total * 100) : 0) + '%');
			$wizard.find('.ew-progress__label').text(sprintf2(i18n.progress || '%1$d of %2$d pages created', done, total));
			$btn.prop('disabled', !boxes().filter(':checked').length);
		}

		// Ticking a child page ticks its parent; unticking a parent unticks its children.
		$wizard.on('change', '.ew-page input[type="checkbox"]', function () {
			var $item = $(this).closest('.ew-page');
			var parent = $item.data('parent');
			if (this.checked && parent) {
				$wizard.find('.ew-page[data-id="' + parent + '"] input:not(:disabled)').prop('checked', true);
			}
			if (!this.checked) {
				$wizard.find('.ew-page[data-parent="' + $item.data('id') + '"] input:not(:disabled)').prop('checked', false);
			}
			refreshProgress();
		});

		$wizard.on('click', '[data-select]', function () {
			var mode = $(this).data('select');
			boxes().each(function () {
				var required = $(this).closest('.ew-page').data('required') === 1;
				this.checked = mode === 'all' || (mode === 'required' && required);
			});
			refreshProgress();
		});

		$btn.on('click', function () {
			var queue = boxes().filter(':checked').map(function () { return this.value; }).get();
			if (!queue.length) {
				return;
			}
			$btn.prop('disabled', true).text(i18n.creating || 'Creating…');
			$log.empty().prop('hidden', false);

			(function next() {
				if (!queue.length) {
					$btn.text($btn.data('label') || 'Create selected pages');
					refreshProgress();
					return;
				}
				var id = queue.shift();
				var $item = $wizard.find('.ew-page[data-id="' + id + '"]');

				$.post(EncoreWebsite.ajaxUrl, { action: 'encore_setup_create_page', page_id: id, nonce: EncoreWebsite.nonce })
					.done(function (res) {
						var data = (res && res.data) || {};
						var $li = $('<li>').text(data.message || '');
						if (res && res.success) {
							$item.addClass('is-existing').find('input').prop('disabled', true).prop('checked', true);
							if (data.edit_url) {
								$item.find('.ew-page__status').html(
									$('<a>').attr('href', data.edit_url).text(i18n.edit || 'Edit')
								).append(' ').append(
									$('<a>').attr({ href: data.view_url, target: '_blank', rel: 'noopener' }).text(i18n.view || 'View')
								);
							}
						} else {
							$li.addClass('is-error');
						}
						$log.append($li);
					})
					.fail(function () {
						$log.append($('<li class="is-error">').text(id + ': ' + (i18n.failed || 'Request failed.')));
					})
					.always(next);
			})();
		});

		$btn.data('label', $btn.text());
		refreshProgress();
	});
})(jQuery);
