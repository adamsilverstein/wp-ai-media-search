<?php
/**
 * Admin UI: the Settings > Media status section, and the per-attachment AI
 * description panel shown on the Edit Media screen and in the media modal.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the status section on the Media settings page.
 */
function ai_media_search_admin_init() {
	add_settings_section(
		'ai_media_search_status',
		__( 'AI Media Search', 'ai-media-search' ),
		'ai_media_search_render_status_section',
		'media'
	);
}
add_action( 'admin_init', 'ai_media_search_admin_init' );

/**
 * Render the status section content.
 */
function ai_media_search_render_status_section() {
	$ai_available = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	$counts       = ai_media_search_get_status_counts();

	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'ai-media-search' ); ?></th>
			<td>
				<?php if ( $ai_available ) : ?>
					<span style="color: #00a32a;">&#9679;</span>
					<?php esc_html_e( 'Active — AI features available', 'ai-media-search' ); ?>
				<?php else : ?>
					<span style="color: #d63638;">&#9679;</span>
					<?php esc_html_e( 'Inactive — AI features not configured', 'ai-media-search' ); ?>
					<p class="description">
						<?php esc_html_e( 'Configure an AI provider (Anthropic, Google, or OpenAI) in your WordPress settings.', 'ai-media-search' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Progress', 'ai-media-search' ); ?></th>
			<td>
				<?php
				$done      = $counts['complete'];
				$remaining = $counts['total'] - $done;
				$pct       = $counts['total'] > 0 ? round( ( $done / $counts['total'] ) * 100 ) : 0;

				printf(
					/* translators: 1: number of processed images, 2: total images, 3: percentage */
					esc_html__( '%1$s of %2$s images processed (%3$s%%)', 'ai-media-search' ),
					'<strong>' . esc_html( number_format_i18n( $done ) ) . '</strong>',
					esc_html( number_format_i18n( $counts['total'] ) ),
					(int) $pct
				);
				?>

				<?php if ( $counts['total'] > 0 ) : ?>
					<div style="margin-top: 8px; background: #f0f0f1; border-radius: 3px; height: 8px; max-width: 400px;">
						<div style="background: #2271b1; border-radius: 3px; height: 100%; width: <?php echo (int) $pct; ?>%; transition: width 0.3s;"></div>
					</div>
				<?php endif; ?>

				<?php if ( $counts['failed'] > 0 || $counts['skipped'] > 0 ) : ?>
					<p class="description" style="margin-top: 8px;">
						<?php
						$parts = array();
						if ( $counts['pending'] > 0 ) {
							/* translators: %s: number of pending images */
							$parts[] = sprintf( esc_html__( '%s pending', 'ai-media-search' ), number_format_i18n( $counts['pending'] ) );
						}
						if ( $counts['failed'] > 0 ) {
							/* translators: %s: number of failed images */
							$parts[] = sprintf( esc_html__( '%s failed', 'ai-media-search' ), number_format_i18n( $counts['failed'] ) );
						}
						if ( $counts['skipped'] > 0 ) {
							/* translators: %s: number of skipped images */
							$parts[] = sprintf( esc_html__( '%s skipped', 'ai-media-search' ), number_format_i18n( $counts['skipped'] ) );
						}
						echo esc_html( implode( ', ', $parts ) );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Read everything the admin needs to describe one attachment's AI state.
 *
 * Returns a normalized array so the render functions never have to reason
 * about meta that is missing, a different type than expected, or left over
 * from an older version of the plugin.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array{status: string, description: string, tags: string, generated_at: int, error_message: string, attempts: int}
 */
function ai_media_search_get_attachment_state( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );
	$data   = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );
	$error  = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );

	$data  = is_array( $data ) ? $data : array();
	$error = is_array( $error ) ? $error : array();

	$description = isset( $data['description'] ) ? (string) $data['description'] : '';
	$tags        = isset( $data['tags'] ) ? (string) $data['tags'] : '';

	// Sites processed before the structured record existed, or filtered to drop
	// it, still have the flat search text. Showing that beats showing nothing.
	if ( '' === $description && '' === $tags ) {
		$text = get_post_meta( $attachment_id, '_wp_ai_media_search_text', true );

		if ( is_string( $text ) ) {
			$description = $text;
		}
	}

	return array(
		'status'        => is_string( $status ) ? $status : '',
		'description'   => $description,
		'tags'          => $tags,
		'generated_at'  => isset( $data['generated_at'] ) ? (int) $data['generated_at'] : 0,
		'error_message' => isset( $error['message'] ) ? (string) $error['message'] : '',
		'attempts'      => isset( $error['attempts'] ) ? (int) $error['attempts'] : 0,
	);
}

/**
 * Translate a stored status value into a label for the admin.
 *
 * @param string $status Stored status meta value. An empty string means the
 *                       attachment has never been queued.
 * @return string Translated label.
 */
function ai_media_search_get_status_label( $status ) {
	$labels = array(
		'complete'   => __( 'Described', 'ai-media-search' ),
		'processing' => __( 'Being described now', 'ai-media-search' ),
		'pending'    => __( 'Queued', 'ai-media-search' ),
		'failed'     => __( 'Failed', 'ai-media-search' ),
		'skipped'    => __( 'Skipped', 'ai-media-search' ),
	);

	return $labels[ $status ] ?? __( 'Not described yet', 'ai-media-search' );
}

/**
 * Build the explanation shown for a failed or skipped attachment.
 *
 * The stored error is the only clue about why an image has no description, so
 * it is surfaced verbatim rather than replaced with a generic message. Whether
 * the image will be retried on its own is the other half of the answer.
 *
 * @param array{status: string, error_message: string, attempts: int} $state Attachment state.
 * @return string Translated message.
 */
function ai_media_search_get_failure_message( $state ) {
	if ( 'skipped' === $state['status'] ) {
		if ( '' === $state['error_message'] ) {
			return __( 'This image was skipped and will not be retried on its own.', 'ai-media-search' );
		}

		return sprintf(
			/* translators: 1: number of attempts made, 2: error message from the last attempt. */
			__( 'Gave up after %1$s attempts and will not retry on its own. Last error: %2$s', 'ai-media-search' ),
			number_format_i18n( $state['attempts'] ),
			$state['error_message']
		);
	}

	if ( '' === $state['error_message'] ) {
		return __( 'The last attempt failed. Another one will run in the background within the hour.', 'ai-media-search' );
	}

	return sprintf(
		/* translators: 1: number of attempts made, 2: error message from the last attempt. */
		__( 'Attempt %1$s failed and another will run in the background within the hour. Error: %2$s', 'ai-media-search' ),
		number_format_i18n( $state['attempts'] ),
		$state['error_message']
	);
}

/**
 * Render the AI description panel for one attachment.
 *
 * The same markup is used by the Edit Media meta box, by the attachment
 * details in the media modal, and as the REST response that replaces the panel
 * after a regeneration, so all three always agree about what is stored.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_render_attachment_panel( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$state         = ai_media_search_get_attachment_state( $attachment_id );
	$ai_available  = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	$can_edit      = current_user_can( 'edit_post', $attachment_id );
	?>
	<div class="ai-media-search-panel" data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
		<p>
			<strong><?php esc_html_e( 'AI status:', 'ai-media-search' ); ?></strong>
			<?php echo esc_html( ai_media_search_get_status_label( $state['status'] ) ); ?>
		</p>

		<?php if ( '' !== $state['description'] ) : ?>
			<p class="ai-media-search-panel-description"><?php echo esc_html( $state['description'] ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $state['tags'] ) : ?>
			<p class="description ai-media-search-panel-tags">
				<strong><?php esc_html_e( 'Tags:', 'ai-media-search' ); ?></strong>
				<?php echo esc_html( $state['tags'] ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $state['generated_at'] > 0 ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: human readable time difference, for example "2 hours". */
					esc_html__( 'Generated %s ago.', 'ai-media-search' ),
					esc_html( human_time_diff( $state['generated_at'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( in_array( $state['status'], array( 'failed', 'skipped' ), true ) ) : ?>
			<div class="notice notice-error notice-alt inline">
				<p><?php echo esc_html( ai_media_search_get_failure_message( $state ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $can_edit && $ai_available ) : ?>
			<p>
				<button
					type="button"
					class="button ai-media-search-regenerate"
					data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'ai_media_search_regenerate_' . $attachment_id ) ); ?>"
				>
					<?php
					echo '' === $state['description']
						? esc_html__( 'Describe with AI', 'ai-media-search' )
						: esc_html__( 'Regenerate', 'ai-media-search' );
					?>
				</button>
				<span class="ai-media-search-feedback" aria-live="polite"></span>
			</p>
		<?php elseif ( $can_edit ) : ?>
			<p class="description">
				<?php esc_html_e( 'Regenerating needs an AI provider configured in WordPress.', 'ai-media-search' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Get the AI description panel for one attachment as a string.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string Panel markup.
 */
function ai_media_search_get_attachment_panel( $attachment_id ) {
	ob_start();
	ai_media_search_render_attachment_panel( $attachment_id );

	return (string) ob_get_clean();
}

/**
 * Add the AI description panel to the media modal's attachment details.
 *
 * @param array   $form_fields Fields keyed by name.
 * @param WP_Post $post        Attachment post object.
 * @return array Filtered fields.
 */
function ai_media_search_attachment_fields_to_edit( $form_fields, $post ) {
	if ( ! ai_media_search_is_supported_attachment( $post->ID ) ) {
		return $form_fields;
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $form_fields;
	}

	$form_fields['ai_media_search'] = array(
		'label'         => __( 'AI description', 'ai-media-search' ),
		'input'         => 'html',
		'html'          => ai_media_search_get_attachment_panel( $post->ID ),
		// The Edit Media screen gets the same panel as a meta box, and showing
		// it in both places would print it twice on that one screen.
		'show_in_edit'  => false,
		'show_in_modal' => true,
	);

	return $form_fields;
}
add_filter( 'attachment_fields_to_edit', 'ai_media_search_attachment_fields_to_edit', 10, 2 );

/**
 * Register the AI description meta box on the Edit Media screen.
 *
 * @param WP_Post $post Attachment post object.
 */
function ai_media_search_add_meta_boxes( $post ) {
	if ( ! ai_media_search_is_supported_attachment( $post->ID ) ) {
		return;
	}

	add_meta_box(
		'ai-media-search',
		__( 'AI Media Search', 'ai-media-search' ),
		'ai_media_search_render_meta_box',
		'attachment',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_attachment', 'ai_media_search_add_meta_boxes' );

/**
 * Render the Edit Media meta box.
 *
 * @param WP_Post $post Attachment post object.
 */
function ai_media_search_render_meta_box( $post ) {
	ai_media_search_render_attachment_panel( $post->ID );
}

/**
 * Enqueue the script backing the Regenerate button.
 *
 * The button appears wherever the media modal can be opened, which is a lot
 * more places than the media screens, so the script is loaded on the admin
 * screens that can show a modal rather than only on `upload.php`.
 */
function ai_media_search_admin_enqueue_scripts() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$screen = get_current_screen();

	/**
	 * Filters the admin screen bases that load the Regenerate button script.
	 *
	 * Matched against `WP_Screen::$base`. Add a base here for a screen that
	 * opens the media modal but is not covered by the defaults.
	 *
	 * @param string[] $bases Screen bases. Default post, upload, media,
	 *                        site-editor, widgets and customize.
	 */
	$bases = (array) apply_filters(
		'ai_media_search_admin_script_screens',
		array( 'post', 'upload', 'media', 'site-editor', 'widgets', 'customize' )
	);

	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->base, $bases, true ) ) {
		return;
	}

	wp_enqueue_script(
		'ai-media-search-admin',
		plugins_url( 'assets/admin.js', AI_MEDIA_SEARCH_PLUGIN_FILE ),
		array(),
		AI_MEDIA_SEARCH_VERSION,
		true
	);

	wp_localize_script(
		'ai-media-search-admin',
		'aiMediaSearchAdmin',
		array(
			'root'    => sanitize_url( rest_url( 'ai-media-search/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'working' => __( 'Asking the AI…', 'ai-media-search' ),
			'failed'  => __( 'The request failed. Please try again.', 'ai-media-search' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'ai_media_search_admin_enqueue_scripts' );
