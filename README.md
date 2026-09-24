# Gallery for Immich

This plugin allows you to easily integrate photos and albums from [Immich](https://immich.app/) into your WordPress site.  
A simple way to display galleries without uploading images manually.

**Security & Privacy:** The Immich API key is stored securely in your WordPress database and is only used server-side to fetch photos from your Immich server. The API key is never exposed to website visitors or sent to their browsers. All image requests are proxied through WordPress, keeping your Immich server credentials completely private.

**Access Control:** The plugin displays photos and albums based on the permissions of the Immich user account that owns the API key. Only photos and albums that are visible to this specific Immich user will be accessible in WordPress. This means if your Immich server has multiple users, each with their own private collections, only the albums shared with or owned by the API key's user account can be displayed on your WordPress site.

**Using a separate display user:** You can point the plugin at a secondary Immich account that only has albums shared with it — this keeps your main account's API key off the WordPress server. One caveat: Immich only allows *shared links* to be created for assets a user actually **owns**, not for assets merely shared with them. Photos always work, but videos in the default **Shared links** mode will not play for a display user. Choose video mode **Proxy via fopen** or **Ignore videos** in that case. The connection test on the settings page flags this situation explicitly.

## ✨ Features

- Display list of albums from Immich
- Display entire albums from Immich
- Flexible sorting options (date/name, ascending/descending)
- Limit the number of albums or photos shown, either the first ones or a random selection
- Beautiful responsive grid layouts with integrated lightbox
- **Apple Live Photos** - Play the video component of Live Photos directly in the lightbox
- Video playback modes (shared links, proxy via fopen, or ignore videos)
- **Single photo embedding** - Display one photo with alignment (left/right/center) and configurable link behavior (lightbox, no link, or custom URL)
- Configure Immich server URL and API key in the WordPress admin panel
- **Connection & permissions check** - Test your API key and verify all required permissions from the settings page
- **Gutenberg Block Editor** - Visual block for selecting albums, photos and settings
- **Shortcode Support** - Classic `[gallery_for_immich]` shortcode works in any editor
- Multi-language support (Dutch, German, French)

## 📦 Installation

### From WordPress.org (Recommended)

1. In WordPress, go to **Plugins > Add New**
2. Search for "Gallery for Immich"
3. Click **Install Now** and then **Activate**

### Manual Installation

1. Download the [latest release](https://github.com/vogon1/immich-wordpress-plugin/releases).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**.
3. Upload the `.zip` file and activate the plugin.

### 🔑 Creating and installing an Immich API Key

1. Log in to your Immich server with the user account whose photos you want to display
1. Go to **Account Settings** (click your profile picture in the top right)
1. Navigate to **API Keys** tab
1. Click **New API Key**
1. Give it a descriptive name (e.g., "WordPress Plugin")
1. Set the following **minimum required permissions**:
   - `album.read` - Required to list and view albums
   - `asset.read` - Required to access photo metadata (EXIF data, descriptions, dates)
   - `asset.view` - Required to retrieve photo thumbnails
   - `sharedLink.create` - Required when using video mode 'Shared Link'
   - `sharedLink.delete` - Required when using video mode 'Shared Link'

   **Note:** The plugin only needs read-only access. Never grant write permissions for security reasons.

   **Note:** `sharedLink.create` and `sharedLink.delete` only work for photos and videos this Immich user **owns**. If you use a separate display user with albums shared to it, pick a different video mode (see *Video playback modes* below).

1. Click **Create** and copy the generated API key
1. In WordPress, go to **Settings > Gallery for Immich** and enter:
   - Your Immich server URL (e.g., `https://immich.example.com`)
   - The API key you just created
1. Save the settings

### Video playback modes

Configure **Settings > Gallery for Immich > Video playback**:

- **Shared links (default):** creates temporary shared links on Immich. Videos stream directly from Immich and links expire automatically. Requires that the API key's Immich user **owns** the videos — this mode does not work for albums that are only shared with that user.
- **Proxy via fopen:** streams videos through WordPress. Moste elagant solution, but requires `fopen` support on Wordpress server which is not always supported.
- **Ignore videos:** hides videos from galleries and only shows photos.

## 🖼️ Usage

### Using the Gutenberg Block Editor (Recommended)

The easiest way to add an Immich gallery is through the Gutenberg block editor:

1. Add a new block and search for **"Gallery for Immich"**
2. Select your display mode:
   - **All albums overview** - Show all albums from your Immich server
   - **Single album** - Display photos from one specific album
   - **Multiple albums** - Show a curated selection of albums
   - **Single photo** - Display a single image
3. Configure display options in the sidebar:
   - **Show options**: Choose what to display (defaults: gallery name, asset description). For album overviews, turn off **Same on album page** to choose different texts for the page that opens when an album is clicked
   - **Sort order**: Control the sorting of albums/photos
   - **Maximum number** (albums/photos): Limit how many are shown, taking the first ones in sort order or a random selection
   - **Thumbnail size** (albums) / **Max width** (single photo): Adjust size (default: 200px)
   - **Text sizes**: Customize title, description, and date font sizes
   - **Alignment** (single photo): Place the photo left, right, or center for text wrapping
   - **Link behavior** (single photo and single album): Lightbox (default), no link, or custom URL — with a toggle to open the custom URL in a new tab (default) or the same tab
4. The preview shows the shortcode that will be used

### Using Shortcodes

You can also use shortcodes directly in your content:

**Basic Usage:**

```text
[gallery_for_immich]
```

Shows a list of all albums with thumbnails.

**Display specific albums:**

```text
[gallery_for_immich albums=3c874076-ba9e-410a-8501-ef3cca897bcb,3c874076-ba9e-410a-8501-ef3cca897bcc]
```

**Display single album:**

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcc]
```

**Display single photo:**

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```

**Single photo — alignment (wrap text around the photo):**

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd align="left"]
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd align="right"]
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd align="center"]
```

Available `align` values: `left`, `right`, `center` (default: no float).

**Single photo — link behavior:**

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd link="none"]
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd link="https://example.com/my-page"]
```

Available `link` values:

- *(omit)* or `lightbox` — opens the full-size photo in a lightbox overlay (default)
- `none` — displays the photo without any link
- `https://...` — wraps the photo in a link to your URL

**Single photo — link target (custom URL only):**

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd link="https://example.com/my-page" link_target="same"]
```

Available `link_target` values:

- `new` — opens the link in a new tab (default)
- `same` — opens the link in the current tab, e.g. for a visual menu to other pages on your own site

**Customize display options:**

```text
[gallery_for_immich show="gallery_name,asset_description"]
```

Available show options (no defaults - must be explicitly specified):

- `gallery_name` - Show the name of the album
- `gallery_description` - Show description of the album
- `asset_description` - Show description of photo/video
- `asset_date` - Show date the photo/video was taken

**Note:** If the `show` parameter is not specified, only thumbnails are displayed without any text.

**Different texts on the album page:**

```text
[gallery_for_immich show="gallery_name" detail_show="gallery_name,gallery_description,asset_date"]
```

When a visitor clicks an album in an overview, the album opens on the same page with the same `show` options. `detail_show` sets different options for that album page — here the overview shows only album names, and the album page adds the description and photo dates. It takes the same values as `show`; `detail_show=""` shows no text on the album page. Without `detail_show`, `show` applies to both.

**Customize sizes:**

```text
[gallery_for_immich size="300" title_size="18" description_size="15" date_size="12"]
```

Size options:

- `size` - Thumbnail size in pixels for albums (100–500, default: 200); max-width in pixels for single photos (100–1200, default: 200)
- `title_size` - Title font size (10-30, default: 16)
- `description_size` - Description font size (10-30, default: 14)
- `date_size` - Date font size (10-30, default: 13)

**Sorting options:**

```text
[gallery_for_immich order="date_desc"]
```

Available order options:

- `date_desc` - Newest first (default for albums)
- `date_asc` - Oldest first (default for photos - chronological order)
- `name_asc` - Alphabetically A-Z (albums only)
- `name_desc` - Alphabetically Z-A (albums only)
- `description_asc` - Alphabetically A-Z by description (photos only)
- `description_desc` - Alphabetically Z-A by description (photos only)

**Note:** Name sorting is only available for album lists. Photos can be sorted by date or description.

**Limit the number of albums or photos:**

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcd limit="10" order="date_desc"]
[gallery_for_immich limit="3" pick="random"]
```

- `limit` - Maximum number of albums (overview) or photos (album), 1–1000. Omit to show all.
- `pick` - Which ones to show when `limit` is set: `first` (default) takes the first ones in the sort order, `random` shows a random selection, still displayed in the sort order.

For albums sorted by date, or with `pick="random"`, only the requested photos are fetched from Immich — a `limit="10"` on a large album is one small request. Sorting by description still needs the whole album to be fetched first.

`limit` applies to the level the shortcode defines: on an overview it limits the number of albums, and an album opened from that overview shows all of its photos.

**Album — link behavior:**

The `link` and `link_target` attributes also work for albums, with the same values as for single photos. Combined with `limit`, this shows e.g. the latest photo of an album on your home page, linking to your full gallery page:

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcd limit="1" order="date_desc" link="https://example.com/gallery" link_target="same"]
```

A custom URL applies to every photo in the album. `link` does not affect album overviews, and an album opened from an overview always uses the lightbox.

## Examples

Sort albums alphabetically:

```text
[gallery_for_immich order="name_asc"]
```

Show photos in chronological order (oldest first):

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcd order=date_asc]
```

Use the shortcode below to display just one photo:

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```

## 📋 Changelog

### 0.9.0

- Tested against WordPress 7.1.2 and Immich v3.2.2.
- New: `limit=` and `pick=` — show only a number of albums or photos, the first ones in the sort order or a random selection. For albums sorted by date, or with `pick="random"`, only the requested photos are fetched from Immich. (issue #13)
- New: `detail_show=` — choose different texts for the album page that opens when a visitor clicks an album in an overview.
- New: `link=` also works for albums, so e.g. the latest photo of an album can link to your full gallery page.
- New: `link_target=` — open a custom link in the same tab or a new tab (default). (issue #6)
- Improved: the block editor only offers the sort options that apply to the chosen display mode, and explains that "Multiple albums" keeps the order in which you select them.
- Security: an album page opened from a URL is now limited to the albums the shortcode actually shows.
- Security: the Live Photo endpoint only serves videos of photos shown on the site, and shared links for videos are reused instead of created on every page view.
- Security: the image proxy no longer serves original files, and only streams videos in the *Proxy via fopen* mode.
- Fix: block editor translations could fall back to an older version after a build.

### 0.8.2

- Tested against WordPress 7.1 and Immich v3.1.
- Fix: Photos that were rotated, cropped or filtered in Immich were displayed in their original, unedited form. The plugin now asks Immich for the edited render (`edited=true`, supported since Immich v2.5). (issue #15)
- Fix: Because proxied images carry a one-year browser cache header, an edited photo stayed stale for visitors who had already seen it. Image URLs now include the asset's modification timestamp, giving every version its own URL.
- Fix: When Immich refused to create a shared link for a video, the plugin aborted the page render with a 502 halfway through the HTML. It now falls back to showing the thumbnail without a lightbox link.
- Fix: The connection & permissions check reported `sharedLink.create` as missing when the API key's Immich user can view an album but does not own it. That is now a warning with an explanation rather than a false negative.

### 0.8.1

- Fix: Album detail pages showed "No photos found" on Immich v3+, because Immich removed the `assets` property from the album API response. Assets are now fetched via the metadata search endpoint instead. Tested against Immich v2.7.5 and v3.0.1. (issue #14)

### 0.8.0

- New: `link=` attribute for single photos — choose between lightbox (default), no link, or a custom URL
- New: Single photos now always display the Immich preview image (web-optimised); `size` controls max-width (up to 1200px)
- New: `align=` attribute for single photos — float left, right, or center for text wrapping
- Fix: Image proxy output buffering issue and CSS typos (PR #11)
- Fix: Connection test false negatives on Immich v2.7+ (PR #12)

### 0.7.0

- Added Apple Live Photos support: a play button appears in the lightbox for photos that have a Live Photo video component
- Added connection & permissions check button on the settings page
- HTTP server URLs are now allowed (with a confirmation warning)
- Removed `asset.download` as a required API permission (no longer needed)
