Hero Carousel Mini Plugin
=========================

What this plugin does:
- registers hero_carousel custom post type
- registers ACF fields for hero carousel items
- adds CTA mode toggle per item (carousel_show_button)
- adds enabled/disabled switch per item (carousel_enabled)
- exposes image_url, image_alt, carousel_title, carousel_link, carousel_enabled, carousel_show_button in REST API
- returns only enabled items in default REST view responses
- provides a combined frontend feed (manual hero items + posts from Clanky + posts from Galeria) at /wp-json/hcm/v1/carousel (cached)
- renders the carousel via the [hero_carousel] shortcode with editable CSS/JS and a global image aspect ratio setting
- adds useful admin columns in WP admin list
- supports native manual ordering for Hero items via WordPress Order (menu_order)

Requirements:
- WordPress 6+
- ACF plugin active

Installation:
1. Copy folder hero-carousel-mini-plugin to wp-content/plugins/
2. Activate plugin in WP Admin -> Plugins
3. Go to Settings -> Permalinks and click Save
4. Add items in WP Admin -> Hero Carousel
5. Configure counts / CSS / JS / aspect ratio in Hero Carousel -> Settings
6. Place the [hero_carousel] shortcode where the carousel should appear

Endpoint:
- Combined feed: /wp-json/hcm/v1/carousel

Important:
- remove equivalent code from theme functions.php to avoid duplicates

Release build:
- Run `build-release.ps1` from the parent project folder.
- The script increments the minor version, updates the plugin version metadata and this release
  notes section, then creates and validates a ZIP under `Releases/`.
- The ZIP keeps `hero-carousel-mini-plugin` as its root folder.

Release Notes:
- 3.9.0
	- version bump to 3.9.0
- 3.8.0
	- version bump to 3.8.0
- 3.7.0
	- version bump to 3.7.0
- 3.6.0
	- version bump to 3.6.0
- 3.5.0
	- version bump to 3.5.0
- 3.4.0
	- version bump to 3.4.0
- 3.3.0
	- version bump to 3.3.0
- 3.2.0
	- version bump to 3.2.0
- 3.1.0
	- version bump to 3.1.0
- 3.0.0
	- version bump to 3.0.0
- 2.14.0
	- version bump to 2.14.0
- 2.13.0
	- version bump to 2.13.0
- 2.12.0
	- version bump to 2.12.0
- 2.10.0
	- version bump to 2.10.0
- 2.8.0
	- version bump to 2.8.0
- 2.7.0
	- version bump to 2.7.0
- 2.6.0
	- version bump to 2.6.0
- 2.5.0
	- version bump to 2.5.0
- 2.4.0
	- version bump to 2.4.0
- 2.3.0
	- version bump to 2.3.0
- 2.2.0
	- moved the combined frontend feed to a dedicated route /wp-json/hcm/v1/carousel to avoid colliding with the default hero_carousel CPT endpoint
	- scoped the frontend script per carousel instance so multiple [hero_carousel] shortcodes on one page no longer conflict
	- made frontend init robust when the carousel is injected after page load
	- added transient caching for the combined feed with automatic invalidation on post save and settings save
	- resolved manual item links consistently (relative paths like /blogy now resolve the same way everywhere)
	- removed the unused /hero_carousel_posts endpoint and legacy hcm_posts_count option
	- existing installs: the stored JS endpoint URL is migrated automatically on upgrade
- 2.1.1
	- changed aspect ratio setting to dropdown-only choice with allowed values 3:4, 1:1, 4:3
	- restricted backend validation to the same allowed ratio values only
- 2.1.0
	- added one global WP admin setting for carousel image aspect ratio (format width:height, e.g. 3:4, 1:1, 4:3)
	- applied ratio setting to main slides and thumbnails while preserving center crop behavior
	- updated responsive desktop sizing logic to support configurable aspect ratio
- 2.0.0
	- changed carousel transition from horizontal slide to fade with white flash overlay
	- updated carousel layout to full width of parent container (edge-to-edge)
