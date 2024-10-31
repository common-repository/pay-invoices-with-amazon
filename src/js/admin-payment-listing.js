document.addEventListener(
	'DOMContentLoaded',
	function () {
		let i18n = PIWAPaymentListingI18n;

		// There's no reason to create a payment manually if there's no interface for adding an amount and associated invoice.
		document.querySelector( 'a.page-title-action' ).style.display = 'none';

		// If no payments are trashed, only one item "All" will display, but it will still have "|" at the end.
		if ( ! document.querySelector( '.subsubsub li.trash' ) ) {
			let all_filter       = document.querySelector( '.subsubsub li.all' );
			all_filter.innerHTML = all_filter.innerHTML.replace( '|', '' );
		}

		// Post State doesn't seem translatable via gettext.
		document.querySelectorAll( 'span.post-state' ).forEach(
			function ( span ) {
				if ( 'Pending' === span.textContent ) {
						span.textContent = i18n.payment_failed;
				}
			}
		);

		let captureNoticeWrapper       = document.createElement( 'div' );
		captureNoticeWrapper.className = 'notice notice-success';

		let captureNoticeContent         = document.createElement( 'p' );
		captureNoticeContent.textContent = i18n.capture_notice;

		captureNoticeWrapper.appendChild( captureNoticeContent );
		document.querySelector( 'h1.wp-heading-inline' ).after( captureNoticeWrapper );

		// Change "Edit" links in row actions to "View", as Publish / Save functionality has been hidden.
		document.querySelectorAll( '.row-actions .edit a' ).forEach(
			function ( link ) {
				link.textContent = 'View';
				link.setAttribute(
					'aria-label',
					link.getAttribute( 'aria-label' ).replace( 'Edit', 'View' )
				);
			}
		);
	}
);
