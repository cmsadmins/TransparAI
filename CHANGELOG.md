# Changelog

## 1.0.0

Initial release.

- Automatic AI detection: C2PA/Content Credentials (JPEG APP11/JUMBF, PNG caBX, WebP, ISO-BMFF uuid boxes, `.c2pa` sidecars), IPTC `DigitalSourceType` in XMP (element, attribute and rdf list forms) and IPTC-IIM, PNG generator chunks (A1111 `parameters`, ComfyUI `prompt`/`workflow`, NovelAI, InvokeAI, SwarmUI, Fooocus), EXIF/XMP generator signatures (word-boundary matched), JPEG COM segments, MP3 ID3v2 "aigc" declarations.
- Camera rule: C2PA presence alone never auto-labels (Leica/Sony embed Content Credentials in real photos).
- Confidence levels (certain/likely/hint) with per-level behavior, review queue with confirm/dismiss (single + bulk), stored evidence per detection.
- Batched library scan (AJAX, time-budgeted, pausable) and per-file re-check.
- Visible badge: overlay or caption mode, 4 positions, 3 sizes, dark/light/outline/icon-only, optional generator name, optional alt-text note; server-side rendering (page-cache safe) for blocks (image, gallery, cover, media-text, featured image, video, audio), classic content, template images, text widgets and Elementor incl. Theme Builder; optional CSS background labeling.
- Machine-readable labeling: IPTC DigitalSourceType as XMP for JPEG (APP1), PNG (iTXt) and WebP (RIFF incl. VP8X handling), all size variants, XMP merge with own-marker removal, optional IPTC-IIM mirror (JPEG, only when no foreign IPTC block exists), atomic validated writes.
- Auto-repair: file fingerprinting plus hourly integrity sweep restores metadata stripped by image optimizers or thumbnail regeneration.
- WP-CLI: `scan`, `flag`, `unflag`, `status` (table/csv/json export), `write-meta`, `verify-meta --repair`.
- Context integrations: AI Engine, AI Power, Elementor AI, WordPress AI; public `transparai_mark_ai` action and detection filters.
- No external requests of any kind.
