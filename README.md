# Immich WordPress Plugin

This plugin allows you to easily integrate photos and albums from [Immich](https://immich.app/) into your WordPress site.  
A simple way to display galleries without uploading images manually.

**Security & Privacy:** The Immich API key is stored securely in your WordPress database and is only used server-side to fetch photos from your Immich server. The API key is never exposed to website visitors or sent to their browsers. All image requests are proxied through WordPress, keeping your Immich server credentials completely private.

**Access Control:** The plugin displays photos and albums based on the permissions of the Immich user account that owns the API key. Only photos and albums that are visible to this specific Immich user will be accessible in WordPress. This means if your Immich server has multiple users, each with their own private collections, only the albums shared with or owned by the API key's user account can be displayed on your WordPress site.

## ✨ Features

- Display list of albums from Immich.
- Display entire albums from Immich.
- Display individual photos.
- Flexible sorting options (date/name, ascending/descending).
- Shortcode support for posts and pages.
- Visual Gutenberg block editor.
- Configure Immich server URL and API key in the WordPress admin panel.
- Multi-language support (Dutch, German, French).

## 📦 Installation

### From WordPress.org (Recommended)

1. In WordPress, go to **Plugins > Add New**
2. Search for "Immich Gallery"
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
   - `asset.download` - Required to retrieve original full-size photos

   **Note:** The plugin only needs read-only access. Never grant write permissions for security reasons.

1. Click **Create** and copy the generated API key
1. In WordPress, go to **Settings > Immich Gallery** and enter:
   - Your Immich server URL (e.g., `https://immich.example.com`)
   - The API key you just created
1. Save the settings

## 🖼️ Usage

### Using the Gutenberg Block Editor (Recommended)

The easiest way to add an Immich gallery is through the Gutenberg block editor:

1. Add a new block and search for **"Immich Gallery"**
2. Select your display mode:
   - **All albums overview** - Show all albums from your Immich server
   - **Single album** - Display photos from one specific album
   - **Multiple albums** - Show a curated selection of albums
   - **Single photo** - Display a single image
3. Configure display options in the sidebar:
   - **Show options**: Choose what to display (defaults: gallery name, asset description)
   - **Sort order**: Control the sorting of albums/photos
   - **Thumbnail size**: Adjust thumbnail size (100-500px, default: 200px)
   - **Text sizes**: Customize title, description, and date font sizes
4. The preview shows the shortcode that will be used

### Using Shortcodes

You can also use shortcodes directly in your content:

**Basic Usage:**

```text
[immich_gallery]
```

Shows a list of all albums with thumbnails.

**Display specific albums:**

```text
[immich_gallery albums=3c874076-ba9e-410a-8501-ef3cca897bcb,3c874076-ba9e-410a-8501-ef3cca897bcc]
```

**Display single album:**

```text
[immich_gallery album=3c874076-ba9e-410a-8501-ef3cca897bcc]
```

**Display single photo:**

```text
[immich_gallery asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```

**Customize display options:**

```text
[immich_gallery show="gallery_name,asset_description"]
```

Available show options (defaults: `gallery_name`, `asset_description`):

- `gallery_name` - Show the name of the album
- `gallery_description` - Show description of the album
- `asset_description` - Show description of photo/video
- `asset_date` - Show date the photo/video was taken

**Customize sizes:**

```text
[immich_gallery size="300" title_size="18" description_size="15" date_size="12"]
```

Size options:

- `size` - Thumbnail size in pixels (100-500, default: 200)
- `title_size` - Title font size (10-30, default: 16)
- `description_size` - Description font size (10-30, default: 14)
- `date_size` - Date font size (10-30, default: 13)

**Sorting options:**

```text
[immich_gallery order="date_desc"]
```

Available order options:

- `date_desc` - Newest first (default for albums)
- `date_asc` - Oldest first (default for photos - chronological order)
- `name_asc` - Alphabetically A-Z (albums only)
- `name_desc` - Alphabetically Z-A (albums only)
- `description_asc` - Alphabetically A-Z by description (photos only)
- `description_desc` - Alphabetically Z-A by description (photos only)

**Note:** Name sorting is only available for album lists. Photos can be sorted by date or description.

## Examples

Sort albums alphabetically:

```text
[immich_gallery order="name_asc"]
```

Show photos in chronological order (oldest first):

```text
[immich_gallery album=3c874076-ba9e-410a-8501-ef3cca897bcd order=date_asc]
```

Use the shortcode below to display just one photo:

```text
[immich_gallery asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```
