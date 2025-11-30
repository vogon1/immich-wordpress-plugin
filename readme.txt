=== Immich Gallery ===
Contributors: sietsevisser
Tags: gallery, photos, immich, albums, lightbox
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display your Immich photo albums and galleries in WordPress using simple shortcodes.

== Description ==

Immich Gallery is a WordPress plugin that seamlessly integrates your self-hosted Immich photo server with your WordPress site. Display beautiful photo galleries and albums using simple shortcodes.

**Key Features:**

* Display all albums from your Immich server
* Show specific albums by ID
* Display individual photos with EXIF data
* Beautiful responsive grid layouts
* Integrated lightbox with GLightbox
* Automatic sorting (albums by date, photos chronologically)
* Flexible sorting options (date/name, ascending/descending)
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

1. Upload the `immich-gallery` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Immich Gallery
4. Enter your Immich server URL (must be HTTPS)
5. Enter your Immich API key
6. Use shortcodes in your posts and pages

== Configuration ==

**Settings:**

1. Navigate to Settings > Immich Gallery in your WordPress admin
2. Enter your Immich server URL (e.g., https://immich.example.com)
3. Generate an API key in your Immich server settings
4. Paste the API key in the plugin settings
5. Save changes

**Security Note:** The plugin requires HTTPS for production servers. Localhost URLs are allowed for development.

== Usage ==

**Display all albums:**
`[immich_gallery]`

**Display specific albums (comma-separated IDs):**
`[immich_gallery albums="album-id-1,album-id-2"]`

**Display single album:**
`[immich_gallery album="album-id"]`

**Display single photo:**
`[immich_gallery asset="photo-id"]`

**Customize display options:**
`[immich_gallery show="gallery_name,gallery_description,asset_date,asset_description"]`

Available show options:
* `gallery_name` - Show album/gallery name
* `gallery_description` - Show album description
* `asset_date` - Show photo date
* `asset_description` - Show photo description

**Sort order:**
`[immich_gallery order="date_desc"]`
`[immich_gallery album="album-id" order="description_asc"]`

Available order options:
* `date_desc` - Newest first (default for albums)
* `date_asc` - Oldest first (default for photos - chronological order)
* `name_asc` - Alphabetically A-Z (albums only)
* `name_desc` - Alphabetically Z-A (albums only)
* `description_asc` - Alphabetically A-Z by description (photos only)
* `description_desc` - Alphabetically Z-A by description (photos only)

Note: Name sorting is only available for albums. Photos can be sorted by date or description.

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

== Screenshots ==

1. Album grid overview with thumbnails
2. Photo gallery with lightbox
3. Settings page configuration
4. Single photo display with EXIF data

== Changelog ==

= 0.4.0 =
*Release Date - 30 November 2025*

* Added Gutenberg block editor
* Visual block interface for easy gallery configuration
* Added album and thumb nail ordering options
* Options added for thumbnail size and text sizes
* Added German (de_DE) translation - complete internationalization
* Added French (fr_FR) translation - complete internationalization

= 0.3.1 =
*Release Date - 29 November 2025*

* Added flexible sorting with order parameter (none, date_asc, date_desc, name_asc, name_desc)
* Albums can be sorted by date or name, ascending or descending
* Photos can be sorted by date (ascending/descending) or use Immich's original order
* Default sorting: albums newest first (date_desc), photos oldest first (date_asc/chronological)
* Uses standard database terminology (asc/desc)
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
