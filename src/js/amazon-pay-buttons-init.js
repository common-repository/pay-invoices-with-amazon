(function () {
	payInvoicesWithAmazon.delayed_flexible_render = debounce( flexible_render, 700 );

	let invoiceNumberInputs = document.querySelectorAll( 'figure.amazon-pay-flexible input[name="invoice_number"]' )

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			// Initialize buttons which have a set amount and have already been signed.
			try {
				maybe_render_amazon_buttons();
			} catch ( error ) {
				if ( -1 !== error.indexOf( "does not match 'sandbox" ) ) {
					alert( payInvoicesWithAmazon.prod_key_in_sandbox );
				}
			}

			// Flexible invoice number input.
			invoiceNumberInputs.forEach(
				function ( invoice_number ) {
					// Respond to typing immediately with button removal and input masking.
					invoice_number.addEventListener(
						'input',
						function ( event ) {
							// Respond to typing immediately with button removal and internationalized input masking.
							// @todo find a better way to get this element
							if (this.closest( 'form' ).querySelectorAll( 'div input[name="amount"]' )[0].value !== '') {
								flexible_typing_text_field.call( this );
							}
						}
					);

				}
			);

			// Flexible price input.
			document.querySelectorAll( 'figure.amazon-pay-flexible input[name="amount"]' ).forEach(
				function ( amount_input ) {
					// Respond to typing immediately with button removal and input masking.
					amount_input.addEventListener(
						'input',
						function ( event ) {
							// checks if invoice number exists, if it does, checks if it has a value
							// Respond to typing immediately with button removal and internationalized input masking.
							if (this.closest( 'form' ).querySelectorAll( 'div input[name="invoice_number"]' ).length !== 0) {
								if (this.closest( 'form' ).querySelectorAll( 'div input[name="invoice_number"]' )[0].value !== '') {
									flexible_typing.call( this );
								}
							} else {
								flexible_typing.call( this );
							}

						}
					);

					// Allow active button to be activated by pressing enter.
					parent( amount_input, 'form' ).addEventListener(
						'submit',
						function ( event ) {
							event.preventDefault();
							let active_button = parent( this, 'div' ).querySelector( 'div:not(.amazon-pay-disabled) > .amazon-pay' );
							if ( active_button ) {
								active_button.click();
							}
						}
					);
				}
			);
		}
	);

	/**
	 * Initialize buttons which have a set amount and have already been signed.
	 * Runs on document at DOMContentLoaded or individual element in block editor render callbacks.
	 */
	window.maybe_render_amazon_buttons = function ( event_or_wrapper_element = document ) {
		event_or_wrapper_element.querySelectorAll( 'figure.amazon-pay-container div.amazon-pay[data-config]:not(.amazon-pay-initialized)' ).forEach(
			function ( button ) {
				let config                                     = JSON.parse( button.getAttribute( 'data-config' ) );
				config.createCheckoutSessionConfig.payloadJSON = atob( config.createCheckoutSessionConfig.payloadJSON );

				if ( 'true' === button.getAttribute( 'data-disabled' ) ) {
					button.classList.remove( 'amazon-pay-initialized' );
					button.parentElement.classList.add( 'amazon-pay-disabled' );
				} else {
					button.classList.add( 'amazon-pay-initialized' );
					button.parentElement.classList.remove( 'amazon-pay-disabled' );
				}

				let initialized_button = amazon.Pay.renderButton( '#' + button.getAttribute( 'id' ), config );
			}
		);
	}

	/**
	 * Respond to typing immediately with button removal and input masking.
	 */
	function flexible_typing_text_field() {
		// If a button has already rendered, disable it to prevent the possibility of clicking an old value.
		let existing_button = parent( this, 'figure' ).querySelector( '.amazon-pay' );
		if ( existing_button ) {
			disable_button( existing_button.id );
		}
		// Render button after delay, passing reference to the instantiating element.
		payInvoicesWithAmazon.delayed_flexible_render.call( this );
	}

	function flexible_typing() {
		// Mask non-numeric input.
		let new_value = this.value
			.replace( /[^0-9\.,]+/g, '' ) // Only numbers and decimals or commas, which act as decimals in some currencies.
			.replace( /(\..*)\./g, '$1' ) // Prevent duplicate dots.
			.replace( /(,.*)\,/g, '$1' ); // Prevent duplicate commas.

		if ( new_value !== this.value ) {
			// Reject invalid input.
			this.value = new_value;
		} else {
			// If a button has already rendered, disable it to prevent the possibility of clicking an old value.
			let existing_button = parent( this, 'figure' ).querySelector( '.amazon-pay' );
			if ( existing_button ) {
				disable_button( existing_button.id );
			}

			// Render button after delay, passing reference to the instantiating element.
			payInvoicesWithAmazon.delayed_flexible_render.call( this );
		}
	}

	// Some currencies write 1,000.00. Others write 1.000,00.
	function normalize_input( value ) {
		return parseFloat(
			value
				.replace( /,(\d{2})$/, '.$1' ) // Replace ending comma followed by two numbers with period.
				.replace( /,/g, '' )
		);
	}

	// Delayed render of flexible price input.
	function flexible_render() {
		let value_as_number = normalize_input( this.value );
		let number_too_low  = (
			'' !== this.getAttribute( 'min' )
			&& value_as_number < normalize_input( this.getAttribute( 'min' ) )
		);
		let number_too_high = (
			'' !== this.getAttribute( 'max' )
			&& value_as_number > normalize_input( this.getAttribute( 'max' ) )
		);

		if (
			'yes' === this.getAttribute( 'data-display-feedback' )
			&& ( number_too_low || number_too_high )
		) {
			let existing_button = parent( this, 'figure' ).querySelector( '.amazon-pay' );
			if ( existing_button ) {
				disable_button( existing_button.id );
			}

			if ( number_too_low ) {
				append_feedback(
					this,
					payInvoicesWithAmazon.enter_above_amount
						.replace( /@currency_symbol@/g, payInvoicesWithAmazon.currency_symbol )
						.replace( '@min_amount@', this.getAttribute( 'min' ).replace( '.00', '' ) )
				);
			}
			if ( number_too_high ) {
				append_feedback(
					this,
					payInvoicesWithAmazon.enter_below_amount
						.replace( /@currency_symbol@/g, payInvoicesWithAmazon.currency_symbol )
						.replace( '@max_amount@', this.getAttribute( 'max' ).replace( '.00', '' ) )
				);
			}

			return;
		}

		if ( 1 > parseInt( this.value.length ) ) {
			return;
		}

		remove_feedback( this );

		fetch(
			payInvoicesWithAmazon.sign_url,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json'
				},
				body: serialize_parent_form( this )
			}
		)
		.then( response => response.json() )
		.then(
			function ( data ) {
				// Append and render.
				enable_button( this, data );
			}.bind( this )
		)
		.catch(
			function ( error ) {
				append_feedback( this, payInvoicesWithAmazon.api_failure );
				disable_button( parent( this, 'figure' ).querySelector( '.amazon-pay' ).id );
				console.error( 'Could not sign pay button', error, this );
			}.bind( this )
		);

	}

	function debounce( func, wait = 700 ) {
		let timeout;

		return function ( ...args ) {
			const later = () => {
				clearTimeout( timeout );
				func.apply( this, args );
			};

			clearTimeout( timeout );
			timeout = setTimeout( later, wait );
		};
	}

	function parent( el, selector ) {
		while ( ( el = el.parentNode ) && el !== document ) {
			if ( ! selector || el.matches( selector ) ) {
				return el;
			}
		}
		return null;
	}

	function serialize_parent_form( input_element ) {
		const form = parent( input_element, 'form' );
		let data   = {};

		if ( form ) {
			form.querySelectorAll( 'input' ).forEach(
				function ( input ) {
					if ( 'amount' === input.name ) {
						data[ input.name ] = normalize_input( input.value );
					} else if ( input.name ) {
							data[ input.name ] = input.value;
					}
				}
			);
		}

		return JSON.stringify( data );
	}

	function append_feedback( element, message ) {
		let figure   = parent( element, 'figure' );
		let form     = parent( element, 'form' );
		let feedback = figure.querySelector( '.amazon-pay-feedback' );

		if ( ! feedback ) {
			feedback           = document.createElement( 'figcaption' );
			feedback.className = 'amazon-pay-feedback';
			form.insertAdjacentElement( 'afterend', feedback );
		}

		feedback.textContent = message;
	}

	function remove_feedback( element ) {
		let figure   = parent( element, 'figure' );
		let feedback = figure.querySelector( '.amazon-pay-feedback' );
		if ( feedback ) {
			feedback.parentNode.removeChild( feedback );
		}
	}

	function disable_button( id ) {
		let button = document.getElementById( id );
		if ( button ) {
			button.classList.remove( 'amazon-pay-initialized' );
			button.setAttribute( 'data-disabled', 'true' );
			button.parentElement.classList.add( 'amazon-pay-disabled' );
		}
	}

	function enable_button( input_element, data ) {
		// New button.
		let button           = parent( input_element, 'figure' ).querySelector( '.amazon-pay' );
		let new_button       = document.createElement( 'div' );
		new_button.id        = data.id;
		new_button.className = 'amazon-pay';
		new_button.setAttribute( 'data-config', JSON.stringify( data.config ) );

		if ( 'true' === data.disabled ) {
			button.setAttribute( 'data-disabled', 'true' );
			button.parentElement.classList.add( 'amazon-pay-disabled' );
		} else {
			new_button.setAttribute( 'data-disabled', 'false' );
			button.replaceWith( new_button );
			button = new_button;
		}

		// Render from context of parent figure.
		maybe_render_amazon_buttons( parent( button, 'figure' ) );
	}

})();
