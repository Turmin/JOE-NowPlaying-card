# JOE Now Playing Card

A lightweight, embeddable PHP card that displays the currently playing track on JOE or Qmusic. It includes cover art, artist and title information, start time, release year, a live progress bar, automatic refresh, caching and a dark mode.

## Features

- Displays the current track, artist and cover art.
- Shows the track start time and release year when available.
- Updates the page every 10 seconds and advances the progress bar every second.
- Caches API responses for 10 seconds and falls back to stale cache data when the API is temporarily unavailable.
- Supports JOE and Qmusic through a query parameter.
- Supports light and dark mode through a query parameter.
- Has no build step or package manager dependencies.

## Requirements

- A web server with PHP 7.4 or newer.
- PHP cURL enabled for API requests.
- Outgoing HTTPS requests enabled.
- Write permission for the project directory so the card can store its JSON cache files.

## Installation

1. Clone or download this repository to a PHP-enabled web server.
2. Keep `now-playing-card.php` and the `static/` directory together.
3. Make sure the directory is writable by the web server user.
4. Open `now-playing-card.php` in a browser, or embed that URL in the page or platform where the card should be shown.

There is no build step. The PHP file loads its CSS and JavaScript from `static/css/` and `static/js/`.

## Usage

The default station is JOE:

```text
/now-playing-card.php
```

Select a station with the `station` parameter:

```text
/now-playing-card.php?station=joe_nl
/now-playing-card.php?station=qmusic_nl
```

Enable dark mode with the `darkmode` parameter:

```text
/now-playing-card.php?station=joe_nl&darkmode=true
```

`darkmode` accepts an empty value, `true`, `yes` or `on`. Station IDs are validated before they are sent to the API. The API must support the requested station for track data to be displayed.

## Configuration

The API endpoint, default station and cache lifetime are defined at the top of `now-playing-card.php`:

```php
$apiBaseUrl = 'https://joe-api.turmin.com/playlist';
$defaultStation = 'joe_nl';
$cacheTtl = 10;
```

Cache files are written next to the PHP file. The default station uses `now-playing-cache.json`; other stations use a station-specific filename.

The visual styling can be adjusted in [static/css/now-playing-card.css](static/css/now-playing-card.css). The client-side progress timer is in [static/js/now-playing-card.js](static/js/now-playing-card.js).

## Deployment with GitHub Actions

The workflow in `.github/workflows/deploy.yml` deploys the PHP card over FTPS whenever `main` or `development` is updated, and can also be started manually.

Configure these GitHub environment secrets before using the workflow:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_REMOTE_DIR`

The workflow intentionally uploads the PHP and frontend assets needed by the card, while excluding repository documentation and local cache files.

## Development

The project is intentionally dependency-free. After changing the PHP file, validate its syntax with:

```bash
php -l now-playing-card.php
```

## License

This project is licensed under the [MIT License](LICENSE).
