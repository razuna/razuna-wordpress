/**
 * Razuna settings page behavior.
 */
( function ( document ) {
	'use strict';

	var region = document.getElementById( 'razuna-region' );

	function toggleCustom() {
		var show = region && 'custom' === region.value;

		document.querySelectorAll( '.razuna-custom-row' ).forEach( function ( row ) {
			row.style.display = show ? '' : 'none';
		} );
	}

	if ( region ) {
		region.addEventListener( 'change', toggleCustom );
		toggleCustom();
	}
} )( document );
