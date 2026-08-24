=== AI Media Search ===
Contributors: adamsilverstein
Tags: ai, media, media library, search, images
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find images by what is in them. AI writes a description and search tags for every image, and media library search reads them.

== Description ==

Upload a photo of a cat, then search the media library for "cat" later on - even though the file is named `IMG_4523.jpg` and nobody ever wrote a caption for it.

AI Media Search hands each image to an AI model, asks for a short description and a list of search tags, and stores the answer as post meta. Media library search then looks through that text alongside the title, caption and filename fields it already searches.

Nothing you typed is overwritten. Titles, captions, descriptions and alt text are left exactly as they are, and everything the AI generates lives in its own `_wp_ai_media_search_*` meta keys.

The description and tags are written in your site language, so searching in the language you actually work in finds what you are looking for.

= How images get processed =

* New uploads are queued at upload time and picked up by a cron event a few seconds later.
* Images already in the library are worked through by an hourly cron job, newest first, five per run by default. A run watches the clock and stops between images before PHP would cut it off, leaving the rest for a follow-up run a couple of minutes later.
* Publishing a post queues any unprocessed images found in its content, including images inside gallery, cover and media-and-text blocks, plus the featured image. They are queued 30 seconds apart, so publishing a gallery does not ask a single cron run to describe every image in it.
* WP-CLI can run the whole library on demand instead of waiting for cron.

Settings > Media gains a status section showing whether AI is available and how many images have been processed so far. A read-only REST endpoint at `ai-media-search/v1/status` returns the same counts to anyone with the `upload_files` capability.

= Seeing and redoing what the AI wrote =

Each image carries an AI Media Search panel with the generated description, the tags and the current status. It shows up as a sidebar box on the Edit Media screen and in the attachment details of the media modal, so the answer to "why did this image not come up in my search" is on screen rather than in the database.

Images that failed or were skipped print the error from the last attempt, and a Regenerate button next to it throws the stored data away and asks the AI again without leaving the page. The button is only there for users who can edit that attachment.

Search integration applies to media library searches: the Media Library screen, the classic media modal, and the block editor's media inserter, which searches over the REST API. In every case the AI text is only consulted for a user who can manage media. Front end queries are left alone.

= Important: your images are sent to an AI provider =

This plugin cannot describe an image without showing it to an AI model, and that model does not run on your site.

AI Media Search has no service of its own. It calls the AI Client API built into WordPress, and WordPress forwards the request to whichever AI provider you have configured - Anthropic, Google or OpenAI. **The image file itself leaves your server** and is uploaded to that provider along with a text prompt asking for a description and tags. The provider's response comes back as JSON and is stored in your database.

What that provider does with the image, how long it retains it, and whether it is used for anything else are governed by the terms and privacy policy of the provider you chose, not by this plugin:

* Anthropic - https://www.anthropic.com/legal/commercial-terms and https://www.anthropic.com/legal/privacy
* Google (Gemini API) - https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* OpenAI - https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy

If images on the site are private, sensitive or licensed in a way that makes that a problem, this plugin is not a good fit. Nothing is sent anywhere until an AI provider is configured in WordPress, and the plugin registers no hooks at all while `wp_supports_ai()` is false.

Whatever the provider charges for image analysis is billed to your account with them. A large media library means a lot of requests.

= Requirements =

* WordPress 7.0 or later, for the AI Client API
* PHP 8.1 or later
* An AI provider configured in WordPress

= Customizing =

Batch size, the prompt, the language, retry limits, which MIME types are processed, the cron interval, how long a batch run may take, how far apart a post's images are queued and the stored search text are all filterable, and actions fire when an image is processed or fails. The full list, with examples, is in the README on GitHub: https://github.com/adamsilverstein/wp-ai-media-search

== Installation ==

1. Configure an AI provider in WordPress first. The plugin stays completely inactive until `wp_supports_ai()` returns true.
2. Upload the plugin to `wp-content/plugins/ai-media-search`, or install it from Plugins > Add New.
3. Activate the plugin.
4. Visit Settings > Media and check the AI Media Search section. It should read "Active - AI features available".

Processing starts on its own from there. New uploads are handled within seconds, and the existing library is worked through in the background, five images an hour by default. To move faster, use WP-CLI:

`wp ai-media-search process --all`

== Frequently Asked Questions ==

= Does this send my images to a third party? =

Yes. There is no way around it - describing an image means sending it to an AI model, and that model is hosted by an AI provider. The image file is uploaded to whichever provider WordPress is configured to use, together with a prompt asking for a description and search tags. Their terms and privacy policy apply to that data, not this plugin's. The Description section above links to the terms for each supported provider.

= Which provider does it use? =

Whichever one is configured in WordPress. The plugin calls the AI Client API added in WordPress 7.0 and never picks a provider itself, so switching providers in WordPress switches what this plugin talks to. Anthropic, Google and OpenAI are the providers the API supports today.

= Does it need its own API key? =

No. Credentials belong to the AI provider configuration in WordPress. The plugin has no settings of its own.

= What happens if no AI provider is configured? =

Nothing. Every hook is registered behind a `wp_supports_ai()` check, so with no provider the plugin adds no processing, no cron and no search changes. Settings > Media will say the AI features are not configured.

= Will it overwrite my alt text, captions or titles? =

Not by default. Generated data is stored in separate meta keys and none of the core fields are touched. Sites that want the description copied into empty alt text fields can opt in:

`add_filter( 'ai_media_search_update_alt_text', '__return_true' );`

Fields that already have alt text are still left alone.

= How long does an existing media library take? =

At the defaults, five images per hour. A library of a few hundred images will take days that way, which is deliberate - it keeps the request volume and the provider bill predictable. Raise `ai_media_search_batch_size` (up to 50), shorten the interval with `ai_media_search_cron_interval`, or just run `wp ai-media-search process --all` and let it work through the queue immediately.

= What happens when an image fails? =

It is retried, with an hour of cooldown between attempts, up to three times. After that it is marked as skipped and left alone. Failure counts show up in the Settings > Media status section, and `wp ai-media-search regenerate` will reset an image and try again.

A run that is cut short rather than failing outright - a PHP timeout, a fatal error, a restarted worker - leaves the image marked as processing. Anything sitting there for more than fifteen minutes is treated as abandoned and picked up again by the next batch.

Batch runs try not to get that far. Each one measures itself against the time PHP allows a request, counting from the moment that request began rather than the moment the batch did, stops between images once most of that is gone, and queues a follow-up run for whatever it did not reach. Images left over that way are untouched, not failed, so they carry no retry count.

= Can it describe video or audio? =

Only if the configured provider supports it. Images are the only type processed out of the box, and other types are opt-in:

`add_filter( 'ai_media_search_supported_mime_types', function ( $types ) { $types[] = 'video'; return $types; } );`

= Where can I see what the AI wrote about an image? =

On the image itself. Open it from the media library, or click it in the media modal while editing a post, and the AI Media Search panel lists the description, the tags and the status. If the image failed or was skipped, the panel shows the error from the last attempt.

= Can I make it try an image again? =

Yes. The same panel has a Regenerate button, which clears the stored description and asks the AI for a new one on the spot. It is available to anyone who can edit that attachment, so authors can redo their own uploads without an administrator. `wp ai-media-search regenerate <id>` does the same thing from the command line.

= What language are the descriptions written in? =

Whatever Settings > General has the site language set to. The site language is used rather than the language of whoever is logged in, because the media library is shared: two editors with different admin languages would otherwise fill it with a mix of both.

A site that wants one fixed language regardless - a multilingual site, or an English-language workflow on a non-English site - can say so:

`add_filter( 'ai_media_search_language', function () { return 'French'; } );`

Images processed before a site language change keep the text they were generated with. `wp ai-media-search process --all --reset` regenerates them.

= Does it change front end search? =

No. Only media library searches are extended, whether they come from the Media Library screen, the classic media modal or the block editor's media inserter. A visitor searching the front end, or a REST request from someone without the `upload_files` capability, gets the search WordPress would have run anyway.

= What happens if I deactivate or delete the plugin? =

Deactivating stops the processing and the search integration but keeps everything already generated, so reactivating picks up where it left off. Deleting the plugin removes every `_wp_ai_media_search_*` meta row and any leftover `ai_media_search_lock_*` option from the database.

== Changelog ==

= 0.1.0 =

* Initial release.
* AI-generated descriptions and search tags for media library images, via the WordPress AI Client API.
* Automatic processing for new uploads, images in newly published posts, and an hourly background batch for the existing library.
* Descriptions and tags generated in the site language, overridable with the `ai_media_search_language` filter.
* Media library search extended to match the generated text.
* WP-CLI commands: `process`, `status` and `regenerate`.
* Status section on Settings > Media and a `ai-media-search/v1/status` REST endpoint.
* AI description panel on the Edit Media screen and in the media modal, with a Regenerate button for a single image.
* Filters and actions for the prompt, batch size, retries, MIME types, cron interval and stored metadata.

== Upgrade Notice ==

= 0.1.0 =

First release. Requires WordPress 7.0 and an AI provider configured in WordPress. Note that activating this plugin sends your media library images to that provider for analysis.
