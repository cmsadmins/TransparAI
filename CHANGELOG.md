# Changelog

## 1.0.0

Initial release.

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
