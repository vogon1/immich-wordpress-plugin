# Gallery for Immich

This plugin allows you to easily integrate photos and albums from [Immich](https://immich.app/) into your WordPress site.  
A simple way to display galleries without uploading images manually.

**Note:** This plugin is not affiliated with or endorsed by Immich. It is an independent integration tool for WordPress users.

**Security & Privacy:** The Immich API key is stored securely in your WordPress database and is only used server-side to fetch photos from your Immich server. The API key is never exposed to website visitors or sent to their browsers. All image requests are proxied through WordPress, keeping your Immich server credentials completely private.

**Access Control:** The plugin displays photos and albums based on the permissions of the Immich user account that owns the API key. Only photos and albums that are visible to this specific Immich user will be accessible in WordPress. This means if your Immich server has multiple users, each with their own private collections, only the albums shared with or owned by the API key's user account can be displayed on your WordPress site.

## ✨ Features

- Display list of albums from Immich.
- Display entire albums from Immich.
- Display individual photos.
- Shortcode support for posts and pages.
- Configure Immich server URL and API key in the WordPress admin panel.
- Easy installation and updates via GitHub.

## 📦 Installation

### From WordPress Plugin Directory (Recommended)

1. In WordPress, go to **Plugins > Add New**
2. Search for "Gallery for Immich"
3. Click **Install Now**, then **Activate**

### From GitHub Release

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

Use shortcode \[gallery_for_immich\] with optional parameters.
Possible parameters (use none or only one):

- (none): - Show a list of all albums with thumbnails
- albums=id1,id2,... - Show a list of given albums. The id's are the id's of the albums as found in Immich url of your album.
- album=id - Show thumbnails of all photos/videos in the album
- asset=id - Show only one photo. The id is shown in the url when visiting the photo or video on Immich

You can add a parameter called 'show', it tells what to show on the page:

- gallery_name - Show the name of the album (when albums are listed)
- gallery_description - Show description of the album(s)
- asset_description - Show description of photo/video (in album view)
- asset_date - Show date the photo/video was taken (on photo list of an album)

The parameter 'show' defaults to 'name'.

## Examples

Use the shortcode below to display a list of all albums with thumbnails:

```text
[gallery_for_immich]
```

Use the shortcode below to display a specific list of albums (no spaces around comma), of course with your album id's:

```text
[gallery_for_immich albums=3c874076-ba9e-410a-8501-ef3cca897bcb,3c874076-ba9e-410a-8501-ef3cca897bcc]
```

Use the shortcode below to display an album:

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcc]
```

If you want to show descriptions and dates of the photos:

```text
[gallery_for_immich album=3c874076-ba9e-410a-8501-ef3cca897bcd show=asset_description,asset_date]
```

Use the shortcode below to display just one photo:

```text
[gallery_for_immich asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```
