=== Gallery for Immich ===
Contributors: sietsevisser
Tags: gallery, photos, immich, albums, lightbox
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display your Immich photo albums and galleries in WordPress using simple shortcodes.

== Description ==

Gallery for Immich is a WordPress plugin that seamlessly integrates your self-hosted Immich photo server with your WordPress site. Display beautiful photo galleries and albums using simple shortcodes.

**Note:** This plugin is not affiliated with or endorsed by Immich. It is an independent integration tool for WordPress users.

**Key Features:**

* Display all albums from your Immich server
* Show specific albums by ID
* Display individual photos with EXIF data
* Beautiful responsive grid layouts
* Integrated lightbox with GLightbox
* Photo descriptions and dates in lightbox
* Automatic sorting (albums by date, photos chronologically)
* Full internationalization support (includes Dutch translation)
* Secure API integration with validation
* HTTPS enforcement for production use

**Perfect for:**

* Photography portfolios
* Family photo sharing
* Event galleries
* Travel blogs
* Any self-hosted photo management with Immich

== Installation ==

1. Install via WordPress admin: Plugins > Add New > Search for "Gallery for Immich"
2. Click "Install Now" and then "Activate"
3. Or upload the plugin files to `/wp-content/plugins/gallery-for-immich/` directory and activate through the 'Plugins' menu

== Configuration ==

**Step 1: Create an Immich API Key**

1. Log in to your Immich server with the user account whose photos you want to display
2. Go to **Account Settings** (click your profile picture in the top right)
3. Navigate to **API Keys** tab
4. Click **New API Key**
5. Give it a descriptive name (e.g., "WordPress Plugin")
6. Set the following **minimum required permissions**:
   * `album.read` - Required to list and view albums
   * `asset.read` - Required to access photo metadata (EXIF data, descriptions, dates)
   * `asset.view` - Required to retrieve photo thumbnails
   * `asset.download` - Required to retrieve original full-size photos
7. **Important:** Only grant read-only access. Never grant write permissions for security reasons.
8. Click **Create** and copy the generated API key

**Step 2: Configure the Plugin in WordPress**

1. Navigate to Settings > Gallery for Immich in your WordPress admin
2. Enter your Immich server URL (e.g., https://immich.example.com)
3. Paste the API key you created in Step 1
4. Save changes

**Security Note:** The plugin requires HTTPS for production servers. Localhost URLs (http://localhost or http://127.0.0.1) are allowed for development only.

== Usage ==

**Display all albums:**
`[gallery_for_immich]`

**Display specific albums (comma-separated IDs):**
`[gallery_for_immich albums="album-id-1,album-id-2"]`

**Display single album:**
`[gallery_for_immich album="album-id"]`

**Display single photo:**
`[gallery_for_immich asset="photo-id"]`

**Customize display options:**
`[gallery_for_immich show="gallery_name,gallery_description,asset_date,asset_description"]`

Available show options:
* `gallery_name` - Show album/gallery name
* `gallery_description` - Show album description
* `asset_date` - Show photo date
* `asset_description` - Show photo description

== Frequently Asked Questions ==

= What is Immich? =

Immich is a self-hosted photo and video backup solution. Learn more at https://immich.app

= Do I need my own Immich server? =

Yes, this plugin requires a running Immich server with API access.

= How do I find my album or photo IDs? =

You can find IDs in your Immich server's URL when viewing albums or photos, or through the Immich API.

= Does this work with localhost? =

Yes, for development purposes localhost URLs (http://localhost or http://127.0.0.1) are allowed. Production servers must use HTTPS.

= Can I customize the appearance? =

Yes, the plugin generates standard HTML with CSS classes. You can override styles in your theme's CSS.

= Is the plugin translated? =

Yes, the plugin is fully internationalized and includes Dutch (nl_NL) translations. Additional translations can be added via .po files.

== Third Party Libraries ==

This plugin includes GLightbox v3.2.0 for the lightbox functionality.
Copyright (c) 2018 Biati Digital
Licensed under MIT License
https://github.com/biati-digital/glightbox

== Screenshots ==

1. Album grid overview with thumbnails
2. Photo gallery with lightbox
3. Settings page configuration
4. Single photo display with EXIF data

== Changelog ==

= 0.3.2 =
* Release Date - 8 December 2025*
* Changed plugin slug from 'immich-gallery' to 'gallery-for-immich' (WordPress naming policy compliance)
* Changed shortcode from [immich_gallery] to [gallery_for_immich]
* Updated all internal naming conventions
* Added disclaimer about non-affiliation with Immich

= 0.3.1 =
*Release Date - 29 November 2025*

* WordPress.org compliance: bundled GLightbox locally (no CDN dependencies)
* Removed load_plugin_textdomain() - WordPress.org handles translations automatically
* Removed debug error_log() calls for production
* Full WordPress Plugin Check compliance with zero errors
* Ready for WordPress.org plugin directory submission

= 0.3.0 =
*Release Date - 29 November 2025*

* WordPress.org compliance: bundled GLightbox locally (no CDN dependencies)
* Removed load_plugin_textdomain() - WordPress.org handles translations automatically
* Removed debug error_log() calls for production
* Full WordPress Plugin Check compliance with zero errors
* Ready for WordPress.org plugin directory submission

= 0.3.0 =
*Release Date - 29 November 2025*

* Disabled zoom functionality in lightbox (removed confusing zoom icon)
* Improved lightbox user experience with cleaner interface
* Code hardening for enhanced security
* Enhanced documentation with API key setup instructions
* Added security and privacy information in README
* Added access control documentation
* Updated installation instructions with detailed API key permissions

= 0.2.1 =
*Release Date - 29 November 2025*

* Enhanced security: UUID validation for all IDs
* Added HTTPS enforcement for production
* Improved input sanitization and validation
* Added timeout settings for API requests
* SSL verification enabled
* Better error handling and logging
* Security headers for image proxies
* Sanitization callbacks for settings
* Capability checks for admin access

= 0.2.0 =
*Release Date - 27 September 2025*

* Added photo sorting by dateTimeOriginal (chronological order)
* Added album sorting by endDate (newest first)
* Improved photo descriptions in lightbox
* Added EXIF date display
* Enhanced grid layouts with CSS Grid
* Added hover effects and animations
* Portrait photo support with object-fit

= 0.1.0 =
*Release Date - September 2025*

* Initial release
* Basic album and photo display
* GLightbox integration
* Shortcode support
* Internationalization support
* Dutch translation included

== Upgrade Notice ==

= 0.3.0 =
Improved lightbox experience and enhanced documentation. Recommended update for better usability.

= 0.2.1 =
Important security update with enhanced validation and HTTPS enforcement. Update recommended for all users.

= 0.2.0 =
Adds chronological photo sorting and improved album organization. Recommended update for better user experience.

== Privacy & Security ==

This plugin:
* Connects to your self-hosted Immich server via API
* Does not collect or transmit any user data to third parties
* Requires HTTPS for production security
* Validates all user input and API parameters
* Uses WordPress security best practices

Your Immich server credentials (URL and API key) are stored in your WordPress database.

== Support ==

For issues, feature requests, or contributions, please visit:
https://github.com/vogon1/immich-wordpress-plugin

== Credits ==

* Lightbox powered by GLightbox (https://github.com/biati-digital/glightbox)
* Integrates with Immich (https://immich.app)
