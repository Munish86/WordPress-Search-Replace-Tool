=== Scoped Search & Replace ===
Contributors: munish86
Tags: search replace, replace url, migration, database, find replace
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search, count and safely replace URLs or text in posts, pages and post meta, only inside the table, field, post type, status or post you pick.

== Description ==

Scoped Search & Replace lets administrators find and replace URLs or text in the WordPress database without touching anything outside the area they choose.

Typical uses: moving a site to a new domain, switching from `http://` to `https://`, fixing a misspelled URL, or updating a phone number or brand name across many pages.

**Search first, then replace**

1. Pick where to look: the posts table (content, title or excerpt) or post meta (custom field values).
2. Narrow it down by content type, post status, or one specific page or post.
3. Search and count: see how many rows match, how many times the text appears, and a list of every matching record with Edit and View links.
4. Replace: the change runs only inside the same scope, after a confirmation that shows the old value, new value and scope.

**Built to be safe**

* Only users who can manage options (administrators) can see or use the tool.
* Every request is protected with a nonce, and all table and field choices are checked against a fixed list.
* Serialized post meta (used by many page builders and plugins) is unserialized, replaced and serialized again, so string lengths stay correct and the data is not broken.
* Rows whose serialized data is already damaged are skipped and reported, never overwritten.
* Search uses exact, case-sensitive matching, so the count you see is what will be replaced.
* The results page uses Post/Redirect/Get, so refreshing the page never runs a replacement twice.

**What it does not touch**

Options, users, comments, terms and custom tables are not searched or changed. The plugin stores no settings of its own.

Always take a full backup of your database before replacing anything.

== Installation ==

1. In your WordPress dashboard, go to Plugins > Add New Plugin and search for "Scoped Search & Replace", or upload the plugin ZIP file under Plugins > Add New Plugin > Upload Plugin.
2. Activate the plugin.
3. Open **Search & Replace** from the left-hand admin menu.
4. Take a backup, run a search first, then replace.

== Frequently Asked Questions ==

= Can I undo a replacement? =

No. Changes are written straight to the database. Take a backup first, and run a search to review the matching records before replacing. You can also replace the new value back with the old one, within the same scope.

= Does it work with page builders like Elementor? =

Yes, when the builder stores its data in post meta. Choose the post meta table (Custom Field / Meta Value). Serialized values are handled safely. Some builders also keep generated CSS files; regenerate them from the builder's settings after a replacement.

= Is the search case-sensitive? =

Yes. "Example.com" and "example.com" are treated as different text.

= Does it search the options table, widgets or menus? =

No. The tool works on posts, pages, other public post types and their post meta only. This keeps replacements limited and predictable.

= Who can use the tool? =

Only users with the `manage_options` capability, which by default means administrators.

= Does it work on multisite? =

Yes. It works on the site you are currently viewing; each site in the network is searched and changed separately.

= What happens to my data when I delete the plugin? =

The plugin removes its own temporary data (short-lived search results). Your content, including any replacements you made, is left as it is.

== Screenshots ==

1. Search and count: choose the table, field, content type, status and page, then see matching rows and total occurrences.
2. Find and replace inside the same scope, with a confirmation before anything changes.
3. Search results with Edit and View links for every matching record.

== Changelog ==

= 1.1.0 =
* Renamed to Scoped Search & Replace for the WordPress.org plugin directory.
* All text is now translatable (text domain `scoped-search-replace`).
* Rows with damaged serialized data are skipped and reported instead of being overwritten.
* Fixed: a post meta value stored as a serialized plain string is now kept serialized after a replacement.
* Added an "Open tool" link on the Plugins screen.
* Added uninstall cleanup of temporary data.
* Faster loading of the page/post list on large sites.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Safer handling of damaged serialized data, translation support and uninstall cleanup.
