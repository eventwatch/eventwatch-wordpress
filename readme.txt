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

Add the Event List block to any page. Choose cards or a list, the number of events per page, the order, and filters by category, tag, prefecture, and dates. The block follows your theme's colors and fonts, and you can change them in the block settings. Only published events that have not ended are listed. Events with no dates and password-protected events are not listed. Events are ordered by the date shown on their card: the next date that has not ended, or, when you filter by dates, the first date within them. With a date filter, an event is listed only if one of its dates falls within it.

Classic themes can use the same list with a shortcode:

`[eventwatch_events layout="list" per_page="6" order="asc" category="slug" tag="slug" prefecture="13" from="2026-12-01" to="2026-12-31" empty_text="No events yet."]`

All attributes are optional. `layout` is `cards` or `list`, `order` is `asc` or `desc`, and `prefecture` is a number from 1 to 47 in the JIS order. `period` is `thisMonth` or `next30Days`. It is worked out from today's date each time the list is shown, and it replaces `from` and `to`.

To change how one event is drawn, copy `templates/event-card.php` or `templates/event-row.php` from the plugin to an `eventwatch` folder in your theme. The `ended` value in `$args` is true for an event that has ended.

Add the Past Events block to show the events that have ended, newest first. An event moves here the day after it ends. Choose cards or a list, the number of events per page, and how far back to go. The default is one year.

Classic themes can use the same list with a shortcode:

`[eventwatch_past_events layout="list" per_page="6" months="12" empty_text="No past events yet."]`

`months` is `3`, `6`, `12`, `24`, or `0` for all past events.

The EventWatch pattern category has ready-made sections: Events This Month, Coming Soon, Past Events, and Upcoming and Past Events. The headings use your theme's colors and fonts, and you can edit them. The Event List block also has the variations Events This Month and Coming Soon.

Add the Event Search block to let visitors search your upcoming events by keyword, dates, category, and prefecture. The results replace the old ones without reloading the page, and the search also works without JavaScript. The search is kept in the page URL, so visitors can share it and use the back button. Keywords match event titles, event content, and venue names.

Classic themes can use the same search with a shortcode:

`[eventwatch_search layout="list" per_page="6"]`

= Event pages =

Each published event has its own page at `/event/your-event/`. The page shows the title, featured image, content, dates, venue, organizer (your site name), map links, and buttons to add the event to a calendar and to share it. You can change the `event` part of the URL in Settings > EventWatch. Old URLs redirect to the new ones. Password-protected events show only the title and the password form until the visitor enters the password.

Block themes can rearrange the page in the Site Editor: edit the Single Event template. The Event Detail block has a variation for each part of the page, and the Event Actions block holds the buttons. A template named `single-eventwatch_event.html` in your theme takes priority.

Classic themes use `templates/single-event.php`. To change it, copy it to an `eventwatch` folder in your theme, or add a `single-eventwatch_event.php` template to your theme.

The page also outputs schema.org `Event` data, a description, and Open Graph tags. If Yoast SEO, Rank Math, All in One SEO, SEOPress, or The SEO Framework is active, the description and Open Graph tags are left to that plugin. Use the filters `eventwatch_output_meta_tags` and `eventwatch_output_json_ld` to change this, for example if your SEO plugin also outputs `Event` data.

= Maps =

By default, the event page only links to Google Maps and OpenStreetMap, and opening the page does not connect to any outside service. If you enter a Google Maps API key in Settings > EventWatch, the page embeds a Google map of the venue, and **your visitors' browsers connect to Google when they open the page**. Google's terms and pricing apply to the key, and you are responsible for reviewing them: https://cloud.google.com/maps-platform/terms . The privacy policy is at https://policies.google.com/privacy .

The links under "Share" and "Add to calendar" open Google Calendar, X, LINE, or Facebook only when a visitor chooses them.

= Connecting to EventWatch =

To list your events on EventWatch, you need an EventWatch account and an API key. Issue the key on EventWatch under Organization settings > Site connection, and enter it in Settings > EventWatch. Saved keys are hidden and are not shown again. Use "Check connection" to see which organization the key belongs to.

A key saved in the settings is also included in database backups and copies of your site. We recommend setting it in `wp-config.php` instead. The constant takes priority, and the field becomes read-only:

`define( 'EVENTWATCH_API_KEY', 'ewk_...' );`

On a multisite network the constant applies to every site. To use a different key for each site, save the key on each site's settings page.

If your site's URL changes, for example when you copy it to a staging site, nothing is sent to EventWatch until an administrator confirms in Settings > EventWatch whether the site is a copy or has moved. A copy becomes a separate site, and its saved key is removed. A key set in `wp-config.php` can't be removed this way, so remove it from the copy's `wp-config.php` first if the copy shouldn't send events. When you confirm a move, published events already sent to EventWatch are sent again, so EventWatch gets the new addresses.

= What is sent to EventWatch =

Once a key is set, events are sent when you publish them, including scheduled events at their publish time. EventWatch reviews them before listing them, and the review only affects the listing on EventWatch: your site shows the event as soon as you publish it. In the editor, the EventWatch panel shows the status of each event.

* Updating a published event sends the update. An event listed on EventWatch stays listed.
* Moving an event to draft, pending review, private, password-protected, or the trash withdraws it from EventWatch. Restoring it from the trash sends nothing until you publish it again.
* Deleting an event permanently deletes it on EventWatch.
* Turn off "Send this post to EventWatch" to keep an event on your site only. If the event was already sent, it is withdrawn from EventWatch.

Events you published before entering a key are not sent automatically. They are sent the next time you update them. To send them together, use "Send all to EventWatch" in Settings > EventWatch. Each event goes to review. This doesn't include events set not to send, events that have ended, or events missing required details. Events are sent a few dozen at a time with WP-Cron. EventWatch limits how many images can be uploaded in a day, so on a site with more than 100 events with images, the event details arrive first and some images follow over the next days.

The featured image is uploaded to EventWatch as a file, shortly after you save, and only when the file has changed. EventWatch accepts JPEG, PNG, and WebP images up to 10 MB. If the file is too large, a smaller size that WordPress generated is sent instead. If no size fits, or the upload fails, the rest of the event is still sent, any image EventWatch already has stays, and the panel shows the reason. Failed uploads caused by a connection problem or the daily limit are retried about once an hour. Removing the featured image removes it on EventWatch too. Files that aren't stored on this server can't be uploaded.

The content is sent as Markdown with headings, paragraphs, lists, quotes, links, emphasis, strikethrough, code, and tables. Embeds and images you add to the content are not sent. Images that came with an event imported from EventWatch stay in the content on EventWatch. An event isn't sent if it has no title, no dates, no EventWatch category, or no prefecture (unless it's held online), or if its title is over 256 characters or its content is over 10,000 characters after conversion. It also isn't sent if only one of the venue name and address is entered, or if the notes on dates, the venue, or the official page URL are over EventWatch's limits, which count emoji as two characters. The panel lists the reasons, and publishing on your site isn't affected.

If EventWatch can't be reached, sending is retried automatically after 1 minute, 5 minutes, 30 minutes, and 2 hours, and then every 6 hours. Only the latest version of an event is sent. Retries use WP-Cron, which only runs when someone visits your site. To retry on time, run WP-Cron from your server's cron: https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/ . If your key is rejected or your organization is suspended, sending stops until you enter a new key, "Check connection" succeeds, or you update an event.

By saving a key, you agree to the EventWatch Terms of Service ( https://eventwatch.app/en/terms ) and Posting Guidelines ( https://eventwatch.app/en/post/guidelines ). This also applies to a key set in `wp-config.php`.

Your site does not depend on EventWatch. Your pages and publishing keep working if EventWatch is unavailable, and visitors' browsers never connect to EventWatch for the event pages or blocks. The only link to EventWatch that visitors get is the "Open in the app" link for events listed there.

= Review status =

The EventWatch panel in the editor and the EventWatch column in the event list show where each event stands: not sent, waiting to send, under review, listed, changes requested, paused, withdrawn, couldn't send, or not sending. If EventWatch asks for changes, the reason appears in the panel. Fix the event and update it to send it again. The column appears only when a key is set.

The plugin reads the status of events it has sent from EventWatch when you open an event in the editor, and about once an hour for up to 30 published events, oldest check first. If you have many events, a full round takes longer. These requests carry your API key, the site ID, and the event's post ID, and send no event content. Nothing is read while the site's URL change is unconfirmed. The Save button on the event page uses the status stored on your site, so a page cache can delay changes to it. A site whose URL change is unconfirmed does not show the button.

= Importing from EventWatch =

Administrators can bring posts your organization created on EventWatch into your site from Events › Import from EventWatch. The menu appears only when a key is set. Choose the posts to import. Each one becomes an event with its details, content, and images, which are copied to your Media Library. From then on you edit it on your site, and it can no longer be edited on EventWatch.

Posts listed on EventWatch are imported as published; all others as drafts. No event content is sent back to EventWatch when you import, and saving a draft doesn't take a post out of review. A draft or a post that was sent back with requested changes is submitted for review when you publish it on your site.

Importing runs one post at a time while the page is open, and stops if you close the page. If an import doesn't finish, the draft stays on your site and the page offers to try again without creating a second event.

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

= Scheduled events =

WordPress publishes scheduled events with WP-Cron, which only runs when someone visits your site. On a site with few visitors, a scheduled event may be published later than the time you set. To publish on time, run WP-Cron from your server's cron.

= Removing the plugin =

Deleting the plugin removes its settings, including the API key and the site ID, the send status of each event, and the capabilities it added to user roles. It also tells EventWatch to detach this site's events: they stay listed on EventWatch and you can edit them there. If EventWatch can't be reached, the plugin is still deleted; in that case, revoke the API key on EventWatch. Your events stay in the database, along with your choice not to send an event, unless you turn on "Delete all events when the plugin is deleted" in Settings > EventWatch. If you reinstall the plugin, the site gets a new site ID, so EventWatch treats it as a new site: when you publish the remaining events, they can be sent as separate posts.

= Source code =

The source code, including the uncompressed JavaScript for the blocks and the editor, is at https://github.com/eventwatch/eventwatch-wordpress . The `build` folder in the plugin is generated from the `src` folder there with `npm ci` and `npm run build`. The README in the repository explains how to set up a development environment.

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
