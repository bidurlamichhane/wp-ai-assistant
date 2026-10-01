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