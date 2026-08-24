<?php
/**
 * AI metadata generation: prompts the AI Client API to analyze images.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate AI search metadata for an image attachment.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array|WP_Error Structured metadata array on success, WP_Error on failure.
 */
function ai_media_search_generate_metadata( $attachment_id ) {
	$file_path = get_attached_file( $attachment_id );

	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return new WP_Error(
			'ai_media_search_file_missing',
			__( 'Attachment file not found.', 'ai-media-search' )
		);
	}

	$mime_type = get_post_mime_type( $attachment_id );

	$analysis_file = ai_media_search_get_analysis_file( $attachment_id, $file_path, $mime_type );
	$file_path     = $analysis_file['path'];
	$mime_type     = $analysis_file['mime_type'];

	$default_prompt = 'Analyze this image and provide: '
		. '1) A detailed description suitable for search (2-3 sentences covering the main subject, setting, colors, mood, and any actions or text visible). '
		. '2) A comma-separated list of 15-25 search tags covering objects, people, animals, colors, concepts, emotions, settings, and styles present in the image. '
		. 'Return JSON with keys "description" and "tags".';

	/**
	 * Filters the AI prompt used for image analysis.
	 *
	 * @param string $prompt        The prompt text.
	 * @param int    $attachment_id Attachment post ID.
	 */
	$prompt = (string) apply_filters( 'ai_media_search_prompt', $default_prompt, $attachment_id );

	// Appended after the filter so a custom prompt is localized too. Writing the
	// language into the default prompt instead would mean any site that replaces
	// the prompt silently goes back to English descriptions, which is the bug
	// this is here to fix. Sites that want a different language, or none of this
	// at all, have `ai_media_search_language` for that.
	$prompt .= ai_media_search_get_language_instruction( $attachment_id );

	$result = ai_media_search_prompt_image( $prompt, $file_path, $mime_type, $attachment_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$decoded = json_decode( $result, true );

	if ( ! is_array( $decoded ) || empty( $decoded['description'] ) || ! isset( $decoded['tags'] ) ) {
		return new WP_Error(
			'ai_media_search_invalid_response',
			__( 'AI returned an invalid response structure.', 'ai-media-search' )
		);
	}

	$mime       = get_post_mime_type( $attachment_id );
	$media_type = $mime ? strtok( $mime, '/' ) : 'image';

	return array(
		'description'  => sanitize_text_field( $decoded['description'] ),
		'tags'         => sanitize_text_field( $decoded['tags'] ),
		'generated_at' => time(),
		'version'      => 1,
		'media_type'   => $media_type,
	);
}

/**
 * Build the sentence that tells the AI which language to answer in.
 *
 * The values are localized, the keys are not: a translated `description` key
 * would not decode into the shape the rest of the plugin expects, so the
 * instruction says so explicitly rather than trusting the model to infer it.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string Instruction to append to the prompt, with a leading space.
 */
function ai_media_search_get_language_instruction( $attachment_id ) {
	$language = ai_media_search_get_language( $attachment_id );

	return sprintf(
		' Write the description and the tags in %s.'
		. ' Return the JSON keys "description" and "tags" in English exactly as written here; translate only their values.',
		$language
	);
}

/**
 * Get the language the AI should write the description and tags in.
 *
 * The site language is used, not the language of whoever happens to be logged
 * in. `determine_locale()` would prefer the current user's admin language, and
 * the media library is a single shared store: two editors with different admin
 * languages would otherwise fill it with a mix of both, and most generation runs
 * from cron or WP-CLI where there is no current user at all. `get_locale()`
 * gives the same answer in every one of those contexts.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string Language name in English, never empty.
 */
function ai_media_search_get_language( $attachment_id ) {
	$locale = get_locale();

	/**
	 * Filters the language the AI is asked to write the description and tags in.
	 *
	 * The value is dropped straight into the prompt, so it should be a language
	 * name a model will recognize, written in English: `French`, `Brazilian
	 * Portuguese`, `Simplified Chinese`. Useful on a multilingual site that wants
	 * its media metadata in one language regardless of the admin language, or on
	 * a site that wants English descriptions whatever the site language is.
	 *
	 * An empty value falls back to English rather than asking for a description
	 * "in ", which no model can do anything useful with.
	 *
	 * @param string $language      Language name in English, derived from $locale.
	 * @param string $locale        Site locale, as returned by get_locale().
	 * @param int    $attachment_id Attachment post ID.
	 */
	$language = apply_filters( 'ai_media_search_language', ai_media_search_language_from_locale( $locale ), $locale, $attachment_id );

	$language = trim( (string) $language );

	return '' === $language ? 'English' : $language;
}

/**
 * Map a WordPress locale to a language name a model will understand.
 *
 * A raw locale is not a good thing to put in a prompt. `de_DE` is readable
 * enough, but `bel`, `pt_PT_ao90` and `nl_NL_formal` are not, and a model asked
 * to answer "in fa_IR" is being invited to guess. A language name in English is
 * unambiguous, so the locale is translated to one here.
 *
 * The names are kept in this file rather than read from WordPress. The only
 * list core has is `wp_get_available_translations()`, which lives in an admin
 * include and calls out to api.wordpress.org; putting an HTTP request in the
 * middle of image processing to look up the word "German" is not a trade worth
 * making. The table below is a few hundred bytes and cannot fail.
 *
 * Anything not listed falls back to English, which produces metadata in the
 * wrong language at worst. Passing an unrecognized locale through to the prompt
 * instead risks the model inventing a language or ignoring the instruction.
 *
 * @param string $locale WordPress locale, such as `de_DE` or `pt_BR`.
 * @return string Language name in English. Defaults to `English`.
 */
function ai_media_search_language_from_locale( $locale ) {
	$locale = str_replace( '-', '_', trim( (string) $locale ) );

	/*
	 * Locales where the region changes the written language, so the full locale
	 * has to be matched before falling back to the base language below.
	 */
	$regional = array(
		'pt_BR' => 'Brazilian Portuguese',
		'zh_CN' => 'Simplified Chinese',
		'zh_SG' => 'Simplified Chinese',
		'zh_HK' => 'Traditional Chinese',
		'zh_TW' => 'Traditional Chinese',
	);

	// Match on language and region only, so `nl_NL_formal` matches `nl_NL`.
	$parts  = explode( '_', $locale );
	$region = isset( $parts[1] ) ? $parts[0] . '_' . $parts[1] : $locale;

	if ( isset( $regional[ $region ] ) ) {
		return $regional[ $region ];
	}

	/*
	 * Base language subtags, keyed the way WordPress writes them: mostly ISO
	 * 639-1, with the three letter codes core uses where no two letter code
	 * exists. Ordered alphabetically to keep additions easy.
	 */
	$languages = array(
		'af'  => 'Afrikaans',
		'am'  => 'Amharic',
		'ar'  => 'Arabic',
		'ary' => 'Moroccan Arabic',
		'as'  => 'Assamese',
		'az'  => 'Azerbaijani',
		'bel' => 'Belarusian',
		'bg'  => 'Bulgarian',
		'bn'  => 'Bengali',
		'bo'  => 'Tibetan',
		'bre' => 'Breton',
		'bs'  => 'Bosnian',
		'ca'  => 'Catalan',
		'ceb' => 'Cebuano',
		'ckb' => 'Central Kurdish',
		'cs'  => 'Czech',
		'cy'  => 'Welsh',
		'da'  => 'Danish',
		'de'  => 'German',
		'dzo' => 'Dzongkha',
		'el'  => 'Greek',
		'en'  => 'English',
		'eo'  => 'Esperanto',
		'es'  => 'Spanish',
		'et'  => 'Estonian',
		'eu'  => 'Basque',
		'fa'  => 'Persian',
		'fi'  => 'Finnish',
		'fr'  => 'French',
		'fur' => 'Friulian',
		'ga'  => 'Irish',
		'gd'  => 'Scottish Gaelic',
		'gl'  => 'Galician',
		'gu'  => 'Gujarati',
		'haz' => 'Hazaragi',
		'he'  => 'Hebrew',
		'hi'  => 'Hindi',
		'hr'  => 'Croatian',
		'hu'  => 'Hungarian',
		'hy'  => 'Armenian',
		'id'  => 'Indonesian',
		'is'  => 'Icelandic',
		'it'  => 'Italian',
		'ja'  => 'Japanese',
		'jv'  => 'Javanese',
		'ka'  => 'Georgian',
		'kab' => 'Kabyle',
		'kk'  => 'Kazakh',
		'km'  => 'Khmer',
		'kn'  => 'Kannada',
		'ko'  => 'Korean',
		'ku'  => 'Kurdish',
		'ky'  => 'Kyrgyz',
		'lo'  => 'Lao',
		'lt'  => 'Lithuanian',
		'lv'  => 'Latvian',
		'mk'  => 'Macedonian',
		'ml'  => 'Malayalam',
		'mn'  => 'Mongolian',
		'mr'  => 'Marathi',
		'ms'  => 'Malay',
		'my'  => 'Burmese',
		'nb'  => 'Norwegian Bokmål',
		'ne'  => 'Nepali',
		'nl'  => 'Dutch',
		'nn'  => 'Norwegian Nynorsk',
		'oci' => 'Occitan',
		'pa'  => 'Punjabi',
		'pl'  => 'Polish',
		'ps'  => 'Pashto',
		'pt'  => 'Portuguese',
		'rhg' => 'Rohingya',
		'ro'  => 'Romanian',
		'roh' => 'Romansh',
		'ru'  => 'Russian',
		'sah' => 'Sakha',
		'si'  => 'Sinhala',
		'sk'  => 'Slovak',
		'skr' => 'Saraiki',
		'sl'  => 'Slovenian',
		'snd' => 'Sindhi',
		'sq'  => 'Albanian',
		'sr'  => 'Serbian',
		'sv'  => 'Swedish',
		'sw'  => 'Swahili',
		'szl' => 'Silesian',
		'ta'  => 'Tamil',
		'te'  => 'Telugu',
		'th'  => 'Thai',
		'tl'  => 'Tagalog',
		'tr'  => 'Turkish',
		'tt'  => 'Tatar',
		'ug'  => 'Uyghur',
		'uk'  => 'Ukrainian',
		'ur'  => 'Urdu',
		'uz'  => 'Uzbek',
		'vi'  => 'Vietnamese',
		'zh'  => 'Chinese',
	);

	$language = $parts[0];

	return isset( $languages[ $language ] ) ? $languages[ $language ] : 'English';
}

/**
 * Resolve which file to send to the AI for an attachment.
 *
 * The original upload is the wrong thing to send. Providers bill by image
 * tokens and cap the size of a single request, and a phone or camera original
 * routinely runs to several megabytes and 4000 pixels or more, none of which
 * buys a better description or a better tag list. A registered intermediate
 * size carries the same subject at a fraction of the payload.
 *
 * The original is used whenever a suitable intermediate is not available: a
 * non-image attachment, an upload too small for the size to have been
 * generated, or a size that was never registered on this site.
 *
 * @param int          $attachment_id Attachment post ID.
 * @param string       $file_path     Absolute path to the original file.
 * @param string|false $mime_type     MIME type of the original file.
 * @return array {
 *     The file to analyze.
 *
 *     @type string       $path      Absolute path to the file.
 *     @type string|false $mime_type MIME type of that file.
 * }
 */
function ai_media_search_get_analysis_file( $attachment_id, $file_path, $mime_type ) {
	$original = array(
		'path'      => $file_path,
		'mime_type' => $mime_type,
	);

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $original;
	}

	/**
	 * Filters the image size sent to the AI for analysis.
	 *
	 * Defaults to `large`, which core caps at 1024 pixels on the long edge.
	 * That is at or above what current vision models resize an image to before
	 * they read it, so it keeps the detail that affects the description while
	 * dropping the payload by an order of magnitude on a typical upload.
	 *
	 * Accepts any registered size name, an array of `array( $width, $height )`
	 * to match the closest generated size, or `full` to send the original.
	 *
	 * @param string|int[] $size          Registered image size name or dimensions.
	 * @param int          $attachment_id Attachment post ID.
	 */
	$size = apply_filters( 'ai_media_search_image_size', 'large', $attachment_id );

	if ( empty( $size ) || 'full' === $size ) {
		return $original;
	}

	$intermediate = image_get_intermediate_size( $attachment_id, $size );

	if ( ! is_array( $intermediate ) || empty( $intermediate['path'] ) ) {
		return $original;
	}

	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return $original;
	}

	// image_get_intermediate_size() reports the path relative to the uploads directory.
	$path = path_join( $uploads['basedir'], $intermediate['path'] );

	if ( ! file_exists( $path ) ) {
		return $original;
	}

	// WordPress can write an intermediate size in a different format than the
	// original, so the type is read off the file that is actually being sent.
	$filetype = wp_check_filetype( $path );

	return array(
		'path'      => $path,
		'mime_type' => empty( $filetype['type'] ) ? $mime_type : $filetype['type'],
	);
}

/**
 * Send an image to the AI Client API and return the raw JSON response.
 *
 * This wraps the single call into the AI Client API so the response can be
 * replaced without a network request, which is what the test suite does.
 *
 * @param string $prompt        The prompt text.
 * @param string $file_path     Absolute path to the file to analyze.
 * @param string $mime_type     MIME type of the file.
 * @param int    $attachment_id Attachment post ID.
 * @return string|WP_Error Raw JSON response on success, WP_Error on failure.
 */
function ai_media_search_prompt_image( $prompt, $file_path, $mime_type, $attachment_id ) {
	/**
	 * Filters the raw AI response, short-circuiting the request when non-null.
	 *
	 * Returning anything other than null skips the AI Client API entirely. Return
	 * a JSON string in the shape the prompt asks for, or a WP_Error to simulate a
	 * failure.
	 *
	 * @param null|string|WP_Error $response      Raw response. Default null.
	 * @param string               $prompt        The prompt text.
	 * @param string               $file_path     Absolute path to the file to analyze.
	 * @param string               $mime_type     MIME type of the file.
	 * @param int                  $attachment_id Attachment post ID.
	 */
	$response = apply_filters( 'ai_media_search_pre_prompt_image', null, $prompt, $file_path, $mime_type, $attachment_id );

	if ( null !== $response ) {
		return $response;
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error(
			'ai_media_search_client_missing',
			__( 'The WordPress AI Client API is not available.', 'ai-media-search' )
		);
	}

	return wp_ai_client_prompt( $prompt )
		->with_file( $file_path, $mime_type )
		->as_json_response(
			array(
				'type'       => 'object',
				'properties' => array(
					'description' => array( 'type' => 'string' ),
					'tags'        => array( 'type' => 'string' ),
				),
				'required'   => array( 'description', 'tags' ),
			)
		)
		->generate_text();
}
