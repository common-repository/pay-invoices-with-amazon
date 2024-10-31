(function ( i18n ) {
	document.querySelectorAll( 'a.copy-shortcode' ).forEach(
		function (link) {
			link.addEventListener(
				'click',
				function () {
					let id        = link.textContent || link.innerText;
					let shortcode = '[piwa ' + parseInt( id ) + ']';

					if ( 'undefined' === typeof navigator.clipboard ) {
						clipboard_copy_fallback( shortcode );
					} else {
						navigator.clipboard.writeText( shortcode );
					}

					link.textContent = i18n.shortcode_copied_to_clipboard;
					link.classList.add( 'copied-message' );

					setTimeout(
						function () {
							link.textContent = id;
							link.classList.remove( 'copied-message' );
						},
						5000
					);
				}
			);
		}
	);

	function clipboard_copy_fallback( text ) {
		const textarea       = document.createElement( 'textarea' );
		textarea.textContent = text;
		document.body.appendChild( textarea );
		textarea.select();
		document.execCommand( 'copy' );
		document.body.removeChild( textarea );
	}
})( PIWAAdminStrings );
