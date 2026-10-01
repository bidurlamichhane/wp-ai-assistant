# WP AI Assistant

A WordPress plugin that adds AI features to the editor and Media Library, powered by Google's Gemini API.

## Features

- **Excerpt generator**: one click in the post editor writes a concise excerpt from your post content.
- **Image alt text**: generate accessible alt text for any image in the Media Library.
- **Settings page**: choose the model and customize both prompts from the dashboard.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Gemini API key (the free tier from Google AI Studio works)

## Setup

1. Copy this folder to `wp-content/plugins/wp-ai-assistant` and activate it.
2. Add your key to `wp-config.php`:

```php
   define( 'GEMINI_API_KEY', 'your-key-here' );
```

3. Open **AI Assistant → Settings** to confirm the key is detected.

## How it works

Each feature sends a request from the browser to WordPress's AJAX endpoint. A PHP handler checks permissions and a security nonce, calls the Gemini API with `wp_remote_post()`, and returns the result. API calls go through a single function, so the provider can be swapped without touching the features.

## Security notes

- The API key lives in `wp-config.php`, never in the plugin code or database.
- Every request is nonce-checked and capability-checked.
- Input is sanitized and output is escaped.

## Privacy

Content (post text and images) is sent to Google's Gemini API. Free-tier data may be used by Google to improve its products, so avoid using private or client content on the free tier.

## License

GPL-2.0-or-later