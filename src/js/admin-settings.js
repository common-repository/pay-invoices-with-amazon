jQuery( document ).ready(
	function ($) {
		/**
		 * Localization.
		 */
		let i18n = PIWASettingsI18n;

		/**
		 * Row Tooltips.
		 */
		$.each(
			i18n.row_tooltips,
			function ( class_name, tooltip ) {
				$( 'tr.' + class_name ).attr( 'data-tooltip', tooltip );
			}
		);

		/**
		 * Merchant account tabs.
		 */
		let $connection_types = $( 'input[name="piwa[merchant_account][connection_type]"]' );
		let $account_tabs     = $( 'article[id^="account-tab-"]' );

		$account_tabs.hide();
		$connection_types.each(
			function () {
				$( this ).on(
					'change',
					function () {
						let connection_type = $( this ).val();
						$account_tabs.hide().filter( '#account-tab-' + connection_type ).show();
					}
				);
				if ( $( this ).is( ':checked' ) ) {
					$( this ).trigger( 'change' );
				}
			}
		);

		let $connect_amazon_account_button = $( '#connect_amazon_account_button' );
		$connect_amazon_account_button.on(
			'click',
			function ( e ) {
				e.preventDefault();
				setInterval( poll_auto_connect_results, 2000 );
				$( '#merchant_registration' ).submit();
			}
		);

		function poll_auto_connect_results() {
			$.getJSON(
				ajaxurl,
				{
					action: 'poll-auto-connect-results'
				},
				function ( response ) {
					if ( 'nothing' !== response.result ) {
						window.location.reload();
					}
				}
			);
		}

		/**
		 * Save Settings proxy links in notices.
		 */
		$( 'a.save-proxy' ).on(
			'click',
			function ( e ) {
				e.preventDefault();
				$( '#wpbody-content > .wrap > form' ).first().submit();
			}
		);

		/**
		 * Reset Keys buttons.
		 */
		$( 'button[data-reset]' ).each(
			function () {
				$( this ).on(
					'click',
					function ( e ) {
						e.preventDefault();
						$( this ).parent().next( 'p.reset-confirm' ).show();
					}
				);
			}
		);
		$( 'button.reset-confirm' ).each(
			function () {
				$( this ).on(
					'click',
					function ( e ) {
						e.preventDefault();
						let key_to_reset = $( this ).parent().prev( 'p' ).find( 'button[data-reset]' ).attr( 'data-reset' );
						$( '#wpbody-content form' ).first().append(
							$( '<input>' )
								.attr( 'type', 'hidden' )
								.attr( 'name', 'piwa[reset-key]' )
								.attr( 'value', key_to_reset )
						).submit();
					}
				);
			}
		);

		/**
		 * Drag-and-drop a .pem file.
		 */
		let receive_dropzone = document.getElementById( 'receive-dropzone' );

		receive_dropzone.ondragover = function () {
			this.className = 'hover';
			return false;
		};
		receive_dropzone.ondragend  = function () {
			this.className = '';
			return false;
		};
		receive_dropzone.ondrop     = function (e) {
			this.className = '';
			e.preventDefault();

			let file      = e.dataTransfer.files[0],
				reader    = new FileReader();
			reader.onload = function ( event ) {
				document.getElementById( 'receive_private_key' ).value = event.target.result;
			};
			reader.readAsText( file );

			return false;
		};

		/**
		 * Currencies & languages.
		 */
		let $ledger_currency          = $( '#piwa__ledger_currency' );
		let $payment_currency         = $( '#piwa__payment_currency' );
		let $language                 = $( '#piwa__language' );
		let $both                     = $ledger_currency.add( $payment_currency );
		let $all                      = $both.add( $language );
		let $ledger_currency_options  = $ledger_currency.find( 'option' ).clone( false );
		let $payment_currency_options = $payment_currency.find( 'option' ).clone( false );
		let $language_options         = $language.find( 'option' ).clone( false );
		let $currency_symbols         = $( '.currency-symbol' );

		/**
		 * Region selector.
		 *
		 * Allowed languages, ledger currencies, and payment currencies vary by region.
		 * Safari & IE don't allow <select> options to be hidden with CSS, so this clones, filters, & adds them.
		 * 
		 * The plugin might support all regions with limited functionality,
		 * but the user interface is hidden and currently defaults to US regardless of language.
		 */
		$( '#piwa__region' ).on(
			'change',
			function () {
				let region = $( this ).val();

				switch ( region ) {
					case 'US': // United States.
						$all.find( 'option' ).remove();

						$ledger_currency.append( $ledger_currency_options.filter( '[value="USD"]' ) );
						$payment_currency.append( $payment_currency_options.filter( '[value="USD"]' ) );
						$language.append( $language_options.filter( '[value="en_US"]' ) );

						$both.val( 'USD' );
						$language.val( 'en_US' );
						$all.parents( 'table.form-table > tbody > tr' ).hide();
						update_currency_symbols();
						break;
					case 'UK': // United Kingdom.
					case 'EU': // European Union.
						$all.find( 'option' ).remove();

						$ledger_currency.append( $ledger_currency_options.filter( ':not( [value="USD"], [value="JPY"] )' ) );
						$payment_currency.append( $payment_currency_options.filter( ':not( [value="USD"], [value="JPY"] )' ) );
						$language.append( $language_options.filter( ':not( [value="en_US"], [value="ja_JP"] )' ) );

						// If the defaults from options or localization (if no options set) exist in the EU menu, select them.
						if (
							0 !== $ledger_currency.find( '[value="' + i18n.ledger_currency + '"]' ).length
							&& 0 !== $payment_currency.find( '[value="' + i18n.payment_currency + '"]' ).length
							&& 0 !== $language.find( '[value="' + i18n.language + '"]' ).length
							&& 'USD' !== i18n.ledger_currency
							&& 'JPY' !== i18n.ledger_currency
						) {
							$ledger_currency.val( i18n.ledger_currency );
							$payment_currency.val( i18n.payment_currency );
							$language.val( i18n.language );
						} else {
							// Otherwise default to British English, then EUR for EU and GBP for UK.
							if ( 'EU' === region ) {
								$both.val( 'EUR' );
							} else if ( 'UK' === region ) {
								$both.val( 'GBP' );
							}
							$language.val( 'en_GB' );
						}

						$all.parents( 'table.form-table > tbody > tr' ).show();
						update_currency_symbols();
						break;
					case 'JP': // Japan.
						$all.find( 'option' ).remove();

						$ledger_currency.append( $ledger_currency_options.filter( '[value="JPY"]' ) );
						$payment_currency.append( $payment_currency_options.filter( '[value="JPY"]' ) );
						$language.append( $language_options.filter( '[value="ja_JP"]' ) );

						$both.val( 'JPY' );
						$language.val( 'ja_JP' );
						$all.parents( 'table.form-table > tbody > tr' ).hide();
						update_currency_symbols();
						break;
				}
			}
		).val( i18n.region ).trigger( 'change' ); // If region has been set in options or defaulted from localization, use that value.

		function update_currency_symbols() {
			$currency_symbols.each(
				function () {
					$( this ).text( i18n.currency_symbols[ $payment_currency.val() ] );
				}
			);
		}
		$payment_currency.on( 'change', update_currency_symbols );

		/**
		 * Show Advanced.
		 */
		if ( ! i18n.show_block_configuration ) {
			let $advanced_options = $( 'label[for="piwa__block_type"], table.form-table tr.block_type td' );
			$( 'label[for="piwa__block_type"]' )
				.before(
					$( '<a>' )
						.text( i18n.show_block_configuration_label )
						.attr( 'href', '#' )
						.on(
							'click',
							function () {
								$( this ).hide();
								$advanced_options.show();
								return false;
							}
						)
				);
			$advanced_options.hide();
		}

		/**
		 * Min-max payment range slider.
		 */
		let min_slider       = document.querySelector( '#piwa__min_max input[name*="[min]"]' ),
			max_slider       = document.querySelector( '#piwa__min_max input[name*="[max]"]' ),
			min_proxy        = document.querySelector( '#piwa__min_max label.min-proxy input' ),
			max_proxy        = document.querySelector( '#piwa__min_max label.max-proxy input' ),
			min_value        = parseInt( min_slider.value ),
			max_value        = parseInt( max_slider.value ),
			min_max_wrapper  = document.querySelector( '#piwa__min_max' ),
			min_max_checkbox = document.querySelector( 'tr.min_max input[type="checkbox"]' );

		max_slider.oninput = function () {
			min_value = parseInt( min_slider.value );
			max_value = parseInt( max_slider.value );
			if ( max_value <= min_value ) {
				max_slider.value = min_value + 4;
				min_slider.value = max_value - 4;

				if ( parseInt( max_slider.value ) >= parseInt( max_slider.max ) ) {
					max_slider.value = max_slider.max;
				}
			}
			min_proxy.value = format_number( min_slider.value );
			max_proxy.value = format_number( max_slider.value );
		};
		min_slider.oninput = function () {
			min_value = parseInt( min_slider.value );
			max_value = parseInt( max_slider.value );
			if ( min_value >= max_value ) {
				max_slider.value = min_value + 4;
				min_slider.value = max_value - 4;

				if ( parseInt( min_slider.value ) <= parseInt( min_slider.min ) ) {
					min_slider.value = min_slider.min;
				}
				if ( parseInt( min_slider.value ) >= parseInt( min_slider.max ) ) {
					min_slider.value = min_slider.max;
				}
			}
			min_proxy.value = format_number( min_slider.value );
			max_proxy.value = format_number( max_slider.value );
		};
		max_proxy.oninput  = function () {
			max_proxy.value = normalize_input( max_proxy.value );
			if (
				'NaN' === max_proxy.value
				|| parseInt( max_proxy.value ) > parseInt( max_slider.max )
				|| parseInt( max_proxy.value ) < parseInt( max_slider.min )
			) {
				max_proxy.value = format_number( max_slider.max );
			}
			max_slider.value = normalize_input( max_proxy.value );
			max_slider.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		min_proxy.oninput  = function () {
			min_proxy.value = normalize_input( min_proxy.value );
			if (
				'NaN' === min_proxy.value
				|| parseInt( min_proxy.value ) < parseInt( min_slider.min )
				|| parseInt( min_proxy.value ) > parseInt( min_slider.max )
			) {
				min_proxy.value = format_number( min_slider.min );
			}
			min_slider.value = normalize_input( min_proxy.value );
			min_slider.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}

		min_max_checkbox.onchange = function () {
			if ( this.checked ) {
				min_max_wrapper.style.display = 'block';
			} else {
				min_max_wrapper.style.display = 'none';
			}
		}
		min_max_checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		function format_number( value ) {
			return normalize_input( value ).toLocaleString( PIWASettingsI18n.language.replace( '_', '-' ) );
		}

		// Some currencies write 1,000.00. Others write 1.000,00.
		function normalize_input( value ) {
			return parseInt(
				value
					.replace( /,(\d{2})$/, '.$1' ) // Replace ending comma followed by two numbers with period.
					.replace( /,/g, '' )
			);
		}

		/**
		 * Copy public keys to clipboard when textarea clicked or focused.
		 */
		$( '.public_key textarea' ).on(
			'click focus',
			function ( e ) {
				let $textarea = $( this );
				let value     = $textarea.val();

				if ( -1 !== value.indexOf( 'BEGIN PUBLIC KEY' ) ) {
					$textarea.get( 0 ).select();
					document.execCommand( 'copy' );

					$textarea.css( 'max-width', $textarea.outerWidth() + 'px' );
					$textarea.css( 'max-height', $textarea.outerHeight() + 'px' );
					$textarea.addClass( 'help' );
					$textarea.val( i18n.key_copied );

					setTimeout(
						function () {
							$textarea.removeClass( 'help' );
							$textarea.val( value );
						},
						15000
					);
				}

			}
		);
	}
);
