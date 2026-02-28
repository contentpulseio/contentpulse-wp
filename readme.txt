=== ContentPulse ===
Contributors: contentpulse
Tags: content, ai, seo, publishing, automation
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-publish AI-generated content from ContentPulse to your WordPress site.

== Description ==

ContentPulse connects your WordPress site to the ContentPulse.io content generation platform. It receives AI-generated, SEO-optimized content and publishes it directly to your site.

Features:

* Automatic post creation and updates from ContentPulse
* Featured image sideloading
* SEO meta field integration (Yoast SEO, Rank Math)
* Category and tag auto-assignment
* Scheduled content support
* WordPress block editor compatibility
* Version handshake for API compatibility

== Installation ==

1. Upload the `contentpulse-wp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > ContentPulse
4. Enter your ContentPulse API URL and API Key
5. Content will now sync automatically when published in ContentPulse

== Frequently Asked Questions ==

= How do I get an API key? =

Sign up at contentpulse.io and generate an API key from your dashboard settings.

= Does this work with the block editor? =

Yes, content is delivered as WordPress block markup when supported.

== Changelog ==

= 1.0.0 =
* Initial release
* REST API endpoints for content ingestion
* Media sideloading
* SEO meta integration
* Category and tag management
