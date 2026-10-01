document.addEventListener( 'click', function ( event ) {
	var button = event.target.closest ? event.target.closest( '.wpai-alt' ) : null;
	if ( ! button ) {
		return;
	}

	// Find the Alt Text field: the media popup uses data-setting="alt",
	// the full attachment edit page uses #attachment_alt.
	var scope  = button.closest( '.attachment-details' ) || document;
	var field  = scope.querySelector( '[data-setting="alt"]' ) || document.getElementById( 'attachment_alt' );
	var status = button.parentNode.querySelector( '.wpai-alt-status' );

	// Don't overwrite existing alt text without asking.
	if ( field && field.value && ! window.confirm( 'Replace the existing alt text?' ) ) {
		return;
	}

	button.disabled = true;
	status.textContent = ' Thinking...';

	var body = new URLSearchParams();
	body.append( 'action', 'wpai_alt' );
	body.append( 'nonce', wpaiAlt.nonce );
	body.append( 'id', button.dataset.id );

	fetch( wpaiAlt.ajaxUrl, { method: 'POST', body: body } )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( result ) {
			if ( result.success ) {
				if ( field ) {
					field.value = result.data;
					// Tell WordPress the field changed so the popup saves it.
					field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				status.textContent = ' Done.';
			} else {
				status.textContent = ' Error: ' + result.data;
			}
		} )
		.catch( function () {
			status.textContent = ' Request failed.';
		} )
		.finally( function () {
			button.disabled = false;
		} );
} );