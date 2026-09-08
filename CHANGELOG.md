# Changelog

## 1.0.1 (2026-09-08)

- Fixed: the hourly integrity sweep was scheduled from the activation hook alone, and that hook fires
  once for a network-wide activation. Every site created in the network afterwards kept no schedule at
  all, so an AI declaration stripped by an image optimizer was never repaired there and nothing said
  so. `TransparAI_Repair::init()` now schedules the sweep itself; the call is idempotent and also
  recovers sites whose cron entry was lost in a migration or a restored backup.
- Per-file history in `_transparai_history`: label, review decision, repair and failed write are
  recorded with time, event, editor and source, capped at the last ten events per file. Read it with
  `TransparAI_Meta::history()` and `TransparAI_Meta::last_change()`.
- Audit export as CSV from the plugin page (`admin-post.php?action=transparai_export`), built from
  `TransparAI_Meta::audit_rows()`, the same rows `wp transparai status` prints. Both now carry the
  last event, its time and the user behind it.
- File inspection in the attachment details ("Show file metadata"): every size with its state, the
  declared digital source type, the full detection evidence, the history and the raw XMP packet of
  the main file, read live from disk through the new `TransparAI_Writer::inspect()`.
- Optional delivery check (`TransparAI_Delivery`, setting `delivery_check`, off by default): fetches
  one image over its own public URL and compares the delivered bytes with the file on disk, which is
  the only way to see that an optimizing CDN re-encodes images and drops the declaration on the way
  out. Runs on click or through `wp transparai verify-delivery [<id>...] [--sample=<n>]`, never on a
  schedule, and refuses any URL whose host is not this site. The readme sections External services
  and Privacy describe it, since the previous wording promised no HTTP request at all.
- Translations shipped for German, French, Spanish, Italian and Dutch (`.po`, `.mo` and `.l10n.php`);
  `.distignore` no longer excludes the compiled catalogs from the release ZIP.

## 1.0.0 (2026-09-08)

Initial release. The list below is the complete feature set as shipped; entries marked Fixed or
Hardened come from the pre-release hardening pass and never affected a published version.

- Fixed: an AI declaration was missed when WordPress' big-image scaling re-encoded the upload into the "-scaled" attached file (which drops all metadata) or an image optimizer such as Imagify stripped it. The scan now falls back to the untouched pre-scale original next to the scaled file, where the declaration survives; the evidence names the original file when it was the source.
- Long overlay badge labels (a generator name like "Google C2PA Core Generator Library") are now capped at 16em with an ellipsis even on large images; hovering or tapping the media expands the badge to the full label.
- Optional badge start date: only media uploaded on or after the configured date get the visible front-end badge, so the labeling can be introduced on an existing site without retroactively badging older content. Earlier media stay labeled in the admin only; the machine-readable file metadata is unaffected. Empty (the default) badges all labeled media as before.
- Overlay guard for the visible badge: the front-end script now checks the real paint order at each badge (hit test, no z-index guessing) and, only when a theme layer actually covers it, raises the badge, moves it to a free corner or, as the last resort, shows it as a caption line below the image. On by default, can be turned off under Visible badge.
- Badge stacking level raised from a fixed `z-index: 2` to the CSS custom property `--trai-badge-z` (default `30`): above typical theme chrome, still far below lightbox and modal layers, tunable globally or per container.
- Per-image badge override: a "Badge position" select in the attachment details (four corners, caption line below the image, or hide the visible badge for this image). Hiding never touches the in-file metadata or the JSON-LD output. Stored as `_transparai_badge_pos`, REST-registered, page caches are purged on change.
- CSS utility classes `trai-badge-top-left`, `trai-badge-top-right`, `trai-badge-bottom-left`, `trai-badge-bottom-right`, `trai-badge-below` and `trai-badge-hidden`: put one on any container (every builder allows custom classes) to reposition or hide the badges inside it. Manual placements are exempt from the overlay guard, and `trai-badge-manual` opts a subtree out of the guard without changing the position.
- New filters `transparai_badge_html` and `transparai_badge_wrap_classes` for customizing the badge markup and wrapper classes per attachment.
- Fixed: with the icon-only style, a caption line showed the full label and the abbreviation next to each other ("AI-generated" plus "AI"). The abbreviation exists for overlay badges that have to fit on the media and is now left out wherever the badge is a caption line, which includes video and audio badges.
- Fixed: with the outline style, a caption line kept the light border and the dark glow meant for sitting on top of an image, which showed up as a stray box around the text on the page background.
- Fixed: a C2PA manifest declaring the legacy term compositeSynthetic was treated as fully generated instead of composite, so the wrong DigitalSourceType was written into the file. Both the XMP and the C2PA path now read one vocabulary table, and the IPTC-IIM path matches the longest term first, which removes an ordering trap between the overlapping terms.
- Fixed: media the scan deliberately leaves alone (labeled by hand, previously dismissed, or a confidence level switched off) was counted as "clean" in the scan summaries of the admin and WP-CLI. Both now report it as skipped, from one shared tally.
- Fixed: `wp transparai scan --dry-run` predicted its own outcome instead of asking the scanner, so with a non-default confidence setting or on a partly labeled library the preview did not match the real run. It now uses the same decision, and reports unreadable files as unreadable rather than clean.
- Fixed: reading a compressed PNG iTXt block during a write called zlib without checking it exists, which is a fatal error on a PHP build without zlib. The read path had that guard, and both now share one implementation.
- Hardened: the temporary file of an atomic write carries a unique name, so a repair sweep and an editor action touching the same file at the same moment cannot meet on one temp path.
- Fixed: a setting stored as something other than a string, as WP-CLI, a migration or another plugin can leave it, ended the page in a fatal error while the footer was being written, which cut off everything after the structured data. Settings are now read defensively.
- Fixed: `wp transparai status --format=ids` printed "Array" once per row instead of the attachment IDs, which made it useless for piping into another command. It now prints the plain ID list.

- Schema.org JSON-LD per page: `ImageObject`/`VideoObject`/`AudioObject` with the IPTC `digitalSourceType` (and the generator as `creator`) for every labeled medium rendered on the page, so search engines read the declaration straight from the markup.
- Optional site-wide disclosure note at the end of pages containing labeled media, and a per-post "This content is AI-generated" checkbox that puts a configurable note ahead of AI-written content.
- XMP writing extended to AVIF (top-level XMP uuid box, exiftool-verified), size variants and auto-repair included; video/audio containers stay read-only on purpose.
- Page caches (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, Cache Enabler, Breeze, Nginx Helper, Hummingbird) are purged when a label changes, so cached pages never serve an outdated badge state.
- WooCommerce validated end to end: shop loop, product gallery with thumbnails and lightbox copies, negative probes stay clean.
- WPML/Polylang support via wpml-config.xml (badge and notice texts, content flag copied to translations).

- Automatic AI detection: C2PA/Content Credentials (JPEG APP11/JUMBF, PNG caBX, WebP, MP4/MOV uuid boxes, `.c2pa` sidecars), IPTC digital source type in XMP (element, attribute and rdf list forms) and IPTC-IIM, PNG generator chunks (A1111 `parameters`, ComfyUI `prompt`/`workflow`, NovelAI, InvokeAI, SwarmUI, Fooocus), EXIF/XMP generator signatures with word-boundary matching, JPEG COM segments, MP3 ID3v2 "aigc" declarations.
- Camera rule: C2PA presence alone never auto-labels, since Leica, Sony and Nikon embed Content Credentials in real photos.
- Confidence levels (certain, likely, hint) with configurable behavior per level, a review queue with confirm and dismiss (single and bulk), stored evidence per detection.
- Batched library scan (AJAX, time-budgeted, pausable) plus a per-file re-check.
- Visible badge: overlay or caption mode, 4 positions, 3 sizes, dark, light, outline and icon-only styles, optional generator name, optional alt text note. Rendered server-side (page-cache safe) for blocks (image, gallery, cover, media-text, featured image, video, audio), classic content, template images, text widgets and Elementor incl. Theme Builder. Optional CSS background labeling.
- Machine-readable labeling: IPTC digital source type as XMP for JPEG (APP1), PNG (iTXt) and WebP (RIFF incl. VP8X handling), all size variants, XMP merge that preserves foreign packets, optional IPTC-IIM mirror (JPEG, only when no foreign IPTC block exists), atomic validated writes. Failed writes are surfaced on the attachment instead of failing silently.
- Auto-repair: file fingerprinting plus an hourly integrity sweep restores metadata stripped by image optimizers or thumbnail regeneration.
- WP-CLI: `scan`, `flag`, `unflag`, `status` (table, csv and json export), `write-meta`, `verify-meta --repair`.
- Integrations: AI Engine, AI Power, Elementor AI, WordPress AI; public `transparai_mark_ai` action and detection filters.
- No external requests of any kind.
- Admin design aligned with the CMS-ADMINS design system: scoped `--trai-*` tokens, orange accent for review states, progress and focus; front-end badges stay neutral by design. wp.org icon and banner in the same palette.
- The experimental front-end option now also labels images printed without an attachment ID (ACF image fields returned as URL or array, sliders, page-builder templates) by matching their src against the labeled files, size variants and the -scaled marker included.
- Page-builder support: the server-side wrap now resolves images by class or by src/data-src against the labeled files (page-cache safe), core-rendered builder images get their attachment class restored, WPBakery helper images are tagged via `vc_wpb_getimagesize`, and badges are never injected into the Elementor or WPBakery editing screens. Covers Elementor widgets, galleries, carousels and Theme Builder templates as well as WPBakery rows, single images, galleries and grids.
- Every image module of both builders validated one by one (Elementor free and Pro: image, carousel, basic and Pro gallery, image box, video overlay, testimonial, slides, media carousel, flip box, call to action, hotspot, posts; WPBakery: single image incl. style variants, gallery, carousel, row and column backgrounds, parallax, hoverbox, masonry media grid). The background script now also reads `data-thumbnail` and `.swiper-slide-bg` surfaces, relabels media that appears after page load (lazy galleries, AJAX grids) through a debounced observer, keeps one badge per surface and file, and overlay badges can no longer grow wider than the media they sit on.
