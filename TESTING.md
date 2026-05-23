# Test Checklist — Gallery for Immich

Use this checklist for every new release or WordPress version upgrade.

> **🤖 = testable via CLI** (curl / WP-CLI in wp-env)  
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
| 🤖 | Connection test button returns green result |

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
| 🤖 | `albums="invalid-id"` produces no PHP errors |

---

## 📂 Album Detail

| ✓ | Test |
|---|---|
| 👤 | Photos render in grid |
| 👤 | `show="asset_date,asset_description"` shows date and description |
| 👤 | `order="date_desc"` reverses the order |
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
| 👤 | `show="asset_date"` shows the date |

---

## 🎬 Video

| ✓ | Test |
|---|---|
| 👤 | Video mode **shared**: video plays in lightbox |
| 👤 | Video mode **fopen**: video streams via WordPress |
| 👤 | Video mode **ignore**: videos are not shown |
| 👤 | Video autoplays when lightbox slide changes |
| 👤 | Previous video pauses when switching slides |

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
| 👤 | Sidebar options (size, order, show) work |
| 👤 | Block on published page renders real gallery |
| 👤 | Block in editor shows placeholder (no real API calls) |

---

## 🔒 Proxy & Security

| ✓ | Test |
|---|---|
| 🤖 | Invalid proxy type → HTTP 400 |
| 🤖 | Invalid UUID format → HTTP 400 |
| 🤖 | Plugin not configured → HTTP 503 |
| 🤖 | REST `/albums` without login → HTTP 401/403 |
| 🤖 | REST `live-photo-url` with invalid ID → HTTP 400 |
| 🤖 | API key not visible in page source |

---

## 🤖 Running CLI Tests

The security/proxy tests can be run in one go:

```bash
# Replace BASE_URL with your wp-env URL (default: http://localhost:8888)
BASE_URL=http://localhost:8888

# Invalid proxy type → expect HTTP 400
curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/?gallery_for_immich_proxy=invalid&id=00000000-0000-0000-0000-000000000001"

# Invalid UUID → expect HTTP 400
curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/?gallery_for_immich_proxy=thumbnail&id=not-a-uuid"

# REST albums without login → expect 401
curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/wp-json/gallery-for-immich/v1/albums"

# REST live-photo-url with invalid ID → expect 400
curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/wp-json/gallery-for-immich/v1/live-photo-url?asset_id=invalid"
```
