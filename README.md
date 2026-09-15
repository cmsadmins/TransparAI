# TransparAI

WordPress plugin that finds AI-generated media in the library, labels it with a
visible badge (EU AI Act, Art. 50) and writes machine-readable IPTC/XMP metadata
into the files. Detection covers C2PA/Content Credentials, the IPTC digital
source type, generator signatures in PNG chunks and EXIF/XMP, MP4 boxes and MP3
declarations. A compliance module adds the readiness score, a self-assessment,
the Article 4 AI literacy checklist, a local registry of AI plugins in use and
a compliance report, all under a top-level "TransparAI" admin menu.

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

## Developer reference

Everything below is stable API surface; the prefixes are `transparai_` for hooks and options and `_transparai_` for attachment meta.

### Attachment meta

 (registered for the REST API, readable and writable with `upload_files` capability):

* `_transparai_ai`: `'1'` when the attachment carries the confirmed AI label, absent otherwise. This is the single source of truth for badge and file metadata.
* `_transparai_type`: `generated` or `composite` (AI-edited). Controls which digital source type is written.
* `_transparai_source`: where the detection came from (`c2pa`, `xmp-dst`, `iim`, `png-chunk`, `exif`, `com`, `id3`, `sidecar`, `filename`, `context`).
* `_transparai_generator`: detected generator name, for example `Midjourney` or `OpenAI`.
* `_transparai_confidence`: `certain`, `likely` or `hint`.
* `_transparai_detected`: `'1'` while an unconfirmed detection waits in the review queue. Kept strictly apart from the public label.
* `_transparai_history`: JSON list of the last fifty events for this file, oldest first. Each entry is `{"t":unix time,"e":event,"u":user ID,"s":trigger,"p":previous state,"n":display name}`, with the events `flagged`, `confirmed`, `unflagged`, `queued`, `dismissed`, `repaired`, `write-failed`, `human-capture`, `human-creation`, `human-removed` and `delivery-*`; the previous state is `ai`, `detected`, `dismissed`, `human` or empty. Written by `TransparAI_Meta::record()`, read with `TransparAI_Meta::history()` and `TransparAI_Meta::last_change()`; `TransparAI_Meta::audit_rows()` returns the rows behind both the CSV export and `wp transparai status`.
* `_transparai_delivery`: result of the last delivery check as `{"t":unix time,"verdict":verdict}`, with `intact`, `stripped`, `unreachable`, `unmarked` or `foreign-host`. Only written when the check is enabled and someone runs it; read it with `TransparAI_Delivery::last_result()`.
* `_transparai_human`: `digitalCapture` or `digitalCreation` when the attachment was declared as not AI-made; absent otherwise. Mutually exclusive with `_transparai_ai`: setting one removes the other. Written through `TransparAI_Meta::mark_human()` and `unmark_human()`, or the bulk actions `human_capture`, `human_creation` and `human_remove`.
* `_transparai_badge_pos`: badge placement for this one image, overriding the site setting. `top-left`, `top-right`, `bottom-left`, `bottom-right`, `below` (caption line under the image) or `hidden`. Absent means the site setting applies.

### Post meta

 (registered for the REST API on every public post type except attachments, writable with `edit_post`):

* `_transparai_content_ai`: the AI level of the text, `none`, `assisted`, `generated` or `generated_reviewed`; absent means not classified. The 1.0.x checkbox value `'1'` is still read as `generated`.
* `_transparai_content_responsible`: name of the person responsible for a reviewed text (optional, falls back to the site default).
* `_transparai_content_review`: JSON stamp written by the plugin when a post reaches the reviewed level: `{"by":display name,"by_id":user ID,"on":Y-m-d,"responsible":name,"hash":sha256}`. The hash covers title, content, featured image and every embedded attachment together with its AI label; `TransparAI_Meta::is_review_current()` tells whether it still matches. Readable in the editor, never writable through REST, and stripped down to the date for readers without `edit_post`.

### Shortcode and blocks
`[transparai_notice]` renders the note of the current post (`type="content"`, the default), `[transparai_notice type="media" id="123"]` the label of one attachment (AI-generated or Human made), `type="systems"` the AI systems notice and `type="chatbot"` the chatbot notice. `style` accepts `block`, `inline`, `banner`, `badge` or `modal`, `text="..."` overrides the wording, `id` picks another post or file. Five blocks cover the same ground with a live preview: "AI Notice" (`transparai/notice`), "AI Image Label" (`transparai/media-label`, with a media picker), "AI Systems Notice" (`transparai/systems-notice`), "AI Systems List" (`transparai/systems-list`, a `ul` with categories) and "Chatbot AI Notice" (`transparai/chatbot-notice`). Each block has its own `block.json` under `blocks/`, all share `blocks/editor.js` and one render path with the shortcode (`TransparAI_Notice::resolve()`). Everything renders only what is declared: a post without AI level, an unlabeled attachment, no visible system or no AI in the chat produces nothing. A placed AI Notice block marks the note as placed, so the automatic note steps back, also in block theme templates. The setting "only where a block or shortcode is placed" (`content_notice_position` and `systems_notice_style` value `manual`) switches the automatic output off entirely. Filters: `transparai_notice_text` (`$text, $post_id, $level`) and `transparai_notice_html` (`$html, $args`).

### REST API

`GET /report` now carries `compliance` (`score`, `traffic`, `factors`, `assessment`,
`literacy`, `systems`, `content`, `notices`) and `log`; the `document_hash` covers the
compliance facts but not the log or the save timestamps.

 (`/wp-json/transparai/v1/`, authenticated users with `upload_files`, writes additionally need `edit_post` on the attachment, the report needs `manage_options`; nothing is public):

* `GET /media?status=flagged|detected|human|labeled|all&page=1&per_page=20`: paginated audit rows.
* `GET /media/{id}`: the row plus history, what the files carry and the expected digital source type.
* `POST /media/{id}` with `action=flag|unflag|confirm|dismiss|human_capture|human_creation|human_remove` and optional `generator`, `type=generated|composite`.
* `POST /media/{id}/scan`: re-check the file.
* `GET /report?status=all`: the canonical audit record with counts, items, history, guidance basis, limitations and `document_hash` (sha256 over the facts without timestamps).

### Label media from your own code

 (an AI image generator plugin, an import script):

`do_action( 'transparai_mark_ai', $attachment_id, 'My Generator' );`

This sets the confirmed label, records the generator name and, with file writing enabled, writes the metadata into the files. Existing labels are never overwritten.

### Extend or veto detection
`add_filter( 'transparai_signatures', function ( $signatures ) { $signatures[] = array( 'pattern' => '/my-generator/i', 'generator' => 'My Generator' ); return $signatures; } );`

`transparai_detection_result` filters the final result per file (or `null`); return `null` to veto a detection, or return a result array to add your own. Both filters receive documented shapes, see the source of `TransparAI_Detector`.

### Customize the badge output
`transparai_badge_html` filters the badge element per attachment (`$html, $attachment_id`); `transparai_badge_wrap_classes` filters the wrapper class list (`$classes, $attachment_id`, `0` for the shared template of the optional script).

### Place badges from your theme or builder

 Put one of these classes on any container and it applies to every badge inside it, which works in every builder because they all allow custom classes:

* `trai-badge-top-left`, `trai-badge-top-right`, `trai-badge-bottom-left`, `trai-badge-bottom-right`: move the badges to that corner.
* `trai-badge-below`: show them as a caption line under the media instead of on it.
* `trai-badge-hidden`: no visible badge inside this container. File metadata and structured data stay untouched.
* `trai-badge-manual`: keep the position as configured and switch the automatic overlay guard off for this subtree.

The first six switch the guard off by themselves, since a placement you chose should not be second-guessed. The stacking level of all badges is the CSS custom property `--trai-badge-z` (default `30`, raised to `99` only where the guard found a real overlap); it inherits, so a theme can tune it globally or per container with a single declaration and without touching the stylesheet. The markup is one wrapper `span` carrying the state classes plus `span.trai-badge` directly after the media element.

### Compliance module

Three options besides the settings array, no custom tables:

| Option | Shape |
|---|---|
| `transparai_compliance` (autoload off) | `assessment{id => yes|no}`, `assessment_at`, `assessment_by`, `literacy{id => bool}`, `literacy_at`, `literacy_by` |
| `transparai_systems` | `detected{id => evidence}`, `manual{id => {name, category, slug}}`, `visible{id => true}`, `scanned_at` |
| `transparai_log` | last 200 site events (`t`, `e`, `u`, `n`, `d`) |

`TransparAI_Compliance::score()` is the share of met factors (`factors()`), each a
decision or an artefact the plugin can verify itself; `traffic()` buckets it at 80 and 50.
`milestones()` holds the enforcement dates of Regulation (EU) 2024/1689 (Article 113) in one place.

`data/ai-systems.json` is the bundled registry (`id`, `name`, `category`, `article`, `risk`, `url`,
`slugs`). Categories: `content`, `image`, `chatbot`, `translation`, `personalisation`, `seo`,
`search`, `audio_video`, `assistant`, `other`. Extend or adjust it without a fork:

```php
add_filter( 'transparai_systems_registry', function ( array $registry ): array {
    $registry['house-recommender'] = array(
        'id'       => 'house-recommender',
        'name'     => 'House Recommender',
        'category' => 'personalisation',
        'article'  => 'Art. 4',
        'risk'     => 'limited',
        'url'      => '',
        'slugs'    => array( 'house-recommender' ),
    );
    return $registry;
} );
```

The visitor notice text goes through `transparai_systems_notice` (`$text`, `$systems`).
`[transparai_notice type="systems"]`, the "AI Systems Notice" block and the "AI Systems List" block place it
by hand; `style` accepts `block`, `inline`, `banner`, `badge` or `modal` for every notice type, and the
`manual` notice style keeps the automatic footer line off.

### Label markup your theme renders itself

```php
echo apply_filters( 'transparai_label_media', $html );
```

Same rules as `the_content`: badge switched on, no builder editor, no feed. No
`function_exists()` guard is needed, an inactive plugin leaves the filter unregistered.
Bricks Builder output is handled automatically through `bricks/frontend/render_element`.

### WP-CLI

 (`wp transparai <command>`):

* `scan [--all] [--dry-run]`: scan the library; `--all` rescans everything, `--dry-run` only reports.
* `flag <id>... [--source=<text>]` and `unflag <id>...`: set or remove labels in bulk.
* `human <id>... [--type=capture|creation] [--remove]`: declare media as camera photo or human work, or withdraw that.
* `status [--status=flagged|detected|human|labeled|all] [--format=table|csv|json|ids|count]`: audit export, for example `wp transparai status --format=csv > ai-audit.csv`.
* `write-meta [--dry-run] --yes` and `verify-meta [--repair]`: write and verify the in-file metadata.
* `verify-delivery [<id>...] [--sample=<n>]`: fetch labeled images over their own public URL and report whether the declaration survives delivery. Needs the delivery check enabled in the settings.
* `score [--format=...]`: readiness score with the checks behind it.
* `content [<id>...] [--level=none|assisted|generated|generated_reviewed] [--remove] [--format=...]`: list the published posts with an AI level, or set or remove the level on posts. Pass `--user=<login>` so a reviewed level stamps a person.
* `assessment [--answer=<question>:<yes|no>,...] [--tick=<item>,...|all|none] [--format=...]`: show the self-assessment and the Article 4 checklist, record answers, tick items.
* `systems [--rescan] [--format=...]`, `systems-declare <name> [--category=...] [--slug=...]`, `systems-undeclare <id>...`, `systems-visible [<id>...] [--all]`: the AI systems inventory, manual declarations and which systems the visitor notice names.
* `report [--status=...]`: the audit record as JSON, identical to `GET /report` and the print view, for example `wp transparai report > transparai-report.json`.

### Theme integration

 print attachment images through `wp_get_attachment_image()` (or markup carrying the `wp-image-{ID}` class) and the badge is rendered server-side and page-cache safe. For raw URL output and CSS backgrounds there is the optional script described above; it wraps matched images with the same markup (`span.trai-wrap` around the image plus `span.trai-badge`), so any CSS you write applies to both paths.
