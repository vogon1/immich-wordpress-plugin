# Test Checklist — Gallery for Immich

Use this checklist for every new release or WordPress version upgrade.

> **🤖 = covered by `npm run test`** (see [Running the Test Suite](#-running-the-test-suite))  
> **👤 = manual browser testing**

## Setting Up the Test Environment

```bash
npm run wp-env:start
# WordPress: http://localhost:8888/wp-admin  (admin / password)

# Configure plugin settings (once, or after wp-env:destroy):
npx wp-env run cli wp option update gallery_for_immich_settings \
  '{"server_url":"https://fotos.sietse.nl","api_key":"YOUR_KEY","video_mode":"shared"}' \
  --format=json
```

---

## ⚙️ Admin & Settings

| ✓ | Test |
|---|---|
| 👤 | Settings page loads without errors |
| 👤 | Saving server URL works |
| 👤 | Invalid URL (without `http://`) shows error message |
| 👤 | HTTP URL (non-localhost) shows warning modal |
| 👤 | Cancelling modal → form not submitted |
| 👤 | API key with invalid characters (space, @) shows error message |
| 👤 | Video mode dropdown saves correctly |
| 👤 | Cache dropdown saves correctly; **Off** fetches from Immich on every page view |
| 👤 | **Clear cache** shows "Cache cleared." and a photo added in Immich appears right away |
| 👤 | Connection test button returns green result and shows the Immich server version |
| 👤 | Connection test with a wrong API key shows `Connection failed: HTTP 401: Invalid API key` |
| 👤 | A failing check shows what Immich answered, e.g. `Missing (HTTP 403: …)` or `Failed (HTTP 502: …)` |

---

## 🖼️ Gallery Overview (all albums)

| ✓ | Test |
|---|---|
| 👤 | `[gallery_for_immich]` renders album grid |
| 👤 | Thumbnails load correctly |
| 👤 | Clicking an album opens the album detail view |
| 👤 | Back link appears in album detail |
| 👤 | `show="gallery_name,gallery_description"` shows name and description |
| 👤 | `order="name_asc"` sorts alphabetically |
| 👤 | `size="300"` renders larger thumbnails |
| 👤 | `limit="2"` shows 2 albums; `pick="random"` varies on reload |
| 👤 | With `limit` on an overview, a clicked album still shows all its photos |
| 👤 | `show="gallery_name" detail_show="gallery_description,asset_date"` — overview shows names only; clicked album shows description and dates, no name |
| 👤 | `detail_show=""` — clicked album shows no text at all |
| 👤 | Block: turning off **Same on album page** shows a second set of checkboxes; the shortcode preview shows `detail_show` |
| 🤖 | `albums="invalid-id"` produces no PHP errors |

---

## 📂 Album Detail

| ✓ | Test |
|---|---|
| 👤 | Photos render in grid |
| 🤖 | Invalid album ID: a visitor sees "No photos found in this album."; an administrator also sees the Immich error |
| 👤 | `show="asset_date,asset_description"` shows date and description |
| 👤 | `order="date_desc"` reverses the order |
| 👤 | `limit="5"` shows the 5 oldest photos; with `order="date_desc"` the 5 newest |
| 👤 | `limit="5" pick="random"` shows a different selection on reload, in sort order |
| 👤 | `limit="5" order="description_asc"` shows the first 5 by description |
| 👤 | Video mode **ignore** + `limit="5"` still shows 5 photos |
| 👤 | `limit="1" order="date_desc" link="https://..."` shows the latest photo, linking to the URL in a new tab |
| 👤 | Same with `link_target="same"` opens in the same tab; `pick="random"` shows a different photo on reload |
| 👤 | `link="none"` shows thumbnails without any link |
| 👤 | Overview with `link="https://..."`: albums still link to their album page, and the clicked album uses the lightbox |
| 👤 | Clicking a photo opens the lightbox |
| 👤 | Lightbox navigation (arrows, keyboard) works |
| 👤 | Closing lightbox (Escape, close button) works |

---

## 🖼️ Single Photo (`asset=`)

| ✓ | Test |
|---|---|
| 👤 | Photo renders correctly |
| 👤 | `align="left"` / `align="right"` — text wraps around photo |
| 👤 | `align="center"` centers the photo |
| 👤 | Clicking opens lightbox with preview |
| 👤 | `link="https://..."` opens the URL in a new tab |
| 👤 | `link="https://..." link_target="same"` opens the URL in the same tab |
| 👤 | `show="asset_date"` shows the date |

---

## 🎬 Video

| ✓ | Test |
|---|---|
| 🤖 | Video mode **shared**: a public page uses an Immich shared link that streams (206) |
| 👤 | Video mode **shared**: video plays in lightbox |
| 👤 | Video mode **Stream through WordPress**: video streams via WordPress, seeking works |
| 🤖 | Streaming answers Range requests with 206 and passes `Content-Range` on |
| 👤 | Video mode **ignore**: videos are not shown |
| 👤 | Video autoplays when lightbox slide changes |
| 👤 | Previous video pauses when switching slides |
| 👤 | Safari (Mac): clicking the seek bar jumps, dragging moves the seek bar (not the slide), and releasing the mouse button lets go of it |

---

## 📸 Live Photos

| ✓ | Test |
|---|---|
| 👤 | Live Photo shows ▶ Live button in lightbox |
| 👤 | Clicking Live button plays the video |
| 👤 | Still image hides, video appears |
| 👤 | Live button disappears when switching to another photo |

---

## 🧩 Gutenberg Block Editor

| ✓ | Test |
|---|---|
| 👤 | Block is findable in the block inserter |
| 👤 | Mode "All albums" shows shortcode preview |
| 👤 | Mode "Single album" — album selection works |
| 👤 | Mode "Multiple albums" — selecting multiple works |
| 👤 | Mode "Single photo" — entering asset ID works |
| 👤 | Mode "Single album" — link behavior (lightbox/no link/custom URL + new tab toggle) is available |
| 👤 | Sidebar options (size, order, show) work |
| 👤 | Sort order offers name sorting only for "All albums", description sorting only for "Single album", and is hidden for "Multiple albums" |
| 👤 | Block on published page renders real gallery |
| 👤 | Block in editor shows placeholder (no real API calls) |

---

## 🔐 Password-Protected & Private Pages

| ✓ | Test |
|---|---|
| 🤖 | Without the password the page shows no photos; their image URLs answer 403 |
| 🤖 | After entering the password, photos load with `Cache-Control: private` |
| 🤖 | Image URL from a private page answers 403 for a visitor |
| 👤 | After entering the password, the lightbox and Live Photos work |
| 🤖 | Video mode **Shared links**: a video on a password page is streamed through WordPress (no shared link), 403 without the password |
| 👤 | Video mode **Shared links**: that video plays and seeks in the lightbox, also in Safari |
| 🤖 | Logged in as editor: photos on a private page and in a draft preview load |

---

## 🔒 Proxy & Security

| ✓ | Test |
|---|---|
| 🤖 | Invalid proxy type → HTTP 400 |
| 🤖 | Invalid UUID format → HTTP 400 |
| 🤖 | Unsigned URL, wrong signature, or a signature for another post → HTTP 403 |
| 🤖 | Thumbnails are served as `image/webp`, previews as `image/jpeg` |
| 👤 | Plugin not configured → HTTP 503 |
| 🤖 | REST `/albums` without login → HTTP 401/403 |
| 🤖 | REST `live-photo-url` with invalid ID → HTTP 400 |
| 🤖 | REST `live-photo-url` without `sig` → HTTP 400; with a wrong `sig` → HTTP 403 |
| 🤖 | Proxy `type=original` → HTTP 400 |
| 🤖 | Proxy `type=video` in video mode **Ignore videos** → HTTP 403 |
| 👤 | Page with `album="A"`: adding `?gallery_for_immich=<other album>` still shows album A |
| 👤 | Page with `albums="A,B"`: `?gallery_for_immich=<album C>` shows the overview, not album C |
| 👤 | Live Photo button still plays the video (the request carries a `sig` parameter) |
| 👤 | Video mode **shared**: reloading a page with videos reuses the same shared link (no new cleanup event per view) |
| 🤖 | API key not visible in page source |
| 🤖 | Plain permalinks: photos load and the Live Photo request (`?rest_route=`) reaches the endpoint |

---

## 🤖 Running the Test Suite

```bash
npm run wp-env:start   # if it is not running yet
npm run test
```

`tests/run.js` creates test pages in wp-env (a public album page, an overview, a password-protected page and a private page), runs the checks and removes the pages again. It also creates a temporary editor account. Afterwards it restores the plugin settings and the permalink structure, because the tests switch the video mode and try plain permalinks. In the mode Shared links one real, short-lived shared link is created in Immich; the plugin's cleanup job removes it.

- **Internals** (`tests/unit.php`, run with `wp eval-file`): attribute parsing (`limit`, `pick`, `show`, `detail_show`, the album from the URL, `link`), signatures, search request bodies for the flat and the structured Immich search API, version detection, caching and error messages.
- **HTTP** (`tests/run.js`): the proxy, password-protected and private pages (as a visitor and as a logged-in editor, including a draft preview), pretty and plain permalinks, the album-from-URL rule, the REST endpoints, and video in the modes Shared links, Stream through WordPress and Ignore videos.

Requirements: a working Immich server URL and API key in the plugin settings, and on that server at least two albums with three or more photos, plus one video for the streaming checks. Set `BASE_URL` to test another address than `http://localhost:8888`.

The tests talk to the real Immich server. A proxy answer of **502** means wp-env could not reach Immich at that moment (a network hiccup), not a plugin error; run the suite again.
