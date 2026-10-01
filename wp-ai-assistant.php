<?php
/**
 * Plugin Name: WP AI Assistant
 * Description: AI integration for WordPress.
 * Version: 0.2.0
 */

// Stop direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add a menu item to the dashboard sidebar.
add_action( 'admin_menu', function () {
	add_menu_page(
		'WP AI Assistant',
		'AI Assistant',
		'manage_options',
		'wp-ai-assistant',
		'wpai_render_page',
		'dashicons-superhero',
		80
	);
} );

// Draw the page and handle the form.
function wpai_render_page() {
	// Only admins may use this page.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$prompt = '';
	$result = null;

	// Did the user submit the form?
	if ( isset( $_POST['wpai_prompt'] ) ) {
		// Security check: confirms the form really came from this page.
		check_admin_referer( 'wpai_send', 'wpai_nonce' );

		// Clean the input.
		$prompt = sanitize_textarea_field( wp_unslash( $_POST['wpai_prompt'] ) );

		if ( '' !== $prompt ) {
			$result = wpai_gemini_generate( $prompt );
		}
	}
	?>
	<div class="wrap">
		<h1>WP AI Assistant</h1>

		<form method="post">
			<?php wp_nonce_field( 'wpai_send', 'wpai_nonce' ); ?>
			<p>
				<textarea name="wpai_prompt" rows="5" class="large-text"
					placeholder="Ask something..."><?php echo esc_textarea( $prompt ); ?></textarea>
			</p>
			<?php submit_button( 'Send' ); ?>
		</form>

		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php elseif ( is_string( $result ) ) : ?>
			<h2>Response</h2>
			<div style="background:#fff;padding:1em;border:1px solid #ccd0d4;max-width:800px;">
				<?php echo wp_kses_post( wpautop( esc_html( $result ) ) ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

// Send a prompt (and optionally an image) to Gemini. Returns text or a WP_Error.
function wpai_gemini_generate( $prompt, $image = null ) {
	if ( ! defined( 'GEMINI_API_KEY' ) || ! GEMINI_API_KEY ) {
		return new WP_Error( 'no_key', 'GEMINI_API_KEY is not set in wp-config.php.' );
	}

	$model = 'gemini-3.8-flash'; // Change this if Google retires the name.
	$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

	// Build the message: an optional image first, then the text instructions.
	$parts = array();
	if ( $image ) {
		$parts[] = array(
			'inline_data' => array(
				'mime_type' => $image['mime'],
				'data'      => $image['data'], // base64-encoded image
			),
		);
	}
	$parts[] = array( 'text' => $prompt );

	$response = wp_remote_post( $url, array(
		'timeout' => 60,
		'headers' => array(
			'Content-Type'   => 'application/json',
			'x-goog-api-key' => GEMINI_API_KEY,
		),
		'body'    => wp_json_encode( array(
			'contents' => array( array( 'parts' => $parts ) ),
		) ),
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$message = $data['error']['message'] ?? 'Unexpected response (HTTP ' . $code . ').';
		return new WP_Error( 'gemini_error', $message );
	}

	$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
	if ( '' === $text ) {
		return new WP_Error( 'empty', 'Gemini returned no text.' );
	}

	return $text;
}

// 1. Add the "AI Excerpt" box to the post editor.
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'wpai_excerpt', 'AI Excerpt', 'wpai_excerpt_box', 'post', 'side' );
} );

function wpai_excerpt_box() {
	echo '<p><button type="button" class="button" id="wpai-generate">Generate excerpt</button></p>';
	echo '<p id="wpai-status" style="margin:0;"></p>';
}

// 2. Load the JavaScript, only on the post editor screens.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_script(
		'wpai-excerpt',
		plugin_dir_url( __FILE__ ) . 'assets/excerpt.js',
		array( 'wp-data' ),
		'0.3.0',
		true
	);

	// Hand the JavaScript the AJAX address and a security token.
	wp_localize_script( 'wpai-excerpt', 'wpaiExcerpt', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'wpai_excerpt' ),
	) );
} );

// 3. Handle the request from the button.
add_action( 'wp_ajax_wpai_excerpt', 'wpai_ajax_excerpt' );

function wpai_ajax_excerpt() {
	check_ajax_referer( 'wpai_excerpt', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'You are not allowed to do that.', 403 );
	}

	// Strip HTML and shortcodes, then limit the length sent to the AI.
	$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
	$content = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $content ) ), 800, '' );

	if ( '' === trim( $content ) ) {
		wp_send_json_error( 'Write some content first.' );
	}

	$prompt = "Write a concise excerpt for the blog post below: one or two sentences, "
		. "under 155 characters, plain text, no quotation marks. "
		. "Return only the excerpt.\n\nPOST:\n" . $content;

	$result = wpai_gemini_generate( $prompt );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( sanitize_textarea_field( trim( $result ) ) );
}


// 1. Add a "Generate alt text" button to image attachments.
add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	if ( ! wp_attachment_is_image( $post->ID ) ) {
		return $fields;
	}

	$fields['wpai_alt'] = array(
		'label' => 'AI alt text',
		'input' => 'html',
		'html'  => '<button type="button" class="button wpai-alt" data-id="' . esc_attr( $post->ID ) . '">Generate alt text</button>'
			. '<span class="wpai-alt-status"></span>',
	);

	return $fields;
}, 10, 2 );

// 2. Load the script on the media library and editor screens
//    (the media popup can open on any of them).
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_script( 'wpai-alt', plugin_dir_url( __FILE__ ) . 'assets/alt.js', array(), '0.4.0', true );

	wp_localize_script( 'wpai-alt', 'wpaiAlt', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'wpai_alt' ),
	) );
} );

// 3. Handle the request: read the image, ask Gemini, save the alt text.
add_action( 'wp_ajax_wpai_alt', 'wpai_ajax_alt' );

function wpai_ajax_alt() {
	check_ajax_referer( 'wpai_alt', 'nonce' );

	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

	if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( 'You are not allowed to do that.', 403 );
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		wp_send_json_error( 'That file is not an image.' );
	}

	// Prefer the "large" size: it's smaller and plenty for describing a photo.
	$file  = get_attached_file( $id );
	$large = image_get_intermediate_size( $id, 'large' );
	if ( $large && ! empty( $large['path'] ) ) {
		$candidate = path_join( wp_get_upload_dir()['basedir'], $large['path'] );
		if ( file_exists( $candidate ) ) {
			$file = $candidate;
		}
	}

	if ( ! $file || ! file_exists( $file ) ) {
		wp_send_json_error( 'Image file not found.' );
	}
	if ( filesize( $file ) > 4 * MB_IN_BYTES ) {
		wp_send_json_error( 'Image is too large (over 4 MB).' );
	}

	$mime = wp_check_filetype( $file )['type'];
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
		wp_send_json_error( 'Unsupported image type.' );
	}

	$image = array(
		'mime' => $mime,
		'data' => base64_encode( file_get_contents( $file ) ),
	);

	$prompt = 'Write alt text for this image for a website. One short sentence, '
		. 'under 125 characters. Describe what is visible. Do not start with '
		. '"image of" or "picture of". Plain text only. Return only the alt text.';

	$result = wpai_gemini_generate( $prompt, $image );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$alt = sanitize_text_field( trim( $result ) );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );

	wp_send_json_success( $alt );
}