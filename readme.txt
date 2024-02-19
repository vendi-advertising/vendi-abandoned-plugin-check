=== Vendi Abandoned Plugin Check ===
Contributors: chrisvendiadvertisingcom
Tags: admin, abandoned, plugin
Requires at least: 6.3
Requires PHP: 7.0
Tested up to: 6.4
Stable tag: 4.0.0
License: GPLv2 or later

Helps find abandoned plugins by showing how many days since their last SVN update.

== Description ==

This plugin will query the WordPress.org servers in a background task to determine the number of days since the last SVN update.

This plugin has no interface. It only runs a background task daily and then modifies the main plugin table by adding the number of days since the plugin was last updated.

This plugin has not been tested with multi-site yet.

Feel free to contribute to this plugin on [GitHub](https://github.com/vendi-advertising/vendi-abandoned-plugin-check/)!

== Installation ==

1. Upload `plugin-name.php` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= Does this work with multisite? =

This has not been tested yet.

= Why does this check all plugins instead of just activated plugins? =

Old code is old code.

= Not all of my plugins are showing a last updated date =

This plugin queries the WordPress.org database via the [official API](http://codex.wordpress.org/WordPress.org_API). If your plugin is not listed in this database then we won't report a date on it.

Also, this plugin (as of 3.0.0) runs in batch mode, executing 10 plugins at a time. It attempts to respawn itself but depending on your configuration this may or may not work.

= I've activated this plugin, now what? =

Depending on how many plugins you have installed it might take a couple of minutes to find the last updated date of every installed plugin. Go to your Plugins page and refresh every couple of seconds. If you don't see notes after 5 minutes there might be an actual problem. If your server is not able to make remote calls this plugin won't work. Also, if you've disabled WordPress's scheduling system this plugin will not work.

== Screenshots ==

1. Example listing of plugins with some ages showing.
2. Example showing old plugins when searching.

== Changelog ==

= 4.0.0 =
* Require PHP 7 or greater
* WP 6.4 tested

= 3.7.2 =
* WP 6.3 tested

= 3.7.0 =
* Switching to GitHub for development, using actions for deploy to SVN

= 3.5.8 =
* WP 5.9 tested

= 3.5.7 =
* WP 5.7 tested

= 3.3.3 =
* WP 5.2 tested

= 3.3.2 =
* Silenced an error that could happen if the server is unable to resolve DNS to itself
* Fixed line breaks in readme
* WP 4.9.5 tested

= 3.3.0 =
* Changed API check to use SVN slug instead of Plugin slug. Thanks Scott Neader and Bob Lindner!
* Added an option to log actions for debugging at the code-level (beta)
* WP 4.5 tested

= 3.2.1 =
* WP 4.4 tested

= 3.2.0 =
* On this plugin update, force reset of stored timestamps

= 3.1.3 =
* WP4.3 test, version bump, no code changes

= 3.1.1 =
* Upon activation clean up any remaining previous installs just in case the plugin wasn't property deactivated

= 3.1.0 =
* Removed mu support for now so that we can use the register_activation_hook

= 3.0.0 =
* Cron jobs are now executed in batches to avoid timing out with lots of plugins
* Fixed some styling for highlighting abandoned plugins on the plugin search screen in WP 3.9 and less
* Renamed cron hooks and transients for clarity

= 2.0.0 =
* Added support for highlighting abandoned plugins on the plugin search screen

= 1.0.2 =
* Version bump for internal folder re-org

= 1.0.1 =
* Added icons

= 1.0.0 =
* Initial release
