document.addEventListener(
	'DOMContentLoaded',
	function () {
		let incorrect_current = document.querySelector( '#adminmenu > li.wp-not-current-submenu li.current' );
		let found             = false;

		if ( incorrect_current ) {
			el = incorrect_current;
			while ( ( el = el.parentNode ) && ! found && el !== document ) {
				if ( el.matches( 'li.wp-not-current-submenu' ) ) {
					el.classList.remove( 'wp-not-current-submenu' );
					el.classList.add( 'wp-has-current-submenu' );
					el.classList.add( 'wp-menu-open' );
					found = true;
				}
			}
		}
	}
);
