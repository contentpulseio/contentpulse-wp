# ContentPulse WordPress Plugin

[![CI](https://github.com/contentpulseio/contentpulse-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/contentpulseio/contentpulse-wp/actions/workflows/ci.yml)

Auto-publish AI-generated content from ContentPulse to your WordPress site.

## Requirements

- WordPress 5.0+
- PHP 8.2+

## Installation

### Manual

1. Download the plugin ZIP or clone this repository
2. Upload to `wp-content/plugins/`
3. Activate the plugin in WordPress admin

### Composer

```bash
composer require contentpulse/wordpress-plugin
```

## Configuration

1. Go to **Settings > ContentPulse** in WordPress admin
2. Enter your ContentPulse API URL and API Key
3. Save the settings

## Features

- Automatic post creation and updates
- Featured image sideloading
- SEO meta integration (Yoast SEO, Rank Math)
- Category and tag auto-assignment
- Scheduled content support
- Block editor compatibility
- Version handshake for API compatibility

## REST API Endpoints

All endpoints require authentication via the `X-ContentPulse-Key` header.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wp-json/contentpulse/v1/plugin-info` | Plugin version and compatibility info |
| POST | `/wp-json/contentpulse/v1/posts` | Create or update a post |
| GET | `/wp-json/contentpulse/v1/posts/{id}` | Retrieve a post by ID |
| DELETE | `/wp-json/contentpulse/v1/posts/{id}` | Delete a post |
| GET | `/wp-json/contentpulse/v1/ingestion/status` | Ingestion status |

## Authentication

Include your API key in the request header:

```
X-ContentPulse-Key: your-api-key
```

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## License

GPL-2.0-or-later
