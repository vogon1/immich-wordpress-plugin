# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Language

All text in files in this repository must be written in **English** — code comments, documentation, commit messages, etc.

## Commands

```bash
# Build the Gutenberg block (webpack + sync translation hashes)
npm run build

# Watch mode during development
npm run start

# Full translation pipeline: generate POT → update PO → compile MO + JSON
npm run translate

# Test suite against wp-env (needs a configured Immich; see TESTING.md)
npm run test

# Plugin Check, all checks incl. runtime, on the .distignore-filtered release contents (RELEASE.md step 9)
npm run plugin-check

# wp-env test environment (isolated Docker, WordPress + plugin pre-installed)
npm run wp-env:start    # starts at http://localhost:8888 (admin / password)
npm run wp-env:stop
npm run wp-env:destroy  # wipe and start fresh

# Configure plugin settings in wp-env after a destroy:
npx wp-env run cli wp option update gallery_for_immich_settings \
  '{"server_url":"https://fotos.sietse.nl","api_key":"YOUR_KEY","video_mode":"shared"}' \
  --format=json
```

## Architecture

The plugin has two layers that work together:

**PHP (`gallery-for-immich.php`)** — single class `Gallery_For_Immich` that handles everything server-side:
- **Image/video proxy** (`handle_image_proxy`, on `wp_loaded`): intercepts `?gallery_for_immich_proxy=` requests and streams assets from Immich. The Immich API key never reaches the browser. Only `thumbnail`, `preview` and `video` are served — never originals. Every proxy URL is built by `proxy_url()` and carries an HMAC (`proxy_signature()`) over the asset ID and the post it is shown on (`post=`); unsigned URLs get 403. For a post with a password or a non-public status, the proxy checks `visitor_can_view_post()` (filterable via `gallery_for_immich_can_view`) and sends `Cache-Control: private`. Videos stream via fopen (`stream_video_fopen`, needs `allow_url_fopen`) — **not cURL**: cURL streaming made the Docker Desktop VM network hang (`NETDEV WATCHDOG: transmit queue timed out`), taking all containers down. Immich's `Content-Type` is passed on from an allow list.
- **Shortcode renderer** (`render_gallery`): one method handles three modes — album overview, album detail, single asset — determined by the presence of `album=`, `asset=`, or neither in shortcode attributes plus `?gallery_for_immich=` in the URL.
- **REST endpoints**: `/gallery-for-immich/v1/albums` (block editor, auth required) and `/gallery-for-immich/v1/live-photo-url` (public, used by frontend JS for Live Photos). Because the latter creates Immich shared links, it requires an HMAC signature (`live_photo_signature()`, over the ID and the post) that is rendered next to each Live Photo ID; shared links are reused per asset via a transient.
- **Immich API layer**: `api_call()` does one request and returns decoded JSON or an error array (`api_error()`: `status` + Immich's `message`); `cached()` keeps successful responses in transients for the configured `cache_ttl`, reduced first to the fields the plugin uses (`slim_album()`, `slim_asset()`; use `get_albums()`, `get_album()`, `get_asset()` rather than raw endpoints) (cache generation option `gallery_for_immich_cache_gen`, bumped by "Clear cache"). `search_album_assets()` uses the structured search API (`filter`/`orderBy`/`cursor`) from Immich 3.2 and the deprecated flat fields below that, based on the cached `server/version` (`use_structured_search()`). Galleries show API errors through `error_html()`: a plain message for visitors, the Immich error for administrators.
- **Album from the URL** (`?gallery_for_immich=`): only honoured by an overview shortcode, and with `albums=` only for an album in that list — never by `album=` or `asset=` shortcodes.
- **Video modes**: `shared` creates temporary Immich shared links (scheduled for cleanup via WP cron); `fopen` ("Stream through WordPress") streams through the proxy; `ignore` skips videos entirely. On protected posts videos always go through the proxy (`video_via_proxy()`), because a shared link cannot be protected.
- **`should_create_shared_links()`**: gates any Immich API write calls — returns false in REST/admin contexts to avoid shared link creation during block editor previews.

**JavaScript (`src/index.js`)** — React Gutenberg block (compiled to `build/`):
- `save: () => null` — the block is server-side rendered; the block only stores attributes and shows a shortcode preview in the editor.
- Fetches available albums via the REST API on mount for album selection dropdowns.
- Generates and displays the equivalent shortcode as a preview.

**Frontend JS** (inline in `gallery-for-immich.php` via `wp_add_inline_script`):
- Initialises GLightbox (from `assets/glightbox/`).
- Handles video autoplay across slide changes via a 100ms polling interval.
- Live Photos: builds a map of `asset_id → livePhotoVideoId` from `data-live-photo-id` attributes, fetches the video URL on demand via the REST endpoint, and overlays a `<video>` element in the lightbox.

## Translation workflow

JSON translation files for the block editor must match the webpack build hash. `scripts/sync-translations.js` (run automatically by `npm run build` and `npm run translate`) reads the hash from `build/index.asset.php` and renames/copies the locale JSON files to match: `gallery-for-immich-{locale}-{hash}.json`. Never manually rename these files.

Supported locales: `nl_NL`, `de_DE`, `fr_FR`.

## Version bumping

Three files must be updated together on every release: `gallery-for-immich.php` (plugin header), `readme.txt` (`Stable tag`), `package.json`. See `RELEASE.md` for the full release checklist. The final `npm run translate` before a release must run **after** bumping the version, because the `.pot` header carries the plugin version. Compiling translations earlier during development is fine.

## Test environment

`.wp-env.json` pins the WordPress version for compatibility testing. To test against a different version, update `"core"` to e.g. `"https://wordpress.org/wordpress-7.0.zip"`. See `TESTING.md` for the manual checklist and the test suite (`tests/`). The shortcode attributes are parsed in `parse_attributes()`, separate from rendering, so the tests can check them directly.
