<?php
/**
 * Fixture generator: builds synthetic media files carrying real AI provenance
 * signatures (and negative probes) into tests/fixtures/generated/.
 *
 * Standalone script — no WordPress, no extensions beyond zlib. Run:
 *   php tests/fixtures/make-fixtures.php
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

$out_dir = __DIR__ . '/generated';
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create {$out_dir}\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Base files (1x1 pixels, base64-embedded)
 * ------------------------------------------------------------------------- */

$base_png = base64_decode(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);

$base_jpeg = base64_decode(
	'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q=='
);

$base_webp = base64_decode( 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==' );

if ( false === $base_png || false === $base_jpeg || false === $base_webp ) {
	fwrite( STDERR, "Base image decode failed\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Byte helpers
 * ------------------------------------------------------------------------- */

/** Append a PNG chunk before IEND. */
function png_add_chunk( string $png, string $type, string $data ): string {
	$chunk    = pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	$iend_pos = strrpos( $png, 'IEND' );
	$insert   = $iend_pos - 4; // Before the IEND length field.
	return substr( $png, 0, $insert ) . $chunk . substr( $png, $insert );
}

/** Insert a JPEG segment right after SOI. */
function jpeg_add_segment( string $jpeg, int $marker, string $payload ): string {
	$segment = chr( 0xFF ) . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	return substr( $jpeg, 0, 2 ) . $segment . substr( $jpeg, 2 );
}

/** Append a RIFF chunk to a WebP file (updates the RIFF size). */
function webp_add_chunk( string $webp, string $fourcc, string $data ): string {
	$chunk = $fourcc . pack( 'V', strlen( $data ) ) . $data;
	if ( strlen( $data ) % 2 ) {
		$chunk .= "\x00";
	}
	$out = $webp . $chunk;
	return substr_replace( $out, pack( 'V', strlen( $out ) - 8 ), 4, 4 );
}

/** XMP packet with a DigitalSourceType in the requested syntax form. */
function xmp_packet( string $term, string $form = 'element' ): string {
	$uri = 'http://cv.iptc.org/newscodes/digitalsourcetype/' . $term;
	switch ( $form ) {
		case 'attribute':
			$description = '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" Iptc4xmpExt:DigitalSourceType="' . $uri . '"/>';
			break;
		case 'li':
			$description = '<rdf:Description rdf:about="" xmlns:iptcExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
				. '<iptcExt:DigitalSourceType><rdf:Bag><rdf:li>' . $uri . '</rdf:li></rdf:Bag></iptcExt:DigitalSourceType>'
				. '</rdf:Description>';
			break;
		default:
			$description = '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
				. '<Iptc4xmpExt:DigitalSourceType>' . $uri . '</Iptc4xmpExt:DigitalSourceType>'
				. '</rdf:Description>';
	}
	return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
		. '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Fixture Generator">'
		. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
		. $description
		. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}

/** iTXt payload carrying an XMP packet. */
function itxt_xmp( string $xmp ): string {
	return "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . $xmp;
}

/** Fake-but-shaped JUMBF/C2PA payload with a claim generator string. */
function c2pa_payload( string $claim_generator ): string {
	$manifest = "\x00\x00\x00\x28jumb\x00\x00\x00\x20jumdc2pa\x00\x11\x00\x10"
		. 'c2pa.manifest'
		. "\x78claim_generator\x78" . chr( strlen( $claim_generator ) ) . $claim_generator
		. "\x00" . 'urn:c2pa:fixture';
	return $manifest;
}

/** Minimal APP13 payload (Photoshop IRB) with the given IIM datasets. */
function app13_payload( string $iim ): string {
	$resource = '8BIM' . "\x04\x04" . "\x00\x00" . pack( 'N', strlen( $iim ) ) . $iim;
	if ( strlen( $iim ) % 2 ) {
		$resource .= "\x00";
	}
	return "Photoshop 3.0\x00" . $resource;
}

/** One IIM dataset. */
function iim_dataset( int $record, int $dataset, string $value ): string {
	return "\x1C" . chr( $record ) . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;
}

$write = static function ( string $name, string $data ) use ( $out_dir ): void {
	if ( false === file_put_contents( $out_dir . '/' . $name, $data ) ) {
		fwrite( STDERR, "Write failed: {$name}\n" );
		exit( 1 );
	}
	echo "  {$name} (" . strlen( $data ) . " bytes)\n";
};

echo "Generating fixtures into tests/fixtures/generated/\n";

/* ---------------------------------------------------------------------------
 * Positive fixtures
 * ------------------------------------------------------------------------- */

// Plain bases (negative probes + writer targets).
$write( 'base.png', $base_png );
$write( 'base.jpg', $base_jpeg );
$write( 'base.webp', $base_webp );

// PNG: Stable Diffusion A1111 parameters chunk (tEXt).
$a1111 = "parameters\x00" . 'masterpiece, best quality' . "\n"
	. 'Negative prompt: lowres' . "\n"
	. 'Steps: 28, Sampler: DPM++ 2M Karras, CFG scale: 7, Seed: 1234567890, Size: 512x512, Model: sd_xl_base_1.0';
$write( 'a1111.png', png_add_chunk( $base_png, 'tEXt', $a1111 ) );

// PNG: A1111 img2img (Denoising strength -> composite).
$write( 'a1111-img2img.png', png_add_chunk( $base_png, 'tEXt', $a1111 . ', Denoising strength: 0.55' ) );

// PNG: ComfyUI prompt + workflow chunks.
$png = png_add_chunk( $base_png, 'tEXt', "prompt\x00" . '{"3":{"class_type":"KSampler","inputs":{"seed":42}}}' );
$png = png_add_chunk( $png, 'tEXt', "workflow\x00" . '{"nodes":[{"type":"KSampler"}]}' );
$write( 'comfyui.png', $png );

// PNG: NovelAI (Software chunk + zTXt comment).
$png = png_add_chunk( $base_png, 'tEXt', "Software\x00NovelAI" );
$png = png_add_chunk( $png, 'zTXt', "Comment\x00\x00" . gzcompress( '{"prompt":"1girl","steps":28}' ) );
$write( 'novelai.png', $png );

// PNG: XMP iTXt with trainedAlgorithmicMedia (element form).
$write( 'xmp-dst.png', png_add_chunk( $base_png, 'iTXt', itxt_xmp( xmp_packet( 'trainedAlgorithmicMedia' ) ) ) );

// PNG: C2PA caBX with an OpenAI claim generator -> certain.
$write( 'c2pa-openai.png', png_add_chunk( $base_png, 'caBX', c2pa_payload( 'OpenAI-API c2pa-rs/0.31.3' ) ) );

// PNG: camera-written C2PA (Leica) -> likely only.
$write( 'c2pa-camera.png', png_add_chunk( $base_png, 'caBX', c2pa_payload( 'Leica_Camera_AG FOTOS/2.1' ) ) );

// PNG: negative-list dST (digitalCapture) -> must NOT match.
$write( 'dst-negative.png', png_add_chunk( $base_png, 'iTXt', itxt_xmp( xmp_packet( 'digitalCapture' ) ) ) );

// JPEG: XMP APP1 attribute form (composite).
$write(
	'xmp-attr.jpg',
	jpeg_add_segment( $base_jpeg, 0xE1, "http://ns.adobe.com/xap/1.0/\x00" . xmp_packet( 'compositeWithTrainedAlgorithmicMedia', 'attribute' ) )
);

// JPEG: XMP APP1 rdf:li list form with a foreign namespace prefix.
$write(
	'xmp-li.jpg',
	jpeg_add_segment( $base_jpeg, 0xE1, "http://ns.adobe.com/xap/1.0/\x00" . xmp_packet( 'trainedAlgorithmicMedia', 'li' ) )
);

// JPEG: XMP mentioning Midjourney parameters (signature, likely).
$midjourney_xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
	. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
	. '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/">'
	. '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">a cat astronaut --ar 16:9 --v 6.1 Job ID: 5c3f9d1e-1111-2222-3333-444455556666</rdf:li></rdf:Alt></dc:description>'
	. '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
$write( 'midjourney.jpg', jpeg_add_segment( $base_jpeg, 0xE1, "http://ns.adobe.com/xap/1.0/\x00" . $midjourney_xmp ) );

// JPEG: APP13 IIM with the Google credit -> certain.
$write(
	'iim-google.jpg',
	jpeg_add_segment( $base_jpeg, 0xED, app13_payload( iim_dataset( 2, 0, "\x00\x04" ) . iim_dataset( 2, 110, 'Made with Google AI' ) ) )
);

// JPEG: APP11 C2PA/JUMBF marker.
$write( 'c2pa.jpg', jpeg_add_segment( $base_jpeg, 0xEB, 'JP' . c2pa_payload( 'Adobe_Firefly c2pa-rs/0.28' ) ) );

// JPEG: COM segment naming ComfyUI (signature via comment block).
$write( 'com-comfyui.jpg', jpeg_add_segment( $base_jpeg, 0xFE, 'Exported from ComfyUI workflow 42' ) );

// WebP: XMP chunk (spec places it at the end of the file) with dST.
$write( 'xmp.webp', webp_add_chunk( $base_webp, 'XMP ', xmp_packet( 'trainedAlgorithmicMedia' ) ) );

// MP4/ISO-BMFF: ftyp + C2PA uuid box.
$c2pa_uuid = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";
$ftyp      = pack( 'N', 24 ) . 'ftypisom' . pack( 'N', 512 ) . 'isomiso2';
$uuid_body = $c2pa_uuid . c2pa_payload( 'GoogleAI Veo/3' );
$uuid_box  = pack( 'N', 8 + strlen( $uuid_body ) ) . 'uuid' . $uuid_body;
$write( 'c2pa.mp4', $ftyp . $uuid_box . pack( 'N', 8 ) . 'mdat' );

// MP3: ID3v2.3 with a TXXX "aigc" frame (GB 45438).
$txxx_data  = "\x00" . "aigc\x00" . '{"label":"1"}';
$txxx_frame = 'TXXX' . pack( 'N', strlen( $txxx_data ) ) . "\x00\x00" . $txxx_data;
$tag_size   = strlen( $txxx_frame );
$syncsafe   = chr( ( $tag_size >> 21 ) & 0x7F ) . chr( ( $tag_size >> 14 ) & 0x7F ) . chr( ( $tag_size >> 7 ) & 0x7F ) . chr( $tag_size & 0x7F );
$write( 'aigc.mp3', 'ID3' . "\x03\x00\x00" . $syncsafe . $txxx_frame . "\xFF\xFB\x90\x00" . str_repeat( "\x00", 32 ) );

// Sidecar: plain JPEG accompanied by a .c2pa file.
$write( 'sidecar.jpg', $base_jpeg );
$write( 'sidecar.jpg.c2pa', c2pa_payload( 'c2patool/0.9' ) );

/* ---------------------------------------------------------------------------
 * Negative fixtures (false-positive probes)
 * ------------------------------------------------------------------------- */

// Spanish text containing "imagenes" in a COM segment — must NOT match ("Imagen" trap).
$write( 'negative-imagenes.jpg', jpeg_add_segment( $base_jpeg, 0xFE, 'Todas las imagenes de la galeria fueron tomadas en Sevilla.' ) );

// Camera EXIF-ish XMP (CreatorTool) without any AI declaration.
$camera_xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
	. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
	. '<rdf:Description xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmp:CreatorTool="Adobe Lightroom 8.0 (Macintosh)"/>'
	. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
$write( 'negative-camera.jpg', jpeg_add_segment( $base_jpeg, 0xE1, "http://ns.adobe.com/xap/1.0/\x00" . $camera_xmp ) );

// A "firefly" that is not Adobe Firefly (word-boundary probe).
$write( 'negative-firefly.jpg', jpeg_add_segment( $base_jpeg, 0xFE, 'Shot during the firefly festival, edited in Firefly Aerospace HQ.' ) );

echo "Done.\n";
