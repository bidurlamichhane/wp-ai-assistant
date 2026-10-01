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

// Send a prompt to Gemini and return the text, or a WP_Error on failure.
function wpai_gemini_generate( $prompt ) {
	if ( ! defined( 'GEMINI_API_KEY' ) || ! GEMINI_API_KEY ) {
		return new WP_Error( 'no_key', 'GEMINI_API_KEY is not set in wp-config.php.' );
	}

	$model = 'gemini-3.8-flash'; // Change this if Google retires the name.
	$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

	$response = wp_remote_post( $url, array(
		'timeout' => 60,
		'headers' => array(
			'Content-Type'   => 'application/json',
			'x-goog-api-key' => GEMINI_API_KEY,
		),
		'body'    => wp_json_encode( array(
			'contents' => array(
				array( 'parts' => array( array( 'text' => $prompt ) ) ),
			),
		) ),
	) );

	// Network-level failure (no connection, timeout).
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	// Gemini replied with an error (bad key, rate limit, wrong model name).
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