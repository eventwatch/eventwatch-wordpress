=== EventWatch Events ===
Contributors: eventwatch
Tags: events, calendar, event list
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your events on your WordPress site and list them on EventWatch.

== Description ==

EventWatch Events lets you manage events on your own WordPress site. Add events from the block editor with their dates, venue, prefecture, and category. Drafts, publishing, scheduling, private posts, the trash, and revisions work the same way as for WordPress posts.

You can use the plugin without an EventWatch account. To connect your site to EventWatch, you need an EventWatch account and an API key. See "Connecting to EventWatch" below.

Setup guide: https://docs.eventwatch.app/en/wordpress/setup . Documentation for developers, including block attributes, templates, filters, and the API: https://docs.eventwatch.app/en/developers .

= Showing events on your site =

The Event List, Event Search, and Past Events blocks and the EventWatch patterns add your events to any page. Classic themes can use the shortcodes `[eventwatch_events]`, `[eventwatch_search]`, and `[eventwatch_past_events]`. Guide: https://docs.eventwatch.app/en/wordpress/blocks .

= Event pages =

Each published event has its own page at `/event/your-event/` with its dates, venue, map links, and buttons to add it to a calendar and to share it. The page also outputs schema.org `Event` data, and block themes can rearrange it in the Site Editor. Guide: https://docs.eventwatch.app/en/wordpress/event-pages .

= Maps =

By default, the event page only links to Google Maps and OpenStreetMap, and opening the page does not connect to any outside service. If you enter a Google Maps API key in Settings > EventWatch, the page embeds a Google map of the venue, and **your visitors' browsers connect to Google when they open the page**. See "External services" below.

The links under "Share" and "Add to calendar" open Google Calendar, X, LINE, or Facebook only when a visitor chooses them.

= Connecting to EventWatch =

To list your events on EventWatch, issue an API key on EventWatch under Organization settings > Site connection, and enter it in Settings > EventWatch. A key saved in the settings is also included in database backups and copies of your site. We recommend setting it in `wp-config.php` instead:

`define( 'EVENTWATCH_API_KEY', 'ewk_...' );`

= What is sent to EventWatch =

Once a key is set, events are sent when you publish them, including scheduled events at their publish time, and when you update them. EventWatch reviews them before listing them; your site shows the event as soon as you publish it. Withdrawing or deleting a published event also applies on EventWatch. Turn off "Send this post to EventWatch" to keep an event on your site only. Guide: https://docs.eventwatch.app/en/wordpress/sending .

By saving a key, you agree to the EventWatch Terms of Service ( https://eventwatch.app/en/terms ) and Posting Guidelines ( https://eventwatch.app/en/post/guidelines ). This also applies to a key set in `wp-config.php`.

Your site does not depend on EventWatch. Your pages and publishing keep working if EventWatch is unavailable, and visitors' browsers never connect to EventWatch for the event pages or blocks.

= External services =

This plugin uses two outside services. It contacts neither of them until you enter an API key for that service.

**EventWatch** (https://eventwatch.app) is the event listing service this plugin sends your events to. The plugin connects your server to the EventWatch API (`api.eventwatch.app`) only after you enter an EventWatch API key, and every request to the API carries that key. Below, the site ID is a random ID the plugin creates for your site, and the post ID is the WordPress ID of the event. The plugin connects:

* When an administrator uses "Check connection". The response gives your organization's name.
* When an event is published, including a scheduled event at its publish time, when a published event is updated, when an administrator uses "Send all to EventWatch", when a failed send is retried, and, for published events that were already sent, when the address of your site or its admin area, its permalink settings, or the URL prefix of its event pages changes. This sends the event's title, content, dates, time zone, notes on dates, category, prefecture, online setting, venue name and address, official page URL (the event's page on your site if you did not enter one), last-updated time, the site ID and post ID, and the addresses of your site and its admin area, and uploads its featured image file. EventWatch shows these two addresses to your organization's members, so they can find the site that sent an event.
* When an event that was sent is moved to draft, pending review, private, password-protected, or the trash, is scheduled for a later date, or is set not to be sent. This sends the site ID, the post ID, and the event's last-updated time, and EventWatch withdraws the event.
* When an event that was sent is permanently deleted. This sends the site ID and the post ID, and EventWatch deletes the event.
* When an event is opened in the editor, and about once an hour for up to 30 published events, to read the review status of events it has sent. These requests send the site ID and the post ID, and no event content.
* When an administrator uses "Import from EventWatch". The plugin reads your organization's posts, downloads their images from the addresses EventWatch returns, and sends the site ID, the post ID, the EventWatch post ID, and the addresses of your site and its admin area. If you edited or published a post before retrying an import that did not finish, the post is then sent in the same way as an updated event, unless it is still a draft that is not listed on EventWatch.
* When the plugin is deleted. This sends the site ID and tells EventWatch to detach this site's events and to remove the addresses of your site and its admin area. The events stay listed on EventWatch, and can be edited there.

Terms of use: https://eventwatch.app/en/terms . Privacy policy: https://eventwatch.app/en/privacy .

**Google Maps** (https://www.google.com/maps): by default, event pages do not connect to Google. Only if you enter a Google Maps API key in Settings > EventWatch, the event page embeds a Google map of the venue. Your visitors' browsers then connect to Google when the map loads, and send your Google Maps API key and the venue's address, or its name if there is no address. Like any connection, this also gives Google the visitor's IP address, and the browser tells Google which site the map is on. Events held online have no map. Google's terms and pricing apply to the key, and you are responsible for reviewing them. Terms of service: https://cloud.google.com/maps-platform/terms . Privacy policy: https://policies.google.com/privacy .

**Links on event pages**: the links to Google Maps, OpenStreetMap, Google Calendar, X, LINE, and Facebook, and, for events listed on EventWatch, the links to EventWatch, the App Store, and Google Play, open those services only when a visitor chooses them. Nothing is sent to them before that.

= Source code =

The source code, including the uncompressed JavaScript for the blocks and the editor, is at https://github.com/eventwatch/eventwatch-wordpress . The `build` folder in the plugin is generated from the `src` folder there with `npm ci` and `npm run build`.

= Review status =

The EventWatch panel in the editor and the EventWatch column in the event list show where each event stands. See "External services" for what the plugin reads. Guide: https://docs.eventwatch.app/en/wordpress/listing-status .

= Importing from EventWatch =

Administrators can bring posts your organization created on EventWatch into your site from Events › Import from EventWatch. Guide: https://docs.eventwatch.app/en/wordpress/import .

= Scheduled events =

WordPress publishes scheduled events with WP-Cron, which only runs when someone visits your site. On a site with few visitors, a scheduled event may be published later than the time you set. To publish on time, run WP-Cron from your server's cron: https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/ .

= Removing the plugin =

Deleting the plugin removes its settings, including the API key and the site ID, the send status of each event, and the capabilities it added to user roles. It also tells EventWatch to detach this site's events: they stay listed on EventWatch and you can edit them there. If EventWatch can't be reached, the plugin is still deleted; in that case, revoke the API key on EventWatch. Your events stay in the database, along with your choice not to send an event, unless you turn on "Delete all events when the plugin is deleted" in Settings > EventWatch. If you reinstall the plugin, the site gets a new site ID, so EventWatch treats it as a new site: when you publish the remaining events, they can be sent as separate posts.

= Translations =

The plugin includes a Japanese translation. Other languages come from translate.wordpress.org.

== Screenshots ==

1. The Event Search block lists your upcoming events and lets visitors search them.
2. Each event has its own page with dates, venue, map links, and buttons to add it to a calendar and share it.
3. Add the dates, venue, prefecture, and category in the block editor.
4. Settings > EventWatch. The plugin works without an API key; enter one to list your events on EventWatch.
5. The event list in the admin area shows each event's dates and prefecture.

== Changelog ==

= 0.1.0 =
* Plugin foundation.
