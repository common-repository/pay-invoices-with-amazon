document.addEventListener(
	'DOMContentLoaded',
	function () {
		// Public / private does not apply.
		document.getElementById( 'visibility' ).style.display = 'none';

		// There's no reason to create a payment manually if there's no interface for adding an amount and associated invoice.
		document.querySelector( 'a.page-title-action' ).style.display = 'none';

		// Draft statuses do not apply.
		let select       = document.getElementsByName( 'post_status' )[0];
		let options      = select.options;
		let option_count = options.length;

		for ( let i = 0; i < option_count; i++ ) {
			if ( options[i].value === 'draft' ) {
				select.removeChild( options[i] );
				break;
			}
		}
	}
);