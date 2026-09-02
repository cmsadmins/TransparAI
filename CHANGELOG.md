# Changelog

## 1.0.0

Initial release.

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
