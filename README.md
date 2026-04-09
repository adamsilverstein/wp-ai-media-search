# AI Media Search

A lightweight WordPress plugin that uses the WordPress 7.0 AI Client API to generate searchable descriptions for media library images.

Upload an image of a cat, and later search your media library for "cat" — even if the filename is `IMG_4523.jpg` and you never wrote a caption.

## How it works

1. **New uploads** are queued for AI processing automatically (via a short-delay cron event).
2. **Existing images** are processed in the background by an hourly cron job, newest first.
3. **Published posts** trigger processing for any unprocessed images in their content.
4. The AI analyzes each image and generates a text description plus search tags.
5. The description and tags are stored as post meta and included in media library search queries.

The plugin never overwrites user-entered metadata (title, caption, description, alt text). All AI data is stored in separate `_wp_ai_media_search_*` meta keys.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- An AI provider configured in WordPress (Anthropic, Google, or OpenAI)

## WP-CLI Commands

```bash
# Process all unprocessed images
wp ai-media-search process --all

# Process specific images by ID
wp ai-media-search process 42 55 78

# Re-process all images from scratch
wp ai-media-search process --all --reset

# Process next 20 unprocessed images
wp ai-media-search process --all --batch-size=20

# Preview what would be processed
wp ai-media-search process --all --dry-run

# Show processing status
wp ai-media-search status

# Regenerate metadata for specific images
wp ai-media-search regenerate 42

# Regenerate all images
wp ai-media-search regenerate --all
```

## REST API

```
GET /wp-json/ai-media-search/v1/status
```

Returns processing counts. Requires `upload_files` capability.

```json
{
  "complete": 142,
  "processing": 0,
  "pending": 23,
  "failed": 2,
  "skipped": 1,
  "unprocessed": 332,
  "total": 500
}
```

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `ai_media_search_batch_size` | `5` | Images per hourly cron batch (clamped 1–50). |
| `ai_media_search_prompt` | *(built-in)* | AI prompt text. Receives `$prompt, $attachment_id`. |
| `ai_media_search_should_process` | `true` | Skip specific attachments. Receives `$should, $attachment_id`. |
| `ai_media_search_max_retries` | `3` | Max retry attempts before marking as skipped. |
| `ai_media_search_update_alt_text` | `false` | When true, writes AI description to empty alt text fields. |

### Examples

```php
// Increase batch size for sites with many images.
add_filter( 'ai_media_search_batch_size', function () {
    return 20;
} );

// Enable auto-populating empty alt text.
add_filter( 'ai_media_search_update_alt_text', '__return_true' );

// Skip GIFs.
add_filter( 'ai_media_search_should_process', function ( $should, $attachment_id ) {
    if ( 'image/gif' === get_post_mime_type( $attachment_id ) ) {
        return false;
    }
    return $should;
}, 10, 2 );
```

## Actions

| Action | Parameters | Description |
|--------|-----------|-------------|
| `ai_media_search_processed` | `$attachment_id, $metadata` | Fires after an image is successfully processed. |

## Uninstall

Deactivating the plugin stops processing and search integration but preserves all generated metadata. Deleting the plugin removes all `_wp_ai_media_search_*` meta from the database.
