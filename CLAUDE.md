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
- **Image/video proxy** (`handle_image_proxy`): intercepts `?gallery_for_immich_proxy=` requests and streams assets from Immich. The Immich API key never reaches the browser.
- **Shortcode renderer** (`render_gallery`): one method handles three modes — album overview, album detail, single asset — determined by the presence of `album=`, `asset=`, or neither in shortcode attributes plus `?gallery_for_immich=` in the URL.
- **REST endpoints**: `/gallery-for-immich/v1/albums` (block editor, auth required) and `/gallery-for-immich/v1/live-photo-url` (public, used by frontend JS for Live Photos).
- **Video modes**: `shared` creates temporary Immich shared links (scheduled for cleanup via WP cron); `fopen` streams through the proxy; `ignore` skips videos entirely.
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

Three files must be updated together on every release: `gallery-for-immich.php` (plugin header), `readme.txt` (`Stable tag`), `package.json`. See `RELEASE.md` for the full release checklist. Run `npm run translate` **after** bumping the version so the `.pot` header carries the correct version number.

## Test environment

`.wp-env.json` pins the WordPress version for compatibility testing. To test against a different version, update `"core"` to e.g. `"https://wordpress.org/wordpress-7.0.zip"`. See `TESTING.md` for the manual and CLI test checklist.
