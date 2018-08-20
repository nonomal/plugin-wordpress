jQuery(document).ready(function ($) {
	/**
	 * jsDelivr CDN Admin Helper
	 * @type {*|Window.jsdelivrcdnHelper}
	 */
	window.jsdelivrcdnHelper = {
		$form: '',
		$table: '',
		$tableBody: '',

		/**
		 * Init
		 * @returns {Window.jsdelivrcdnHelper}
		 */
		init: function() {
			var _self = this;

			_self
				.initVars()
				.initButtons()
				.loadTableData();

			return _self;
		},

		/**
		 * Init vars
		 * @returns {Window.jsdelivrcdnHelper}
		 */
		initVars: function() {
			var _self = this;

			_self.$form = $('#jsdelivrcdn_settings_form');
			_self.$table = $('#jsdelivrcdn-settings-table');
			_self.$tableBody = _self.$table.find('tbody');

			return _self;
		},

		/**
		 * Init Buttons
		 * @returns {Window.jsdelivrcdnHelper}
		 */
		initButtons: function() {
			var _self = this;

			$('#select_all').on('click', function(e){
				e.stopPropagation();
				e.preventDefault();

				$('input[name*=jsdelivrcdn_settings]').prop('checked', true);
			});

			$('#unselect_all').on('click', function(e){
				e.stopPropagation();
				e.preventDefault();

				$('input[name*=jsdelivrcdn_settings]').prop('checked', false);
			});

			$('#clear_source_list').on('click', function(e){
				e.stopPropagation();
				e.preventDefault();

				var $dashicons = $(this).find('.dashicons');
				$dashicons.removeClass('hidden');

				if(confirm('Are you sure to clear all jsdelivr stored urls?')){
					$.post(ajaxurl, {
						'action': 'clear_source_list',
						'security': _self.$form.data('ajaxnonce')
					}, function(response) {
						if(response.result === 'OK') {
							_self.$tableBody.find('td.jsdelivr_url').text('');
						}
						$dashicons.addClass('hidden');
					}, 'json');
				}
			});

			$('#delete_source_list').on('click', function(e){
				e.stopPropagation();
				e.preventDefault();

				var $dashicons = $(this).find('.dashicons');
				$dashicons.removeClass('hidden');

				if(confirm('Are you sure to remove all stored urls?')){
					$.post(ajaxurl, {
						'action': 'delete_source_list',
						'security': _self.$form.data('ajaxnonce')
					}, function(response) {
						if(response.result === 'OK') {
							_self.$tableBody.empty();
						}
						$dashicons.addClass('hidden');
					}, 'json');
				}
			});

			//Clear jsdelivr url from row
			$(document).on('click', '.clear_source', function(e){
				e.stopPropagation();
				e.preventDefault();

				var $dashicons = $(this).find('.dashicons');
				$dashicons.removeClass('hidden');

				var $tr = $(this).closest('tr');
				var handle = $tr.prop('id');

				if(confirm('Are you sure to remove all stored urls?')){
					$.post(ajaxurl, {
						'action': 'clear_source',
						'security': _self.$form.data('ajaxnonce'),
						'handle': handle
					}, function(response) {
						if(response.result === 'OK') {
							$tr.find('td.jsdelivr_url').text('');
						}
						$dashicons.addClass('hidden')
					}, 'json');
				}
			});

			//Analyze data and load jsdelivr urls
			$('#jsdelivr_analyze'). on('click', function(e) {
				e.stopPropagation();
				e.preventDefault();

				var $dashicons = $(this).find('.dashicons');
				$dashicons.removeClass('hidden');

				$.post(ajaxurl, {
					'action': 'jsdelivr_analyze',
					'security': _self.$form.data('ajaxnonce')
				}, function(response) {
					if(response.result === 'OK') {
						$.each(response.data, function(index, url){
							_self.$tableBody.find('tr#'+index).find('td.jsdelivr_url').text(url);
						});
					}
					$dashicons.addClass('hidden');
				}, 'json');
			});

			$('#reload').on('click', function(e){
				e.stopPropagation();
				e.preventDefault();
				var $dashicons = $(this).find('.dashicons');
				$dashicons.removeClass('hidden');

				_self.loadTableData(function(){
					$dashicons.addClass('hidden');
				});
			});

			$('#advanced_mode').on('change', function(){
				$.post(ajaxurl, {
					'action': 'advanced_mode_switch',
					'advanced_mode': $(this).is(':checked'),
					'security': _self.$form.data('ajaxnonce')
				}, function(response) {
					if(response.result === 'OK') {
						location.reload();
					}
				}, 'json');
			});

			return _self;
		},

		/**
		 * load table
		 * @returns {Window.jsdelivrcdnHelper}
		 */
		loadTableData: function(callback) {
			var _self = this;
			_self.$tableBody.empty();

			var $tr, $td;

			$.post(ajaxurl, {
				'action': 'get_source_list',
				'security': _self.$form.data('ajaxnonce')
			}, function(response) {
				if(response.result === 'OK') {
					var i = 0;
					$.each(response.data, function(index, data){
						i++;
						$tr = $('<tr></tr>')
							.attr('id', index)
							.appendTo(_self.$tableBody);

						//Number
						$td = $('<td></td>')
							.addClass('check-column text-center')
							.append(i)
							.appendTo($tr);

						//Active
						$td = $('<td></td>')
							.addClass('check-column text-center')
							.append('<input name="jsdelivrcdn_settings[source_list]['+index+'][active]" type="checkbox" title="Active" value="1" '+(data['active'] ? 'checked': '')+'>')
							.appendTo($tr);

						if(typeof data['handle'] !== 'undefined') {
							//Name
							$td = $('<td></td>')
								.text(data['handle'])
								.appendTo($tr);
						}

						if(typeof data['ver'] !== 'undefined') {
							//Version
							$td = $('<td></td>')
								.text(data['ver'] ? data['ver']:'')
								.appendTo($tr);
						}

						//Origin
						$td = $('<td></td>')
							.text(data['original_url'])
							.appendTo($tr);

						//jsDelivr
						$td = $('<td></td>')
							.addClass('jsdelivr_url')
							.text(data['jsdelivr_url'])
							.appendTo($tr);

						if(typeof data['ver'] !== 'undefined') {
							$td = $('<td></td>')
								.append('<button class="button button-primary clear_source" ><span class="dashicons dashicons-update spin hidden"></span> Clear</button>')
								.appendTo($tr);
						}

					})
				}
				if(typeof callback === 'function') {
					callback();
				}
			}, 'json');

			return _self;
		}

	}.init();
});
