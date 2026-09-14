# Changelog

## 1.0.3 (2026-09-14)

- Chatbot disclosure (`TransparAI_Chatbot`, `data/chatbots.json` with 44 vendors and their staffing):
  options `chatbot_answer`, `chatbot_staffing`, `chatbot_notice_text`, `chatbot_output`; the notice is on
  only with answer yes and staffing ai|mixed. Detection without any HTTP request: active plugin
  directories plus class checks, theme files and snippet options (1 MB cap each), the scripts
  `wp_scripts()` registered on a front-end page (hourly, kept 30 days), and a client report from an
  administrator's browser (`wp_ajax_transparai_chatbot_seen`, nonce, ids validated against the bundled
  list). Output: `mwai_chatbot_params` prepends the notice to AI Engine's first message idempotently,
  `chatbot.js` places a fixed note next to the detected launcher (MutationObserver, 30-second stop),
  or a server-rendered footer line. `transparai_chatbot_vendors` and `transparai_chatbot_notice` filters.
- Setup (`TransparAI_Setup`, admin only): activation sets a 30-second transient, `admin_init`
  redirects to the settings page once, guarded against AJAX, network admin, missing capability,
  `activate-multi` and a finished setup. The card renders inside the settings page: step 1 triggers the
  existing scan loop, steps 2 and 3 post through `admin_post_transparai_setup` and only ever touch
  their own keys (`STEP_KEYS`); each apply snapshots the previous values into
  `transparai_setup_journal` (cap 20) and `undo()` restores exactly that snapshot. Finish sets
  `transparai_setup_done`, "Not now" is user meta. Front-end styles are loaded on the settings page
  for the live badge preview.
- REST API `transparai/v1` (`TransparAI_REST`): `GET /media` (paginated, status enum), `GET|POST
  /media/{id}` (detail with history and file state; actions flag|unflag|confirm|dismiss|human_*),
  `POST /media/{id}/scan`, `GET /report`. Args declared with enum/minimum/maximum so the server
  validates before the handler; route gate `upload_files`, per-object `edit_post` in the permission
  callback; report needs `manage_options`. No public route.
- History: `HISTORY_LIMIT` 50, entries carry `p` (state before: ai|detected|dismissed|human) and `n`
  (display name, survives user deletion); `record()` takes the previous state, every mutator passes
  it. `history_text()` renders one line per file for exports. Site log option `transparai_log`
  (cap 200): settings-saved (changed keys), scan-started/finished, sweep-finished, bulk-*.
- Report: `TransparAI_Meta::report()` is the canonical record (counts, items with history,
  `guidance_basis`, `limitations`, `truncated` at 5000, `document_hash` = sha256 over the sorted
  facts without timestamps). `audit_rows()` pages through 200-row queries instead of
  `posts_per_page => -1`. CSV export: BOM, `;`, formula guard, history column; new print view
  (`admin_post_transparai_print`) with hash, basis, limitations and print-to-PDF.
- WooCommerce (`TransparAI_WooCommerce`, no-op without the plugin): `woocommerce_available_variation`
  hands the label state of the variation image to front.js (`TransparAI_Frontend::public_label()`),
  which listens to `found_variation`/`reset_data` instead of watching `src`; an explicit null for
  variations without an own image removes the parent's badge. Lightbox clones (PhotoSwipe
  `.pswp__zoom-wrap`, core `.wp-lightbox-overlay`) get a badge layer from a page-built map keyed by
  normalized upload path. `woocommerce_email_header`/`_footer` mute all badge output, the product
  summary prints the text note at priority 45 and marks it placed so the description tab does not
  repeat it. HPOS compatibility declared via `FeaturesUtil`.
- Non-AI declaration per attachment (`_transparai_human` = `digitalCapture` | `digitalCreation`):
  `mark_human()` clears label and detection, `flag()` clears the declaration, the scanner's
  `planned_status()` returns `skipped` for declared media. Writer: `write_type()`/`expected_token()`
  decide what a file should carry; a declaration is only written into files without a foreign
  source type (`has_foreign_dst()`), `file_is_marked()` takes the expected token, the repair sweep
  covers declared media via the new `labeled` meta query and accepts any declaration for them.
  Front end: structured data always, `trai-badge--human` only with `human_badge`. Media library:
  select in the attachment field, grid and list filter `human`, three native bulk actions,
  `wp transparai human`, `status --status=human`.
- AI-written text: the per-post checkbox became a disclosure level (`none`, `assisted`, `generated`,
  `generated_reviewed`), stored in the same meta key; the old `'1'` reads as `generated`. Block
  editor panel over the entity store (saves with the post, lands in revisions), classic meta box
  hidden there via `__back_compat_meta_box`, Quick Edit with a data marker in the list column so a
  Quick Edit save never resets the level, Bulk Edit through the core `bulk_edit_posts` hook (offered
  from WordPress 6.3, where that hook exists), sortable column via a LEFT JOIN, list filter.
- Review stamp (`_transparai_content_review`): reviewer, date and a sha256 over title, content,
  featured image and embedded attachments with their AI label, set through the meta hooks so every
  save path is covered. `is_review_current()` compares it and the list says "changed since review".
- `TransparAI_Notice` is the single render path for the note: automatic note at `the_content` 30
  with the full guard stack, `[transparai_notice]` shortcode, server-rendered `transparai/notice`
  block (block.json, hand-written ES5 editor script, no build step), excerpt and feed variants
  (`the_content_feed`, `the_excerpt_rss`, `rss2_item` with `dc:description`). Placing the block or
  shortcode switches the automatic note off (`has_block()` plus rendered marker).
- JSON-LD: `Article`/`WebPage` node for AI-written posts, stable `@id` on every node, and the source
  type as both the Schema.org enumeration and the IPTC URI in `additionalProperty`; printed with
  `wp_print_inline_script_tag()` and the HEX flags. Footer output no longer depends on the badge
  switch for the text node.

## 1.0.2 (2026-09-08)

- Fixed: the library scan stopped after its first batch on sites where another plugin or the theme
  calls `wp_enqueue_media()` on the plugin page. Both admin surfaces localize the same script handle
  and object name, and `wp_localize_script()` replaces a registered object instead of merging into
  it, so the media-library set overwrote the settings set and `labels.scanProgress` was gone. The
  progress callback then died on its first `.replace()` and never requested the next batch, without
  a visible error. There is one shared label set now, and `AdminLabelsTest` fails if a string
  `admin.js` uses is missing from it.
- Fixed: PNG files were only searched for metadata within the first 512 KB. PNG allows metadata
  chunks after the image data, so a generated 4K image carried its declaration outside that window
  and stayed undetected. Chunks are read from disk now, seeking over the image data, which also
  keeps the memory cost independent of the file size (a 42 MB PNG parses in 2 MB).
- Fixed: Microsoft signs images from Bing Image Creator, Designer and Copilot with the claim
  generator `Microsoft Responsible AI Provenance`, which no rule matched, so certain AI images only
  reached the review queue. Conversely `designer` matched any editor carrying that word, Affinity
  Designer included. Both replaced by exact needles.
- The file inspection panel now says whether the attachment is labeled at all. A file without a
  declaration was reported as "declaration missing" even when nothing was supposed to be written
  into it, which read like a defect instead of the normal state.

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
- Fixed before release, found in browser testing: `delivery_check` was missing from the boolean list
  in `TransparAI_Options::sanitize()`, so the setting could not be switched on at all; a test now
  walks the defaults so the next on/off setting cannot be forgotten the same way.
- Fixed before release: `wp transparai verify-delivery` reported success even when every request had
  failed. Intact, stripped and not-comparable results are counted apart, in the CLI and in the sample
  summary on the plugin page.
- Confirming a detection whose file cannot be written now says so immediately instead of at the next
  page load.
- Fixed before release: the catalog build matched translations by their position in the `.pot`, and
  regenerating that file after adding a string shifted every entry behind it, so a handful of strings
  carried the neighbouring translation in all five languages. Matching happens by msgid now, and the
  catalogs are verified against the source strings (placeholders and length) before they ship.

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
