<?php
/**
 * Plugin Name: WP AI Assistant
 * Description: AI integration for WordPress.
 * Version: 0.1.0
 */

// Stop direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add a menu item to the dashboard sidebar.
add_action( 'admin_menu', function () {
	add_menu_page(
		'WP AI Assistant',       // Page title
		'AI Assistant',          // Menu label
		'manage_options',        // Who can see it (admins)
		'wp-ai-assistant',       // Menu slug
		'wpai_render_page',      // Function that draws the page
		'dashicons-superhero',   // Icon
		80                       // Position in the menu
	);
} );

// Draw the page.
function wpai_render_page() {
	echo '<div class="wrap"><h1>WP AI Assistant</h1><p>It works.</p></div>';
}