/**
 * Settings page: "Test connection" button.
 *
 * Calls POST /river-conditions/v1/test. wp.apiFetch sends the REST nonce, and
 * the endpoint requires manage_options. Admin only: the front end has no JS.
 */
( function () {
	const button = document.getElementById( 'rivcon-test' );
	const notice = document.getElementById( 'rivcon-test-result' );
	if ( ! button || ! notice || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const { __ } = window.wp.i18n;

	function show( ok, message ) {
		notice.classList.remove( 'hidden', 'notice-success', 'notice-error' );
		notice.classList.add( ok ? 'notice-success' : 'notice-error' );
		notice.querySelector( 'p' ).textContent = message;
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		show( true, __( 'Testing…', 'river-conditions' ) );
		window.wp
			.apiFetch( { path: '/river-conditions/v1/test', method: 'POST' } )
			.then( function ( response ) {
				show( !! response.ok, response.message );
			} )
			.catch( function ( error ) {
				show(
					false,
					( error && error.message ) ||
						__( 'Connection test failed.', 'river-conditions' )
				);
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
} )();
