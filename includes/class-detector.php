<?php
/**
 * Detection engine: decides whether a media file declares (or strongly
 * signals) AI provenance.
 *
 * Order of evidence:
 *  1. IPTC/XMP DigitalSourceType (standard declaration)      -> certain
 *  2. C2PA manifest presence + AI claim generator            -> certain
 *     C2PA presence alone (cameras also embed C2PA!)         -> likely
 *  3. Generator signatures in real metadata blocks
 *     (PNG text chunks, EXIF, JPEG COM, XMP)                 -> likely
 *  4. ID3v2 "aigc" declaration (MP3, GB 45438)               -> certain
 *  5. Filename patterns (optional)                           -> hint
 *
 * Matching only ever runs on EXTRACTED metadata blocks, never on raw file
 * bytes, and generator names use word boundaries, both measures prevent the
 * false positives observed in competing implementations.
 *
 * @package   TransparAI
 * @author    Patrick Schlesinger
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-level AI provenance detection.
 */
final class TransparAI_Detector {

	private const DST_TERMS = array(
		'trainedalgorithmicmedia'              => array( 'generated', 'certain' ),
		'compositewithtrainedalgorithmicmedia' => array( 'composite', 'certain' ),
		'compositesynthetic'                   => array( 'composite', 'certain' ),
		'algorithmicmedia'                     => array( 'generated', 'likely' ),
	);

	/**
	 * Claim generator substrings that identify a generative-AI producer.
	 * Deliberately excludes plain editors/libraries (Adobe_Photoshop, c2pa-rs)
	 * and camera vendors, their manifests do not prove AI origin.
	 */
	private const AI_CLAIM_GENERATORS = array(
		'openai'     => 'OpenAI',
		'dall-e'     => 'DALL-E',
		'dall·e'     => 'DALL-E',
		'gpt-image'  => 'OpenAI GPT-Image',
		'chatgpt'    => 'ChatGPT',
		'firefly'    => 'Adobe Firefly',
		'gemini'     => 'Google Gemini',
		'imagen'     => 'Google Imagen',
		'google ai'  => 'Google AI',
		'googleai'   => 'Google AI',
		'stability'  => 'Stability AI',
		'midjourney' => 'Midjourney',
		'designer'   => 'Microsoft Designer',
		'leonardo'   => 'Leonardo.Ai',
		'ideogram'   => 'Ideogram',
		'recraft'    => 'Recraft',
		'krea'       => 'Krea AI',
		'seedream'   => 'Seedream',
	);

	/**
	 * Detect AI provenance for a media file.
	 *
	 * @param string $path Absolute file path.
	 * @return array{is_ai:bool, type:string, source:string, generator:string, confidence:string, evidence:string}|null
	 *         Null when no signal was found or the file is unreadable.
	 */
	public static function detect_file( string $path ): ?array {
		$head = TransparAI_Parsers::read_head( $path );
		if ( null === $head || '' === $head ) {
			return null;
		}

		$format = TransparAI_Parsers::sniff( $head );
		$result = null;

		switch ( $format ) {
			case 'jpeg':
				$result = self::detect_jpeg( $path, $head );
				break;
			case 'png':
				$result = self::detect_png( $head );
				break;
			case 'webp':
				$result = self::detect_webp( $path, $head );
				break;
			case 'bmff':
				$result = self::detect_bmff( $head );
				break;
			case 'mp3':
				$result = self::detect_mp3( $head );
				break;
		}

		if ( null === $result ) {
			$result = self::detect_sidecar( $path );
		}

		if ( null === $result && TransparAI_Options::enabled( 'filename_hints' ) ) {
			$result = self::detect_filename( $path );
		}

		/**
		 * Filter the detection result for a file.
		 *
		 * Allows third parties to add or veto detections.
		 *
		 * @param array|null $result Detection result or null.
		 * @param string     $path   Absolute file path.
		 */
		return apply_filters( 'transparai_detection_result', $result, $path );
	}

	/* ---------------------------------------------------------------------
	 * Per-container detection
	 * ------------------------------------------------------------------- */

	/**
	 * JPEG: XMP, IPTC-IIM, C2PA (APP11), EXIF, COM.
	 */
	private static function detect_jpeg( string $path, string $head ): ?array {
		$segments = TransparAI_Parsers::jpeg_segments( $head );
		if ( null === $segments ) {
			return null;
		}

		$xmp = TransparAI_Parsers::jpeg_xmp( $segments );

		$dst = self::from_dst( $xmp );
		if ( null !== $dst ) {
			return $dst;
		}

		$iim = self::from_iim( $path );
		if ( null !== $iim ) {
			return $iim;
		}

		$has_c2pa     = TransparAI_Parsers::jpeg_has_c2pa( $segments );
		$c2pa_payload = $has_c2pa ? TransparAI_Parsers::jpeg_app11_payload( $segments ) : '';
		$c2pa         = self::from_c2pa( $has_c2pa, $c2pa_payload );
		if ( null !== $c2pa && 'certain' === $c2pa['confidence'] ) {
			return $c2pa;
		}

		$blocks = array();
		if ( null !== $xmp ) {
			$blocks['xmp'] = $xmp;
		}
		$exif_fields = self::exif_fields( $path );
		if ( '' !== $exif_fields ) {
			$blocks['exif'] = $exif_fields;
		}
		foreach ( TransparAI_Parsers::jpeg_comments( $segments ) as $i => $comment ) {
			$blocks[ 'com' . $i ] = $comment;
		}

		$signature = self::from_signatures( $blocks );
		if ( null !== $signature ) {
			return $signature;
		}

		return $c2pa;
	}

	/**
	 * PNG: XMP (iTXt), C2PA (caBX), generator text chunks.
	 */
	private static function detect_png( string $head ): ?array {
		$chunks = TransparAI_Parsers::png_chunks( $head );
		if ( null === $chunks ) {
			return null;
		}

		$xmp = TransparAI_Parsers::png_xmp( $chunks );
		$dst = self::from_dst( $xmp );
		if ( null !== $dst ) {
			return $dst;
		}

		$has_c2pa = TransparAI_Parsers::png_has_c2pa( $chunks );
		$c2pa     = self::from_c2pa( $has_c2pa, $has_c2pa ? TransparAI_Parsers::png_c2pa_payload( $chunks ) : '' );
		if ( null !== $c2pa && 'certain' === $c2pa['confidence'] ) {
			return $c2pa;
		}

		$texts = TransparAI_Parsers::png_text_chunks( $chunks );

		$chunk_rule = self::from_png_text_chunks( $texts );
		if ( null !== $chunk_rule ) {
			return $chunk_rule;
		}

		$blocks = $texts;
		if ( null !== $xmp ) {
			$blocks['xmp'] = $xmp;
		}
		$signature = self::from_signatures( $blocks );
		if ( null !== $signature ) {
			return $signature;
		}

		return $c2pa;
	}

	/**
	 * WebP: XMP chunk sits at the END of the file per spec, read the tail too.
	 */
	private static function detect_webp( string $path, string $head ): ?array {
		$chunks = TransparAI_Parsers::webp_chunks( $head, true );
		if ( null === $chunks ) {
			return null;
		}

		$xmp = TransparAI_Parsers::webp_xmp( $chunks );
		if ( null === $xmp ) {
			$tail = TransparAI_Parsers::read_tail( $path );
			if ( null !== $tail ) {
				$pos = strpos( $tail, 'XMP ' );
				if ( false !== $pos && $pos + 8 <= strlen( $tail ) ) {
					$size = unpack( 'V', substr( $tail, $pos + 4, 4 ) );
					$xmp  = substr( $tail, $pos + 8, $size[1] );
				}
			}
		}

		$dst = self::from_dst( $xmp );
		if ( null !== $dst ) {
			return $dst;
		}

		$has_c2pa     = TransparAI_Parsers::webp_has_c2pa( $chunks );
		$c2pa_payload = '';
		if ( $has_c2pa ) {
			foreach ( $chunks as $chunk ) {
				if ( 'C2PA' === $chunk['fourcc'] ) {
					$c2pa_payload = $chunk['data'];
					break;
				}
			}
		}
		$c2pa = self::from_c2pa( $has_c2pa, $c2pa_payload );
		if ( null !== $c2pa && 'certain' === $c2pa['confidence'] ) {
			return $c2pa;
		}

		$blocks = array();
		if ( null !== $xmp ) {
			$blocks['xmp'] = $xmp;
		}
		$exif_raw = TransparAI_Parsers::webp_exif_raw( $chunks );
		if ( null !== $exif_raw ) {
			$blocks['exif'] = $exif_raw;
		}
		$signature = self::from_signatures( $blocks );
		if ( null !== $signature ) {
			return $signature;
		}

		return $c2pa;
	}

	/**
	 * ISO-BMFF (MP4/MOV/M4A/HEIC/AVIF): C2PA uuid box, XMP uuid box.
	 */
	private static function detect_bmff( string $head ): ?array {
		$scan = TransparAI_Parsers::bmff_scan( $head );

		$xmp = $scan['xmp'] ? TransparAI_Parsers::bmff_xmp( $head ) : null;
		$dst = self::from_dst( $xmp );
		if ( null !== $dst ) {
			return $dst;
		}

		if ( $scan['c2pa'] ) {
			return self::from_c2pa( true, $head );
		}

		if ( null !== $xmp ) {
			return self::from_signatures( array( 'xmp' => $xmp ) );
		}

		return null;
	}

	/**
	 * MP3: ID3v2 TXXX "aigc" (GB 45438), GEOB (C2PA), PRIV (XMP).
	 */
	private static function detect_mp3( string $head ): ?array {
		$frames = TransparAI_Parsers::id3_frames( $head );
		if ( array() === $frames ) {
			return null;
		}

		$xmp      = null;
		$has_c2pa = false;
		foreach ( $frames as $frame ) {
			if ( 'TXXX' === $frame['id'] && 'aigc' === strtolower( TransparAI_Parsers::id3_txxx_description( $frame['data'] ) ) ) {
				return self::result( 'generated', 'id3', '', 'certain', 'ID3v2 TXXX frame "aigc" (GB 45438 AIGC declaration)' );
			}
			if ( 'GEOB' === $frame['id'] && ( str_contains( $frame['data'], 'c2pa' ) || str_contains( $frame['data'], 'jumb' ) ) ) {
				$has_c2pa = true;
			}
			if ( 'PRIV' === $frame['id'] && str_contains( $frame['data'], 'xmpmeta' ) ) {
				$xmp = $frame['data'];
			}
		}

		$dst = self::from_dst( $xmp );
		if ( null !== $dst ) {
			return $dst;
		}

		return self::from_c2pa( $has_c2pa, '' );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------- */

	/**
	 * Rule: declared IPTC DigitalSourceType in an XMP packet.
	 */
	private static function from_dst( ?string $xmp ): ?array {
		if ( null === $xmp || '' === $xmp ) {
			return null;
		}
		$known = self::known_dst_term( TransparAI_Parsers::xmp_digital_source_types( $xmp ) );
		if ( null === $known ) {
			return null;
		}
		list( $type, $confidence, $term ) = $known;
		return self::result( $type, 'xmp-dst', self::generator_from_blocks( array( 'xmp' => $xmp ) ), $confidence, 'XMP DigitalSourceType: ' . $term );
	}

	/**
	 * First term of the IPTC vocabulary in a list, with its classification.
	 *
	 * The single place that turns a DigitalSourceType term into a content type
	 * and a confidence, no matter which container declared it.
	 *
	 * @param string[] $terms Lowercase vocabulary terms.
	 * @return array{0:string, 1:string, 2:string}|null type, confidence, term.
	 */
	private static function known_dst_term( array $terms ): ?array {
		foreach ( $terms as $term ) {
			if ( isset( self::DST_TERMS[ $term ] ) ) {
				return array( self::DST_TERMS[ $term ][0], self::DST_TERMS[ $term ][1], $term );
			}
		}
		return null;
	}

	/**
	 * Rule: IPTC-IIM datasets (JPEG APP13), Google credit or a dST token.
	 */
	private static function from_iim( string $path ): ?array {
		if ( ! function_exists( 'iptcparse' ) ) {
			return null;
		}
		$info = array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize warns on non-images; a null result is handled.
		@getimagesize( $path, $info );
		if ( empty( $info['APP13'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt IRB data must not raise a warning.
		$iptc = @iptcparse( $info['APP13'] );
		if ( ! is_array( $iptc ) ) {
			return null;
		}

		$credit = isset( $iptc['2#110'][0] ) ? (string) $iptc['2#110'][0] : '';
		if ( false !== stripos( $credit, 'Made with Google AI' ) ) {
			return self::result( 'generated', 'iim', 'Google AI', 'certain', 'IPTC-IIM Credit: ' . $credit );
		}

		$instructions = isset( $iptc['2#040'][0] ) ? (string) $iptc['2#040'][0] : '';
		if ( '' !== $instructions ) {
			/*
			 * Free text, so the terms overlap as substrings:
			 * compositeWithTrainedAlgorithmicMedia contains
			 * trainedAlgorithmicMedia, which in turn contains
			 * algorithmicMedia. Matching the longest term first is what makes
			 * the result independent of the table's order.
			 */
			$terms = array_keys( self::DST_TERMS );
			usort(
				$terms,
				static function ( string $a, string $b ): int {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			foreach ( $terms as $term ) {
				if ( false !== stripos( $instructions, $term ) ) {
					list( $type, $confidence ) = self::DST_TERMS[ $term ];
					return self::result( $type, 'iim', '', $confidence, 'IPTC-IIM SpecialInstructions dST token: ' . $term );
				}
			}
		}

		return null;
	}

	/**
	 * Rule: C2PA manifest presence.
	 *
	 * Camera rule: cameras (Leica, Sony, Nikon) embed Content Credentials in
	 * REAL photos, presence alone is only 'likely'. Only an AI claim
	 * generator upgrades to 'certain'.
	 */
	private static function from_c2pa( bool $present, string $payload ): ?array {
		if ( ! $present ) {
			return null;
		}
		$claim = '' !== $payload ? TransparAI_Parsers::c2pa_claim_generator( $payload ) : '';

		/*
		 * C2PA 2.x declares the digital source type inside the manifest's
		 * actions assertion. An explicit declaration outranks the camera rule,
		 * and the vocabulary table decides what the term is worth, exactly as
		 * on the XMP path.
		 */
		if ( '' !== $payload ) {
			$declared = self::known_dst_term( TransparAI_Parsers::c2pa_digital_source_types( $payload ) );
			if ( null !== $declared ) {
				list( $type, $confidence, $term ) = $declared;
				return self::result( $type, 'c2pa', $claim, $confidence, 'C2PA manifest declares DigitalSourceType: ' . $term . ( '' !== $claim ? ' (claim generator: ' . $claim . ')' : '' ) );
			}
		}

		if ( '' !== $claim ) {
			$lower = strtolower( $claim );
			foreach ( self::AI_CLAIM_GENERATORS as $needle => $label ) {
				if ( str_contains( $lower, $needle ) ) {
					return self::result( 'generated', 'c2pa', $label, 'certain', 'C2PA claim generator: ' . $claim );
				}
			}
		}
		$evidence = 'C2PA/Content Credentials manifest present' . ( '' !== $claim ? ' (claim generator: ' . $claim . ')' : '' ) . ', could also stem from a camera or editor';
		return self::result( 'generated', 'c2pa', $claim, 'likely', $evidence );
	}

	/**
	 * Rule: structured PNG text chunks written by local generators.
	 *
	 * @param array<string, string> $texts keyword => text.
	 */
	private static function from_png_text_chunks( array $texts ): ?array {
		$keys = array_change_key_case( $texts, CASE_LOWER );

		if ( isset( $keys['parameters'] ) ) {
			$params = $keys['parameters'];
			if ( str_contains( $params, 'sui_image_params' ) ) {
				return self::result( 'generated', 'png-chunk', 'SwarmUI', 'likely', self::excerpt( 'PNG chunk "parameters": ', $params ) );
			}
			if ( false !== stripos( $params, 'Steps:' ) ) {
				$type = false !== stripos( $params, 'Denoising strength' ) ? 'composite' : 'generated';
				return self::result( $type, 'png-chunk', 'Stable Diffusion (A1111/Forge)', 'likely', self::excerpt( 'PNG chunk "parameters": ', $params ) );
			}
		}

		if ( isset( $keys['prompt'], $keys['workflow'] ) ) {
			return self::result( 'generated', 'png-chunk', 'ComfyUI', 'likely', self::excerpt( 'PNG chunks "prompt"+"workflow": ', $keys['prompt'] ) );
		}

		if ( isset( $keys['software'] ) && false !== stripos( $keys['software'], 'NovelAI' ) ) {
			return self::result( 'generated', 'png-chunk', 'NovelAI', 'likely', 'PNG chunk "Software": NovelAI' );
		}

		foreach ( array( 'invokeai_metadata', 'sd-metadata', 'invokeai', 'dream' ) as $key ) {
			if ( isset( $keys[ $key ] ) ) {
				return self::result( 'generated', 'png-chunk', 'InvokeAI', 'likely', self::excerpt( 'PNG chunk "' . $key . '": ', $keys[ $key ] ) );
			}
		}

		if ( isset( $keys['fooocus_scheme'] ) ) {
			return self::result( 'generated', 'png-chunk', 'Fooocus', 'likely', 'PNG chunk "fooocus_scheme"' );
		}

		return null;
	}

	/**
	 * Rule: curated generator signatures on extracted metadata blocks.
	 *
	 * @param array<string, string> $blocks Named metadata text blocks.
	 */
	private static function from_signatures( array $blocks ): ?array {
		if ( array() === $blocks ) {
			return null;
		}

		foreach ( $blocks as $name => $block ) {
			foreach ( self::signatures() as $signature ) {
				if ( preg_match( $signature['pattern'], $block, $match ) ) {
					$evidence = self::excerpt( ucfirst( preg_replace( '/\d+$/', '', $name ) ) . ' metadata: ', $match[0] . ' …' );
					$type     = $signature['type'] ?? 'generated';
					return self::result( $type, self::block_source( $name ), $signature['generator'], 'likely', $evidence );
				}
			}
		}

		return null;
	}

	/**
	 * Rule: `.c2pa` sidecar file next to the media file.
	 */
	private static function detect_sidecar( string $path ): ?array {
		if ( file_exists( $path . '.c2pa' ) ) {
			return self::result( 'generated', 'sidecar', '', 'likely', 'C2PA sidecar file present (' . basename( $path ) . '.c2pa)' );
		}
		return null;
	}

	/**
	 * Rule (opt-in): filename patterns. Suggestions only, never auto-flag.
	 */
	private static function detect_filename( string $path ): ?array {
		$name     = strtolower( basename( $path ) );
		$patterns = array(
			'/(?<![a-z])dall[-·_ ]?e(?![a-z])/'          => 'DALL-E',
			'/gemini_generated_image/'                   => 'Google Gemini',
			'/^generated[-_ ]image/'                     => 'Google Gemini',
			'/comfyui_\d+/'                              => 'ComfyUI',
			'/(?<![a-z])firefly[-_]/'                    => 'Adobe Firefly',
			'/^leonardo_/'                               => 'Leonardo.Ai',
			'/^ideogram[-_]/'                            => 'Ideogram',
			'/^grok[-_]image/'                           => 'Grok',
			'/(?<![a-z])midjourney(?![a-z])/'            => 'Midjourney',
			'/^u\d+_[a-z0-9_]+_[0-9a-f]{8}-[0-9a-f]{4}/' => 'Midjourney',
		);
		foreach ( $patterns as $pattern => $generator ) {
			if ( preg_match( $pattern, $name ) ) {
				return self::result( 'generated', 'filename', $generator, 'hint', 'Filename pattern: ' . basename( $path ) );
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Curated generator signature list (word-boundary regexes).
	 *
	 * Lessons encoded: never bare "firefly" (Mozilla/Firefly Aerospace),
	 * never bare "imagen" (Spanish "imagen(es)"), no "bing" (files never say so).
	 *
	 * @return array<int, array{pattern:string, generator:string, type?:string}>
	 */
	private static function signatures(): array {
		$signatures = array(
			array(
				'pattern'   => '/(?<![a-z])stable[- _]?diffusion(?![a-z])/i',
				'generator' => 'Stable Diffusion',
			),
			array(
				'pattern'   => '/(?<![a-z])comfyui(?![a-z])/i',
				'generator' => 'ComfyUI',
			),
			array(
				'pattern'   => '/(?<![a-z])automatic1111(?![a-z])/i',
				'generator' => 'Stable Diffusion (A1111)',
			),
			array(
				'pattern'   => '/(?<![a-z])midjourney(?![a-z])/i',
				'generator' => 'Midjourney',
			),
			array(
				'pattern'   => '/ --(?:ar|v|stylize|chaos|weird) .{0,200}job id:/is',
				'generator' => 'Midjourney',
			),
			array(
				'pattern'   => '/(?<![a-z])dall[-·]e(?![a-z])/i',
				'generator' => 'DALL-E',
			),
			array(
				'pattern'   => '/(?<![a-z])gpt-image(?![a-z])/i',
				'generator' => 'OpenAI GPT-Image',
			),
			array(
				'pattern'   => '/adobe firefly/i',
				'generator' => 'Adobe Firefly',
			),
			array(
				'pattern'   => '/generative (?:fill|expand)/i',
				'generator' => 'Adobe Photoshop (Generative Fill)',
				'type'      => 'composite',
			),
			array(
				'pattern'   => '/(?<![a-z])novelai(?![a-z])/i',
				'generator' => 'NovelAI',
			),
			array(
				'pattern'   => '/(?<![a-z])invokeai(?![a-z])/i',
				'generator' => 'InvokeAI',
			),
			array(
				'pattern'   => '/leonardo\.ai/i',
				'generator' => 'Leonardo.Ai',
			),
			array(
				'pattern'   => '/(?<![a-z])ideogram(?![a-z])/i',
				'generator' => 'Ideogram',
			),
			array(
				'pattern'   => '/(?<![a-z])recraft(?![a-z])/i',
				'generator' => 'Recraft',
			),
			array(
				'pattern'   => '/flux\.1|black forest labs/i',
				'generator' => 'Flux (Black Forest Labs)',
			),
			array(
				'pattern'   => '/(?<![a-z])seedream(?![a-z])/i',
				'generator' => 'Seedream',
			),
			array(
				'pattern'   => '/made with google ai/i',
				'generator' => 'Google AI',
			),
		);

		/**
		 * Filter the generator signature list.
		 *
		 * @param array $signatures Each: pattern (regex), generator (label), type (optional).
		 */
		return (array) apply_filters( 'transparai_signatures', $signatures );
	}

	/**
	 * EXIF text fields via exif_read_data (JPEG/TIFF only), concatenated.
	 */
	private static function exif_fields( string $path ): string {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- exif_read_data warns on unsupported files; a false result is handled.
		$exif = @exif_read_data( $path, 'IFD0,EXIF,COMMENT', true );
		if ( ! is_array( $exif ) ) {
			return '';
		}
		$parts = array();
		foreach ( $exif as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( array( 'Software', 'Artist', 'ImageDescription', 'UserComment', 'Model', 'Make', 'Comment' ) as $field ) {
				if ( isset( $section[ $field ] ) && is_string( $section[ $field ] ) ) {
					$parts[] = $section[ $field ];
				}
				if ( isset( $section[ $field ] ) && is_array( $section[ $field ] ) ) {
					$parts[] = implode( "\n", array_filter( $section[ $field ], 'is_string' ) );
				}
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * Try to name the generator from metadata blocks (used to enrich dST hits).
	 *
	 * @param array<string, string> $blocks Named metadata text blocks.
	 */
	private static function generator_from_blocks( array $blocks ): string {
		foreach ( $blocks as $block ) {
			foreach ( self::signatures() as $signature ) {
				if ( preg_match( $signature['pattern'], $block ) ) {
					return $signature['generator'];
				}
			}
		}
		return '';
	}

	/**
	 * Map an internal block name to a result source key.
	 */
	private static function block_source( string $name ): string {
		if ( str_starts_with( $name, 'com' ) ) {
			return 'com';
		}
		if ( 'exif' === $name ) {
			return 'exif';
		}
		if ( 'xmp' === $name ) {
			return 'xmp';
		}
		return 'png-chunk';
	}

	/**
	 * Build a result array.
	 */
	private static function result( string $type, string $source, string $generator, string $confidence, string $evidence ): array {
		return array(
			'is_ai'      => true,
			'type'       => $type,
			'source'     => $source,
			'generator'  => $generator,
			'confidence' => $confidence,
			'evidence'   => mb_substr( $evidence, 0, 500 ),
		);
	}

	/**
	 * Short printable excerpt for the evidence field.
	 */
	private static function excerpt( string $prefix, string $text ): string {
		$text = preg_replace( '/[^\P{C}\n\t]/u', '', $text );
		$text = trim( (string) $text );
		return $prefix . mb_substr( $text, 0, 200 );
	}
}
