# Immich WordPress Plugin

This plugin allows you to easily integrate photos and albums from [Immich](https://immich.app/) into your WordPress site.  
A simple way to display galleries without uploading images manually.

## ✨ Features

- Display list of albums from Immich.
- Display entire albums from Immich.
- Display individual photos.
- Shortcode support for posts and pages.
- Configure Immich server URL and API key in the WordPress admin panel.
- Easy installation and updates via GitHub.

## 📦 Installation

1. Download the [latest release](https://github.com/vogon1/immich-wordpress-plugin/releases).
1. In WordPress, go to **Plugins > Add New > Upload Plugin**.
1. Upload the `.zip` file and activate the plugin.
1. Create an API key in Immich: **Account settings > API keys**.  
The API key must have 3 permissions: asset.read, asset.view and album.read
1. In wordpress go to **Settings > Immich** and enter your Immich url and API key.

## 🖼️ Usage

Use shortcode \[immich_gallery\] with optional parameters.
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
[immich_gallery]
```

Use the shortcode below to display a specific list of albums (no spaces around comma), of course with your album id's:

```text
[immich_gallery albums=3c874076-ba9e-410a-8501-ef3cca897bcb,3c874076-ba9e-410a-8501-ef3cca897bcc]
```

Use the shortcode below to display an album:

```text
[immich_gallery album=3c874076-ba9e-410a-8501-ef3cca897bcc]
```

If you want to show descriptions and dates of the photos:

```text
[immich_gallery album=3c874076-ba9e-410a-8501-ef3cca897bcd show=asset_description,asset_date]
```

Use the shortcode below to display just one photo:

```text
[immich_gallery asset=3c874076-ba9e-410a-8501-ef3cca897bcd]
```
