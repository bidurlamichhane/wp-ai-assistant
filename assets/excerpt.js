// Read the post content, from the block editor or the classic editor.
function wpaiGetContent() {
	try {
		var editor = wp.data.select( 'core/editor' );
		if ( editor ) {
			return editor.getEditedPostContent();
		}
	} catch ( e ) {}
	var box = document.getElementById( 'content' );
	return box ? box.value : '';
}

// Put the excerpt into the editor's excerpt field.
function wpaiSetExcerpt( text ) {
	try {
		var dispatcher = wp.data.dispatch( 'core/editor' );
		if ( dispatcher ) {
			dispatcher.editPost( { excerpt: text } );
			return;
		}
	} catch ( e ) {}
	var box = document.getElementById( 'excerpt' );
	if ( box ) {
		box.value = text;
	}
}

// Listen for clicks on the button.
document.addEventListener( 'click', function ( event ) {
	if ( event.target.id !== 'wpai-generate' ) {
		return;
	}

	var button = event.target;
	var status = document.getElementById( 'wpai-status' );
	button.disabled = true;
	status.textContent = 'Thinking...';

	var body = new URLSearchParams();
	body.append( 'action', 'wpai_excerpt' );
	body.append( 'nonce', wpaiExcerpt.nonce );
	body.append( 'content', wpaiGetContent() );

	fetch( wpaiExcerpt.ajaxUrl, { method: 'POST', body: body } )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( result ) {
			if ( result.success ) {
				wpaiSetExcerpt( result.data );
				status.textContent = 'Excerpt added: ' + result.data;
			} else {
				status.textContent = 'Error: ' + result.data;
			}
		} )
		.catch( function () {
			status.textContent = 'Request failed.';
		} )
		.finally( function () {
			button.disabled = false;
		} );
} );