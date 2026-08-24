# AI Media Search

A lightweight WordPress plugin that uses the WordPress 7.0 AI Client API to generate searchable descriptions for media library images.

Upload an image of a cat, and later search your media library for "cat" — even if the filename is `IMG_4523.jpg` and you never wrote a caption.

## How it works

1. **New uploads** are queued for AI processing automatically (via a short-delay cron event).
2. **Existing images** are processed in the background by an hourly cron job, newest first.
3. **Published posts** trigger processing for any unprocessed images in their content.
4. The AI analyzes a scaled copy of each image (the `large` size by default, not the full size original) and generates a text description plus search tags.
5. The description and tags are written in the site language, so a German site gets German text to search against.
6. The description and tags are stored as post meta and included in media library search queries, both on the Media Library screen and in the block editor's media inserter.

A run that dies mid-flight - a PHP timeout, a fatal error, a restarted worker -
leaves an image marked `processing`. Anything still in that state after
`ai_media_search_processing_timeout` seconds (15 minutes by default) is treated
as abandoned and picked up again by the next batch, so nothing needs `--reset`
to get moving again.

The plugin never overwrites user-entered metadata (title, caption, description, alt text). All AI data is stored in separate `_wp_ai_media_search_*` meta keys.

The AI text is searched as an extra source alongside the post columns WordPress
already searches, one search term at a time, so a two word search can match one
word in the title and the other in the AI description. It follows the rest of
`WP_Query`'s search behaviour: `exact` and `sentence` queries match the AI text
the same way they match a post column, a `post_search_columns` filter that
narrows the columns still applies to those columns, and a term prefixed with `-`
excludes images the AI described that way without hiding images that have no AI
text yet.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- An AI provider configured in WordPress (Anthropic, Google, or OpenAI)

## In the admin

Every image gets an **AI Media Search** panel showing what the AI wrote about
it, the tags it came up with, and where it is in the queue:

- On the **Edit Media** screen, as a meta box in the sidebar.
- In the **media modal**, in the attachment details next to alt text and caption.

When an image failed or was skipped, the panel says so and prints the stored
error, so the reason a search missed an image is visible without a database
query.

A **Regenerate** button in the same panel throws the stored data away and asks
the AI again. It posts to the REST endpoint below and swaps the panel out in
place, so nothing reloads. The button only appears for users who can edit that
attachment, and only while an AI provider is configured.

Settings > Media keeps the library-wide status summary.

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

```http
GET /wp-json/ai-media-search/v1/status
```

Returns processing counts. Requires `upload_files` capability.

Counts are cached for five minutes and refreshed as soon as any attachment's
processing status changes, so polling this endpoint does not recount the media
library each time. Use `ai_media_search_status_counts_ttl` to change or disable
the cache.

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

```http
POST /wp-json/ai-media-search/v1/attachments/<id>/regenerate
```

Clears the stored data for one attachment and describes it again, returning the
new state along with the re-rendered admin panel. Requires the `edit_post`
capability for that attachment, plus a `nonce` parameter for the
`ai_media_search_regenerate_<id>` action. This is the endpoint behind the
Regenerate button; the AI call runs inline, so the request stays open for as
long as the provider takes.

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `ai_media_search_batch_size` | `5` | Images per cron batch (clamped 1–50). |
| `ai_media_search_prompt` | *(built-in)* | AI prompt text. Receives `$prompt, $attachment_id`. |
| `ai_media_search_language` | *(from `get_locale()`)* | Language the description and tags are written in, as an English language name such as `French`. Receives `$language, $locale, $attachment_id`. |
| `ai_media_search_pre_prompt_image` | `null` | Return a JSON string or `WP_Error` to skip the AI request entirely. Receives `$response, $prompt, $file_path, $mime_type, $attachment_id`. |
| `ai_media_search_image_size` | `'large'` | Registered image size sent to the AI. Use `'full'` to send the original. Receives `$size, $attachment_id`. |
| `ai_media_search_should_process` | `true` | Skip specific attachments. Receives `$should, $attachment_id`. |
| `ai_media_search_max_retries` | `3` | Max retry attempts before marking as skipped. |
| `ai_media_search_processing_timeout` | `900` | Seconds an attachment may sit in `processing` before the run that owns it is presumed dead and the attachment is retried. Clamped to a minimum of 60. |
| `ai_media_search_update_alt_text` | `false` | When true, writes AI description to empty alt text fields. |
| `ai_media_search_metadata` | *(AI output)* | Filter metadata after AI generation, before storage. Receives `$metadata, $attachment_id`. |
| `ai_media_search_search_text` | *(description + tags)* | Filter concatenated search text before storage. Receives `$search_text, $metadata, $attachment_id`. |
| `ai_media_search_supported_mime_types` | `['image']` | MIME type prefixes to process. Add `'video'` or `'audio'` to extend. |
| `ai_media_search_cron_interval` | `'hourly'` | Cron recurrence schedule name for batch processing. |
| `ai_media_search_status_counts_ttl` | `300` | Seconds the processing status counts are cached. The cache is dropped whenever a status changes; return `0` to recount on every call. |
| `ai_media_search_is_attachment_search` | *(admin and REST media searches)* | Whether a query searches the AI text. Receives `$is_attachment_search, $query`. |
| `ai_media_search_admin_script_screens` | `['post', 'upload', 'media', 'site-editor', 'widgets', 'customize']` | Admin screen bases (`WP_Screen::$base`) that load the Regenerate button script. |

### Examples

```php
// Increase batch size for sites with many images.
add_filter( 'ai_media_search_batch_size', function () {
    return 20;
} );

// Enable auto-populating empty alt text.
add_filter( 'ai_media_search_update_alt_text', '__return_true' );

// Generate metadata in one language regardless of the site language.
add_filter( 'ai_media_search_language', function () {
    return 'French';
} );

// Send a smaller image: cheaper and faster, with less detail for the AI to read.
add_filter( 'ai_media_search_image_size', function () {
    return 'medium_large';
} );

// Skip GIFs.
add_filter( 'ai_media_search_should_process', function ( $should, $attachment_id ) {
    if ( 'image/gif' === get_post_mime_type( $attachment_id ) ) {
        return false;
    }
    return $should;
}, 10, 2 );

// Append EXIF keywords to search text.
add_filter( 'ai_media_search_search_text', function ( $text, $metadata, $attachment_id ) {
    $meta = wp_get_attachment_metadata( $attachment_id );
    if ( ! empty( $meta['image_meta']['keywords'] ) ) {
        $text .= ' ' . implode( ' ', $meta['image_meta']['keywords'] );
    }
    return $text;
}, 10, 3 );

// Enable video processing (requires AI provider with video support).
add_filter( 'ai_media_search_supported_mime_types', function ( $types ) {
    $types[] = 'video';
    return $types;
} );

// Run batch processing every 30 minutes instead of hourly.
add_filter( 'ai_media_search_cron_interval', function () {
    return 'every_thirty_minutes'; // Must be registered with wp_get_schedules().
} );

// Search the AI text on the front end too, which is off by default.
add_filter( 'ai_media_search_is_attachment_search', function ( $is_attachment_search, $query ) {
    if ( ! is_admin() && $query->is_search() && 'attachment' === $query->get( 'post_type' ) ) {
        return true;
    }
    return $is_attachment_search;
}, 10, 2 );
```

## Actions

| Action | Parameters | Description |
|--------|-----------|-------------|
| `ai_media_search_processed` | `$attachment_id, $metadata` | Fires after an attachment is successfully processed. |
| `ai_media_search_failed` | `$attachment_id, $error, $error_data` | Fires when processing fails. `$error_data` includes attempt count. |
| `ai_media_search_batch_complete` | `$processed` | Fires after a batch cron run with the count of items processed. |
| `ai_media_search_regenerated` | `$attachment_id, $status` | Fires after a manual regeneration, whether it succeeded or failed. |

## Development

Tests, coding standards and static analysis all run on every push and pull
request. Everything the plugin needs for development comes from Composer:

```bash
composer install
```

### Checks

```bash
composer test      # PHPUnit, against the WordPress test library
composer lint      # PHPCS: WordPress Coding Standards + PHPCompatibilityWP (8.1+)
composer lint:fix  # PHPCBF: fix what can be fixed automatically
composer analyze   # PHPStan level 5, via szepeviktor/phpstan-wordpress
```

Configuration lives in `phpunit.xml.dist`, `phpcs.xml.dist` and
`phpstan.neon.dist`. None of the dev tooling ships in the WordPress.org build.

### Running tests

The test suite runs against the WordPress PHPUnit library. Both that library and a
copy of WordPress come from Composer, so there is no install script to run and no
Subversion checkout to keep in sync.

The tests need a MySQL or MariaDB database they can drop and recreate. Point the
suite at one with environment variables, then run it:

```bash
export WP_TESTS_DB_NAME=wordpress_test
export WP_TESTS_DB_USER=root
export WP_TESTS_DB_PASSWORD=root
export WP_TESTS_DB_HOST=127.0.0.1:3306

composer test
```

| Variable | Default |
|----------|---------|
| `WP_TESTS_DB_NAME` | `wordpress_test` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | `root` |
| `WP_TESTS_DB_HOST` | `localhost` |
| `WP_TESTS_ABSPATH` | the WordPress copy in `vendor/` |

**The database is wiped on every run**, so give the suite one of its own.

No test ever reaches an AI provider. `AI_Media_Search_TestCase` short-circuits
`ai_media_search_pre_prompt_image` with a failing stub, so a test that forgets to
supply a canned response gets a `WP_Error` rather than a live request.

GitHub Actions runs the suite on PHP 8.1 through 8.4 for every pull request.

### Releasing

Publishing a GitHub release pushes the plugin to the WordPress.org directory
under the slug `ai-media-search`, which is not the same as this repository's
name. Before tagging a release, three things have to agree:

1. `Version` in `ai-media-search.php` - this is what the directory shows as the
   downloadable version.
2. `Stable tag` in `readme.txt` - this is what the directory serves. If it points
   at a tag that does not exist, updates break.
3. The new `== Changelog ==` entry in `readme.txt`.

`Requires at least` and `Requires PHP` are read from `ai-media-search.php`, not
from `readme.txt`, but the two are kept in sync so the readme is not misleading.

## Uninstall

Deactivating the plugin stops processing and search integration but preserves all generated metadata. Deleting the plugin removes all `_wp_ai_media_search_*` meta and any leftover `ai_media_search_lock_*` options from the database.
