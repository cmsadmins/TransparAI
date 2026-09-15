=== TransparAI: EU AI Act Compliance, AI Disclosure & AI Image Detection ===
Contributors: contexlabs
Tags: eu ai act, ai compliance, ai disclosure, ai transparency, c2pa
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect AI images via C2PA and IPTC, disclose AI content and chatbots, track EU AI Act readiness with a score, self-assessment and compliance report.

== Description ==

TransparAI is the EU AI Act compliance plugin for WordPress that covers AI transparency and AI disclosure end to end: it detects AI-generated images in your media library, labels them with a visible AI badge, writes the machine-readable AI disclosure (the IPTC digital source type) into the image files, adds the note on AI-written text and the notice in front of a chatbot, and shows you where you stand with a readiness score, a self-assessment, an inventory of the AI systems on your site and a compliance report. Detection, labeling, structured data for SEO, audit trail, REST API and WP-CLI, all on your own server, without a single external request.

**Who this plugin is for**

Publishers and bloggers who use AI images or AI-assisted text. WooCommerce shops with AI product images or descriptions. Sites that run an AI chatbot. Agencies that maintain many client sites and need a documented, exportable and scriptable state per site. Anyone who has to answer "where do we use AI on this site" for management, a customer or a lawyer.

**EU AI Act articles covered**

Article 50, the transparency obligations, applies from 2 August 2026: visitors must be told when they talk to an AI system (50(1)), AI-generated content must carry a machine-readable marking (50(2)), and deepfakes as well as AI-written text on matters of public interest must be disclosed (50(4)). Which paragraph addresses you depends on whether you provide the AI system or deploy it; the plugin covers the technical side of all of them. Article 4, AI literacy, applies since 2 February 2025: providers and deployers take measures so that the people who work with AI systems have sufficient AI literacy, and the plugin's checklist records what your organisation has done. The dashboard shows the whole timeline with the dates as adopted in Regulation (EU) 2024/1689.

**Readiness score, self-assessment and compliance report**

The TransparAI dashboard rates your compliance readiness from 0 to 100 with a traffic light: each check stands for a decision or an artefact the plugin can verify on its own, such as an AI level on your AI-written posts, a worked-through review queue, an answered chatbot question and an inventoried list of AI systems. A six-question self-assessment (chatbot, AI text, AI images, personalisation, translation, synthetic media) names the obligations likely to apply and the page that addresses each of them. The Article 4 checklist counts toward the score. A widget on the WordPress dashboard keeps the score in view. The score is a technical self-check of the plugin state and your own answers, not a legal assessment.

**AI systems registry: which AI plugins run on your site**

A list of known AI tools ships with the plugin and is matched against your installed plugins on your server, nothing is fetched. Chatbots an AI answers in are included, plugins the list does not know are suggested by their own description, and anything else (an external service, a script your theme calls) can be declared by hand. For every system you decide whether visitors are told about it; the notice appears as a line at the end of the page, a small badge or a dismissible banner and can be placed by block or shortcode too.

**Automatic AI image detection**

Most AI image generators leave traces in their files, and TransparAI reads all of the common ones: C2PA manifests (Content Credentials) from OpenAI (ChatGPT, DALL-E, GPT-Image), Adobe Firefly, Google Gemini including Nano Banana, and Bing Image Creator; the IPTC digital source type in XMP as written by Midjourney and a growing number of tools; generation parameters in PNG chunks from Stable Diffusion (AUTOMATIC1111), ComfyUI, NovelAI and InvokeAI; EXIF and XMP signatures of Flux, Leonardo.Ai, Ideogram, Recraft, Seedream and Photoshop Generative Fill; JPEG comment markers, C2PA in MP4 video and AI declarations in MP3 audio.

Detection parses the real file containers (JPEG segments, PNG chunks, RIFF, MP4 boxes, ID3 frames) and matches only inside metadata blocks, never with a blind text search over raw bytes, and never with guesses from image dimensions, file size or file names. Cameras from Leica, Sony and Nikon embed Content Credentials into real photos too, so a C2PA manifest alone never auto-labels anything here. Findings carry a confidence level: explicit AI declarations are labeled automatically, strong but informal signals land in a review queue where you confirm or dismiss them, single or in bulk, right in the media library. New uploads are checked on arrival, the existing library in a batched, pausable scan. Media generated by AI plugins on your own site (AI Engine, AI Power, Elementor AI, WordPress AI) is labeled at the source.

**The visible AI label**

A configurable AI badge marks labeled media in the front end: overlay or caption line, four positions, three sizes, dark, light, outline or icon-only, optional generator name, alt text note for screen readers and start date so older content is not badged retroactively. It renders server-side, so it survives page caching, and it works with the block editor, the classic editor, template images, widgets, Elementor free and Pro including Theme Builder, WPBakery Page Builder, Bricks Builder and WooCommerce, where the badge follows variation swaps and reappears inside the zoom and lightbox. A guard checks the real paint order and moves a badge that a theme overlay covers to a free corner or below the image. Per-image overrides and CSS utility classes give theme builders full control, markup a theme renders itself goes through the `transparai_label_media` filter, and an optional script also labels images printed without an attachment ID and CSS backgrounds. Page-cache plugins are told to refresh whenever a label changes.

**Machine-readable AI labeling, structured data and SEO**

For labeled files TransparAI writes the IPTC digital source type (trainedAlgorithmicMedia, or compositeWithTrainedAlgorithmicMedia for AI-edited media) as XMP into JPEG, PNG, WebP and AVIF, every size variant included. Google documents this field and can show an "AI-generated" label in Google Image Search, so the disclosure travels with the image wherever it is indexed. Each page additionally carries Schema.org JSON-LD structured data (ImageObject, VideoObject, AudioObject and an Article node for AI-written posts) with digitalSourceType both as the Schema.org enumeration and as the IPTC vocabulary URI, so search engines and AI crawlers get the declaration without opening a file. All of it is server-rendered with no extra request and no layout shift, so the SEO signals arrive without a Core Web Vitals cost. The same declarations serve GEO (generative engine optimization): AI search and answer engines such as Google AI Overviews, ChatGPT search and Perplexity weigh structured data and provenance signals when they decide what to cite. Existing metadata is merged, not replaced; unlabeling removes exactly what the plugin wrote; writes are atomic and validated. Because image optimizers and thumbnail regeneration strip metadata, every labeled file is fingerprinted and an hourly sweep restores missing declarations.

**Camera photos and human work**

Not every declaration says "AI". Any media file can be declared as a camera photo (digitalCapture) or as human digital work (digitalCreation), from the attachment details, in bulk, or with WP-CLI. The declaration ends any AI label or pending detection, appears in the structured data, and is written into the file, but only where no other digital source type exists: a camera's own declaration stays untouched. A "Human made" badge is optional and off by default.

**AI-written text: disclosure levels per post**

Every post, page and public custom post type carries an AI level for its text: no AI used, AI-assisted, AI-generated, or AI-generated and reviewed by a person. Set it in the block editor sidebar, the classic meta box, Quick Edit or Bulk Edit; the post list gets a sortable column and a filter, the AI Content screen sums it up per post type. AI levels show a configurable note ahead of the content, after it or on both sides, as a block, an inline note, a dismissible banner, a badge or a button that opens a dialog, plus an optional AI badge behind the post title. Five blocks (AI Notice, AI Image Label, AI Systems Notice, AI Systems List, Chatbot AI Notice) and the `[transparai_notice]` shortcode place every notice by hand; "only where a block or shortcode is placed" switches the automatic output off. Reviewed texts record the reviewer, the date and a fingerprint of the content, so a later edit shows as "changed since review". The note can go into excerpts and RSS feed items, including a dc:description element and an optional [AI] prefix on feed titles.

**Chatbot disclosure**

Chatbot transparency is the Article 50 duty every visitor notices first: people must know when they talk to an AI system. TransparAI puts that notice into the first message of the bot for AI Engine, next to the chat launcher for every other widget, or as a line at the end of each page. Your answer decides whether it appears and who answers in the chat; the plugin recognizes more than 40 chat and chatbot vendors locally (plugins, theme snippets, registered scripts, your own browser as administrator) and tells you what it found, but never switches the notice on from a finding alone, because a live chat with a person behind it is not an AI system.

**Audit trail, compliance report, REST API, WP-CLI**

Every label, review decision, declaration and repair is recorded per file with time, previous state, trigger and the editor's name, plus a site log of settings changes, scans, sweeps, bulk actions and declarations that the dashboard shows as recent activity. Export the whole library as a CSV audit list, or open the compliance report: a print view with the readiness score, the assessment answers, the Article 4 checklist, the AI systems in use, the state of every disclosure notice, the AI-written content, the media audit list and the recent activity, sealed with a document hash over the facts, the guidance basis and the stated limitations, ready for print-to-PDF. A REST API under `transparai/v1` exposes rows, per-file detail, every action and the same report for headless setups and agency tooling; nothing in it is public. WP-CLI covers scanning, labeling, declaring, auditing, the score, the assessment, the AI systems inventory, the AI levels of posts and the report. Other plugins can label media through an action, detection rules and the AI systems list are extensible via filters, WPML and Polylang are configured, and uninstalling cleans up across a multisite network on request.

**Privacy by design**

The plugin runs entirely on your server: no accounts, no telemetry, no external requests, and the list of AI tools ships with the plugin instead of being fetched. The only HTTP request it can make is the optional delivery check, which asks your own site for one image to see whether your CDN strips the declaration. The interface is available in English, German, French, Spanish, Italian and Dutch. Please also read the Disclaimer section.

Contact: TransparAI@cms-admins.de

== Installation ==

1. Install the plugin from the WordPress plugin directory (Plugins, Add New, search for "TransparAI") or upload the ZIP, then activate it. The activation opens **TransparAI, Settings** with a three-step setup: scan the library, choose the badge look with a live preview, decide whether declarations are written into the files. Every step can be undone, and "Not now" hides the card for you until you open it again.
2. New uploads are checked automatically from now on.
3. Click **Scan new/unscanned media** (on the AI Images screen or in the setup) to go through your existing library in small batches, pausable at any time.
4. Clear declarations are labeled right away; strong signals land in the review queue. Follow the **Open review queue** link and confirm or dismiss each item, single or in bulk. A camera photo with Content Credentials shows up here on purpose; dismiss it once and it stays dismissed. Media you have already decided on yourself is reported as skipped in the scan summary and is never overruled.
5. Open **TransparAI, Dashboard** for the readiness score: answer the six questions of the self-assessment, tick the Article 4 checklist, check the AI Systems screen and decide which systems visitors are told about.
6. Adjust the badge under **Settings, Visible badge**, and if your theme prints images without an attachment ID (ACF URL fields, sliders) or uses CSS backgrounds, enable the extra option there.
7. Single image sitting awkwardly? Open its attachment details and pick a **Badge position** there: another corner, a caption line below the image, or no visible badge for that one image.

Everything else runs on its own: labeled files get the IPTC digital source type written into the file (verify with `exiftool -XMP-iptcExt:DigitalSourceType image.jpg`), and the hourly sweep restores metadata that optimizers strip.

== Frequently Asked Questions ==

= Can the plugin detect every AI image? =

No, and no plugin can. Detection relies on metadata that generators embed. Images whose metadata was stripped (social media re-uploads, screenshots, clipboard pastes) carry no signals. Invisible pixel watermarks such as Google SynthID can only be verified by the vendor's own service; TransparAI does not pretend otherwise. Detected metadata is an indication, not cryptographic proof. That is exactly why the review queue exists.

= Which AI image generators are recognized? =

Anything that leaves a standard marking in the file. In practice that covers ChatGPT and DALL-E, GPT-Image, Google Gemini including Nano Banana output, Adobe Firefly and Photoshop Generative Fill, Bing Image Creator, Midjourney, Stable Diffusion (AUTOMATIC1111, ComfyUI, InvokeAI, SwarmUI, Fooocus), NovelAI, Flux by Black Forest Labs, Leonardo.Ai, Ideogram, Recraft and Seedream, plus every tool that writes a C2PA manifest or the IPTC digital source type, which is the direction the whole industry is moving in. New signatures can be added with a filter, no code fork needed. TransparAI is an independent plugin and is not affiliated with any of these vendors, nor with the makers of the plugins in its AI systems list.

= Does the plugin make my site compliant with the EU AI Act? =

It gives you the technical building blocks Article 50 asks for (a visible disclosure and a machine-readable marking) and documents your decisions, but whether and how the EU AI Act or any other law applies to your site, and whether your specific setup satisfies it, is a legal question only you (or your lawyer) can answer. The readiness score is a self-check, not a verdict. See the Disclaimer section.

= What does the readiness score mean? =

It is the share of checks that are done, each check being something the plugin can verify in its own state: AI-written posts carry a level (or you declared that none exist), labeled media exist and nothing waits in the review queue, the chatbot question is answered, the AI systems are inventoried with a visibility decision, and the self-assessment and Article 4 checklist are complete. 100 means every technical building block is in place and documented. It does not mean your site is compliant; the Disclaimer applies.

= How does the self-assessment work? =

Six yes-or-no questions about how your site uses AI: chatbot, AI-written text, AI images, personalisation, translation and synthetic media. The answers list the obligations likely to apply, with the article of the EU AI Act and the plugin page that addresses each one. Answers are stored on your site with the name of the person who saved them, are editable any time and appear in the compliance report.

= What is the Article 4 AI literacy checklist? =

Article 4 asks providers and deployers for measures, as far as they can, toward a sufficient level of AI literacy of the people who operate or use AI systems on their behalf; it prescribes no training format or certificate. The five items are the measures that usually serve as evidence: staff know which AI tools are in use, an internal policy is written down, people who work with AI tools have had guidance, a review date is set, and the context of use and the people affected were considered. Tick what applies; the checklist counts toward the readiness score and is printed in the compliance report with the name and date of the last save.

= How are AI systems detected without external requests? =

A list of known AI plugins ships inside TransparAI and is compared with your installed plugins on your server, the same way the chatbot detection works. The list updates with the plugin, nothing is fetched and nothing about your site is sent anywhere. Active plugins the list does not know are suggested when their own name or description mentions AI, and any other system can be declared by hand. Developers can extend the list with the `transparai_systems_registry` filter.

= Can I export a compliance report? =

Yes. The print view covers the readiness score, the assessment answers, the Article 4 checklist, the detected and declared AI systems, the state of every disclosure notice, the AI-marked content, the media audit list and the recent activity, together with a document hash over the facts, so two reports of an unchanged site carry the same hash. `GET /wp-json/transparai/v1/report` returns the same record as JSON, and the CSV export lists the media audit rows.

= Is this legal advice? =

No. TransparAI is a technical tool, not legal advice, and using it creates no guarantee of compliance with any regulation. See the Disclaimer section.

= Does the plugin change my image files? =

Only when a file is labeled and the metadata option is enabled. The plugin then writes a small XMP block into the JPEG, PNG, WebP or AVIF file and its size variants. The image pixels are untouched. Files are replaced atomically and validated first. Unlabeling removes exactly the metadata this plugin wrote; foreign metadata is never touched.

= Where can I see what was written into a file? =

Open the attachment details and click "Show file metadata". It lists every file of that attachment with its state (declaration present, missing, or a format that cannot carry one), the digital source type currently declared, the detection evidence in full, the recorded history and the raw XMP packet of the main file. Nothing is written while you look; it is a read of the files as they are on disk right now.

= Does the marking survive my CDN? =

Not always, and that is worth checking. Image optimizers at the edge, Cloudflare Polish and Jetpack Photon among them, re-encode images while delivering them and drop every metadata block in the process. The file on your server stays perfect while visitors and search engines receive a bare image, and nothing in WordPress shows it. Enable the delivery check in the settings, then press "Check delivery" on a labeled image: the plugin fetches that image from your own public URL and tells you whether the declaration arrived. If it did not, the fix is in your CDN configuration (keep metadata, or exclude labeled images from re-encoding), not in this plugin.

= Does AI labeling affect my SEO and GEO? =

It gives search engines exactly the signals they document. Google reads the IPTC digital source type that TransparAI writes into the image files and can show an "AI-generated" label in Image Search; the Schema.org JSON-LD in the page carries the same declaration for search engines and AI crawlers that never open the file. Everything is rendered server-side, adds no request and shifts no layout, so there is no Core Web Vitals cost. Whether and how a search engine treats labeled images in ranking is its decision, not something a plugin can promise; what the labeling avoids is the opposite risk, an undisclosed AI image that a platform or a competitor points out later. For GEO, generative engine optimization, the same structured data tells AI search and answer engines where your images and texts come from, which is part of what they weigh when they cite a source.

= Does the plugin detect my chatbot? =

It recognizes more than 40 chat and chatbot vendors by their plugin directory, their script hosts, their JavaScript globals and their widget markup, entirely locally. The finding shows on the settings page with a hint whether a bot or people usually answer there. You decide whether the notice appears; the plugin never switches it on from a finding alone, because a live chat with a person behind it is not an AI system.

= Can I also label AI-written text? =

Yes. Every post and page has an AI level in the editor sidebar (no AI used, AI-assisted, AI-generated, AI-generated and reviewed), also available in Quick Edit and Bulk Edit of the post list. AI levels show a configurable note ahead of or after the content, or on both sides; the "AI Notice" block and the `[transparai_notice]` shortcode place it by hand instead, exclusively so when the position is set to "only where a block or shortcode is placed". Reviewed texts record the reviewer, the date and a fingerprint of the content, so a later edit is visible as "changed since review". Optionally the note goes into excerpts and RSS feed items too. There is also an optional site-wide note at the end of pages that contain labeled media.

= Can I change how the AI notice looks? =

Yes. Under Settings, AI-written text you choose between a block on its own line, an inline note inside the text, a dismissible banner, a small badge and a modal (a button that opens a dialog), and whether it appears ahead of the content, after it, on both sides or only where a block or shortcode is placed. Two further options add a small AI badge to the post title in lists and archives and an [AI] prefix to feed item titles. Dismissing a banner hides it for that page view only; nothing is stored in the visitor's browser.

= Which blocks does the plugin add? =

Five, all rendered on the server so a wording change in the settings reaches every placed block at once, and all silent when nothing is declared. "AI Notice" places the note of the current post in any of the five styles, also several times per post or in a block theme template, where it replaces the automatic note. "AI Image Label" shows the label of one file from the media library, AI-generated with the generator name or Human made, for galleries, captions and builder images the automatic badge does not reach. "AI Systems Notice" is the one-sentence notice naming the visible AI systems, "AI Systems List" the same systems as a list with categories for a transparency statement. "Chatbot AI Notice" tells visitors that an AI answers in the chat. Every block has a shortcode twin in `[transparai_notice]`.

= Why was a real camera photo put into the review queue? =

Modern cameras embed C2PA Content Credentials into real photos. A C2PA manifest alone therefore never auto-labels. It lands in the review queue unless the manifest declares an AI source. Dismiss it with one click; dismissed files are not queued again.

= Which page builders and editors are supported? =

The visible badge covers the block editor (every image-bearing block incl. cover, media-text, galleries, inline images in text, video and audio), the classic editor, template images, text widgets, Elementor free and Pro (image, galleries, carousels, slides, image box, hotspot, flip box, call to action, posts, Theme Builder), WPBakery Page Builder (single images, galleries, carousels, row and column backgrounds, parallax, grids) and Bricks Builder (every element that prints an image tag). Inside the builders' own editing screens badges are deliberately not injected. Markup a theme renders itself can be passed through `apply_filters( 'transparai_label_media', $html )`. For images a theme prints without an attachment ID (ACF URL or array fields) and for CSS backgrounds enable the option under Extras: a small script matches those images against your labeled files. The machine-readable XMP labeling is independent of any builder and always works.

= My theme puts overlays on images; does the badge disappear under them? =

Usually not, and never silently. Badges sit above typical theme layers (hover effects, zoom icons, sale badges), and a small script additionally checks the real paint order: a badge that is still covered is raised, moved to a free corner or, as the last resort, shown as a caption line below the image (this guard can be turned off under Visible badge, Extras). For manual control, give any container one of the utility classes `trai-badge-top-left`, `trai-badge-top-right`, `trai-badge-bottom-left`, `trai-badge-bottom-right`, `trai-badge-below` or `trai-badge-hidden`, or pick a position for a single image in its attachment details ("Badge position"). Both ways switch the guard off for those badges, and `trai-badge-manual` does the same without changing the position, for the rare case where the guard misjudges your layout. Hiding the visible badge of one image never touches the machine-readable file metadata or the structured data in the page; that part of the disclosure stays intact.

= Does the plugin phone home? =

No. There are no external requests of any kind. All detection happens by reading file bytes locally on your server, and the AI systems list is a file inside the plugin.

= Does the plugin process personal data? =

It records who did what inside your own WordPress: the per-file history and the site log store the user ID and display name of whoever labeled, confirmed, declared or dismissed something, the assessment and the checklist store the name and date of the last save, and a post marked as reviewed stores the reviewer's name, which is shown publicly only when you enable it. Nothing leaves the server, no cookies are set, and everything is removed on uninstall with data removal enabled. See the Privacy section.

= What happens when I uninstall the plugin? =

By default your labels stay in the database (reinstalling restores them) and metadata already written stays in the files. If you prefer a full cleanup, enable "Delete all plugin data" in the settings before uninstalling. Unlabel files first if you also want the in-file metadata removed.

= Can I label media programmatically? =

Yes. The meta key `_transparai_ai` is registered for the REST API, WP-CLI commands cover bulk work, and other plugins can call `do_action( 'transparai_mark_ai', $attachment_id, 'Generator name' )`.

= Where do I get help? =

Post in the support forum here on wordpress.org, or write to TransparAI@cms-admins.de. The plugin is built and maintained by Patrick Schlesinger (cms-admins.de).

== For developers ==
Everything below is stable API surface; hooks and options use the `transparai_` prefix, meta keys `_transparai_`. Every shape and example lives in the GitHub README: https://github.com/cmsadmins/TransparAI

**Meta:** attachment keys `_transparai_ai`, `_transparai_type`, `_transparai_source`, `_transparai_generator`, `_transparai_confidence`, `_transparai_detected`, `_transparai_human`, `_transparai_badge_pos`, `_transparai_history`, `_transparai_delivery` (REST-exposed, `upload_files`); post keys `_transparai_content_ai`, `_transparai_content_responsible`, `_transparai_content_review` (`edit_post`). **Options:** `transparai_settings`, `transparai_compliance`, `transparai_systems`, `transparai_log`.

**Hooks:** `do_action( 'transparai_mark_ai', $id, 'My Generator' )` labels media from your code, `echo apply_filters( 'transparai_label_media', $html )` badges the AI images in markup your theme renders; filters `transparai_signatures`, `transparai_detection_result`, `transparai_badge_html`, `transparai_badge_wrap_classes`, `transparai_notice_text`, `transparai_notice_html`, `transparai_chatbot_vendors`, `transparai_chatbot_notice`, `transparai_systems_registry`, `transparai_systems_notice`.

**Shortcode and blocks:** `[transparai_notice type="content|media|systems|chatbot" style="block|inline|banner|badge|modal" text="" id=""]` and the five blocks (AI Notice, AI Image Label, AI Systems Notice, AI Systems List, Chatbot AI Notice) render only what is declared. **REST** (`/wp-json/transparai/v1/`, authenticated): `GET /media`, `GET|POST /media/{id}`, `POST /media/{id}/scan`, `GET /report` with `document_hash`, `compliance` and `log`. **WP-CLI** (`wp transparai`): `scan`, `flag`, `unflag`, `human`, `status`, `score`, `content`, `assessment`, `systems`, `systems-declare`, `systems-undeclare`, `systems-visible`, `report`, `write-meta`, `verify-meta`, `verify-delivery`. **Theme control:** container classes `trai-badge-top-left` to `trai-badge-hidden` and `trai-badge-manual`, stacking via `--trai-badge-z`.

== External services ==
None. The plugin makes no request to any external service and never sends media or site data anywhere. The list of AI tools it matches installed plugins against is a file inside the plugin. The only HTTP request it can make is the optional delivery check, which fetches one image from your own site to see whether a CDN strips the declaration; it is off by default, runs only on click, and stops if the image is served from another host.

== Privacy ==
TransparAI processes media files locally on your server and stores its results in the WordPress database (post meta and a few options). The per-file history records the user ID and display name of whoever labeled, confirmed, declared or dismissed a file (last fifty events), a site log keeps the last 200 administrative events, the self-assessment and the AI literacy checklist keep the name and date of the last save, and a post marked as reviewed stores the reviewer's name and user ID, which is shown publicly only when you enable it. Everything is removed on uninstall with data removal enabled. The plugin collects, transmits and shares nothing and sets no cookies.

== Disclaimer ==
TransparAI is a technical tool, not legal advice, and is provided "as is" without warranty of any kind, to the extent permitted by law (GNU GPL v2, sections 11 and 12). The author makes no representation that using it makes your site compliant with the EU AI Act, the Digital Services Act or any other law; legal obligations depend on your situation and remain your responsibility as the site operator. The readiness score and the self-assessment reflect your own answers and the checks this plugin can perform; they are not a legal assessment. Detection is based on metadata embedded by generators: stripped files carry no signals, and detected metadata is an indication, not proof. No function is guaranteed to run without error in every environment. You use this plugin at your own risk; to the extent permitted by law, the author accepts no liability for damages arising from its use.

== Screenshots ==

1. TransparAI dashboard with the readiness score, open checks, counters and the EU AI Act timeline
2. Media library grid with AI badges, a review state and bulk labeling
3. Attachment details: label checkbox, detection evidence, review actions and the file inspection with its raw XMP packet
4. Self-assessment with the obligations that apply to the site and the Article 4 AI literacy checklist
5. AI systems in use: detected plugins, suggestions and the per-system visibility for the visitor notice
6. Front-end notice styles on an AI-written post: banner, badge and title badge
7. Settings with tabs, library statistics, the batched scan and the audit export
8. Front-end badge on a labeled image
9. Compliance report print view with the document hash

== Changelog ==

= 1.0.3 =
* AI-written text: a disclosure level per post (no AI, AI-assisted, AI-generated, AI-generated and reviewed) in the block editor sidebar, the classic meta box, Quick Edit and Bulk Edit, with a sortable list column and filter. Reviewed texts record reviewer, date and a content fingerprint that flags later edits. New "AI notice" block and `[transparai_notice]` shortcode; the note can go into excerpts and RSS feeds (dc:description).
* Camera photos and human work: media can be declared as digitalCapture or digitalCreation, in the details, in bulk or via `wp transparai human`; written into files that carry no other digital source type, shown in the structured data, optional "Human made" badge.
* WooCommerce: the badge follows variation swaps, is re-created inside the zoom and PhotoSwipe lightbox and the core image lightbox, stays out of WooCommerce e-mails; HPOS compatibility declared.
* REST API `transparai/v1` (media rows, per-file detail, actions, re-check, report), authenticated only.
* Audit trail: fifty events per file with previous state, trigger and editor name; site log; CSV export with history, BOM and formula guard; print view with document hash, guidance basis and limitations; paginated exports.
* First-run setup with three undoable steps; chatbot disclosure with local detection of more than 40 vendors and the notice as first bot message, next to the widget or in the footer.
* Structured data: Article node for AI-written posts, stable ids, digitalSourceType as Schema.org enumeration and IPTC URI.

= 1.0.2 =
* Fixed: the library scan stopped after its first batch on some sites; large PNG files with metadata after the image data were reported as clean; images from Bing Image Creator, Microsoft Designer and Copilot were only queued instead of labeled.
* The file inspection says whether the attachment is labeled at all.

= 1.0.1 =
* Fixed: the hourly integrity sweep was not scheduled on sites created in a multisite network after activation.
* Per-file history, CSV audit export, file inspection with the raw XMP packet, optional delivery check for CDNs that strip metadata, translations for German, French, Spanish, Italian and Dutch.

= 1.0.0 =
* Initial release: C2PA, XMP/IPTC, PNG-chunk, EXIF, MP4 and MP3 detection with a camera rule and a review queue; visible badge with overlay guard, per-image override and CSS utility classes; IPTC digital source type written as XMP into JPEG, PNG, WebP and AVIF with auto-repair; Schema.org JSON-LD; page-cache purging; WP-CLI; integrations for AI Engine, AI Power, Elementor AI and WordPress AI; no external requests.

== Upgrade Notice ==

= 1.0.3 =
Adds disclosure levels for AI-written text, declarations for camera photos and human work, WooCommerce variation and lightbox badges, a REST API, a fuller audit trail with print view, a first-run setup and chatbot disclosure. Existing labels and settings carry over unchanged.

= 1.0.2 =
Fixes a library scan that stopped after its first batch, detection of large PNG files whose metadata sits after the image data, and the labeling of images from Bing Image Creator, Microsoft Designer and Copilot.

= 1.0.1 =
Fixes a silent failure of the auto-repair on multisite networks, adds a per-file history with CSV audit export, file inspection in the attachment details, an optional delivery check and five translations.

= 1.0.0 =
Initial release.
