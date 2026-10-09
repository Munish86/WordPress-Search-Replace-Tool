=== WordPress Search & Replace Tool ===
Contributors: custom
Tags: search replace, url replace, database, admin tool
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

A private admin-only tool to search, count and safely replace URLs or text in posts, pages and post meta.

== Installation ==
1. Go to WordPress Dashboard > Plugins > Add New > Upload Plugin.
2. Upload the ZIP file.
3. Activate the plugin.
4. Open Search & Replace from the left-side dashboard menu.
5. Create a backup before replacing anything.

== Features ==
* Search wp_posts and wp_postmeta.
* Select the database field.
* Filter by content type, post status and a specific post/page.
* Count matching rows and total occurrences.
* Review matching records before replacement.
* Serialized post meta handling.
* Administrator-only access and nonce protection.
* Post/Redirect/Get workflow prevents form resubmission warnings.
