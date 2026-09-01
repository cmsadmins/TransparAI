# TransparAI – AI Image Marker & Detector

WordPress plugin: label AI-generated media (EU AI Act, Art. 50) with a visible badge and
machine-readable IPTC/XMP metadata written into the files — and detect AI media automatically
via C2PA/Content Credentials, IPTC `DigitalSourceType`, generator signatures and more.

- Requires WordPress 6.2+ and PHP 8.1+
- No external requests, no telemetry — everything runs on your server
- WP.org listing: https://wordpress.org/plugins/transparai/

## Development

```bash
composer install
composer lint      # PHPCS (WordPress Coding Standards)
composer analyse   # PHPStan
composer test      # PHPUnit (WordPress-free unit tests against binary fixtures)
```

Fixtures for the parser/detector/writer tests are generated, not committed:

```bash
php tests/fixtures/make-fixtures.php
```

The surrounding Docker workspace (local WordPress, Plugin Check, build tooling)
lives in the private parent repository; this repository contains the plugin only.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
