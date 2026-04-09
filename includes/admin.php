<?php
/**
 * Admin UI: status section on Settings > Media page.
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
					'<strong>' . number_format_i18n( $done ) . '</strong>',
					number_format_i18n( $counts['total'] ),
					$pct
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
