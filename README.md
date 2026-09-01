# TransparAI

WordPress plugin that finds AI-generated media in the library, labels it with a
visible badge (EU AI Act, Art. 50) and writes machine-readable IPTC/XMP metadata
into the files. Detection covers C2PA/Content Credentials, the IPTC digital
source type, generator signatures in PNG chunks and EXIF/XMP, MP4 boxes and MP3
declarations.

- Requires WordPress 6.2+ and PHP 7.4+ (tested up to PHP 8.5)
- No external requests, no telemetry. Everything runs on your server.
- Author: [Patrick Schlesinger](https://www.cms-admins.de/)
- Contact: transparai@cms-admins.de
- Listing: https://wordpress.org/plugins/transparai/

## Development

```bash
composer install
composer lint      # PHPCS (WordPress Coding Standards)
composer analyse   # PHPStan
composer test      # PHPUnit (WordPress-free unit tests against binary fixtures)
```

Fixtures for the parser, detector and writer tests are generated, not committed:

```bash
php tests/fixtures/make-fixtures.php
```

The surrounding Docker workspace (local WordPress, Plugin Check, build tooling)
lives in a private parent repository; this repository contains the plugin only.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
