=== NodeThemes Analytics AI ===
Contributors: nodethemes
Tags: analytics, statistics, woocommerce analytics, privacy, gdpr
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first website analytics inside WordPress: visitors, sources, goals, WooCommerce revenue and AI summaries with your own API key.

== Description ==

NodeThemes Analytics AI is a privacy-first analytics plugin that runs entirely on your own server. Your visitor data stays in your WordPress database: it is cookieless by default, never stores IP addresses and does not send visits to any outside analytics service.

All reports below are included in this plugin, with no usage limits:

* Visitors, visits, pageviews, bounce rate, visit duration, conversions and revenue, compared with the previous period
* Daily / hourly charts with annotations, real-time visitors, busiest-times heatmap, WordPress dashboard widget
* Visits log with the full timeline of every visit, and visitor profiles (cookie mode)
* Locations from a GeoIP database on your own server (DB-IP Lite or MaxMind GeoLite2): countries, regions, cities and a world map
* Devices, browsers, operating systems, screens, languages and five custom dimensions
* Engagement: new vs returning, visit duration, pages per visit, visit number, days since last visit, local time
* Pages with transitions (where visitors came from and went next), entry and exit pages, site search incl. searches without results
* Page speed from real visitors: Core Web Vitals (LCP, INP, CLS), FCP, TTFB and load time per page
* Acquisition: channels, AI assistants (ChatGPT, Perplexity, Gemini, Claude, Copilot…), search engines, social networks, websites, UTM campaigns with keyword and content, Google/Microsoft/TikTok ad clicks, campaign URL builder
* Crawlers & AI agents log (GPTBot, ClaudeBot, PerplexityBot, Googlebot…) — never counted as visitors
* Goals: page visits, events, visit duration, pages per visit, WooCommerce purchases and manual goals; conversion rates by channel, device, country, campaign and entry page; apply a goal to past data
* WooCommerce: revenue, orders, average order value, conversion rate, shopping path (product view → cart → checkout → purchase), abandoned carts, products, categories and sales by channel, campaign, country and device; refunds and cancellations are subtracted
* My dashboard with drag & drop widgets, row evolution for every table row, segment comparison
* Segments: filter every report by channel, device, country, city, campaign, visited page, event, goal, order and more; save and share segments
* Built for busy sites: daily pre-calculated reports and a background queue for hits
* Privacy: cookieless by default, IP addresses never stored, Do Not Track / GPC, consent modes (WP Consent API), opt-out shortcode [ntaa_optout], GDPR delete and export, raw-data retention
* Access control per user role, CSV export of every table
* AI summary of your traffic with your own OpenAI (ChatGPT), Anthropic (Claude) or Google (Gemini) key

= Optional Pro add-on =

NodeThemes Analytics AI Pro is a separate add-on plugin sold at [nodethemes.com](https://nodethemes.com/product/nodethemes-analytics-ai-pro/). It adds extra features on top of this plugin: in-depth AI reports and chat, funnels, heatmaps and session recordings, A/B tests, Google Analytics 4 import and Search Console keywords, form, media, JavaScript-error and content tracking, cohorts, users flow, attribution, custom reports, alerts, scheduled e-mail reports, Tag Manager, multiple websites and a reporting API. Nothing in this plugin stops working without it, and your settings and data stay when you add it.

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload the zip, and activate it. Tracking starts immediately.
2. Optional: Analytics AI > Settings > Locations (GeoIP): choose a database and click "Download now" to show countries and cities. Nothing is downloaded until you click this button.
3. Optional: Analytics AI > Settings > AI: paste your own OpenAI, Anthropic or Google Gemini API key, click "Save settings", then "Test connection".
4. Analytics AI > Dashboard > Goals > Manage goals: create your first goal.
5. Busy site? Settings > Performance: turn on High-traffic mode and add a real server cron job.

Tip: keep API keys out of the database by adding define( 'NTAA_OPENAI_KEY', '...' ); (or NTAA_ANTHROPIC_KEY / NTAA_GEMINI_KEY) to wp-config.php.

== Privacy ==

* Cookieless by default; an optional cookie mode can be switched on in the settings.
* IP addresses are never stored. When a GeoIP database is installed, the location is looked up on your server on arrival and only country, region and city are kept.
* Honours Do Not Track and Global Privacy Control, supports the WP Consent API, includes an opt-out shortcode [ntaa_optout] and the WordPress personal-data export and erase tools.
* Visit data is never sent to NodeThemes or to any analytics service.

== External services ==

This plugin works without any external service. It only connects to the services below when you turn them on yourself.

= AI providers (only when you add your own API key and click an AI button) =

When you request an AI summary or test the connection, the plugin sends your site name, site address and language plus aggregated report tables for the chosen period (totals, top pages, site-search terms, referrers, channels, campaigns, devices and countries) to the provider you selected. No individual visits, IP addresses or visitor profiles are sent.

* OpenAI (api.openai.com): [Terms and policies](https://www.openai.com/policies), [Privacy policy](https://openai.com/policies/privacy-policy)
* Anthropic (api.anthropic.com): [Commercial terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* Google Gemini (generativelanguage.googleapis.com): [Gemini API terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)

= GeoIP location database (only after you click "Download now") =

The plugin downloads a free IP-to-location database file to your server and then updates it about once a month. Only the download request is sent (with your MaxMind account ID and licence key if you choose MaxMind); no visitor data is ever sent.

* DB-IP Lite (download.db-ip.com), licensed under CC BY 4.0: [Database and licence](https://db-ip.com/db/lite.php), [Terms of service](https://db-ip.com/tos.php)
* MaxMind GeoLite2 (download.maxmind.com, needs a free MaxMind account): [GeoLite2 licence](https://www.maxmind.com/en/geolite2/eula), [Data privacy](https://support.maxmind.com/knowledge-base/data-privacy)

You can also upload your own .mmdb file or switch locations off.

== Third-party libraries ==

* Chart.js 4.5.1 (MIT License), assets/js/chart.umd.js – source: https://github.com/chartjs/Chart.js
* World map shapes from Natural Earth (public domain) via world-atlas (ISC License), assets/js/world-map.js – source: https://github.com/topojson/world-atlas

== Frequently Asked Questions ==

= Does it slow down my site? =
The tracker is a small script that sends one request per page view. Reports are pre-calculated once a day, and High-traffic mode stores hits in a background queue.

= Do I need an AI key? =
No. All reports work without AI. The AI summary is optional and uses your own key, so you pay the provider directly.

= Is it GDPR friendly? =
It is cookieless by default, never stores IP addresses and keeps the data on your own server. You are still responsible for your own privacy policy.

== Changelog ==

= 2.1.5 =
* Plugin header: removed the Plugin URI (it was the same as the Author URI).

= 2.1.4 =
* Code: an error message of the GeoIP database reader is now escaped (Plugin Check).

= 2.1.3 =
* Plugin name shortened to "NodeThemes Analytics AI".

= 2.1.2 =
* WordPress.org edition: updates now come from WordPress.org.
* Privacy: the GeoIP database is only downloaded after you click "Download now" (no automatic download on activation).
* Removed: the separate lightweight tracking file. High-traffic mode now always uses the WordPress REST API endpoint.
* Readme: external services and third-party libraries are documented.

= 2.1.1 =
* New: support for Pro roll-ups (several websites reported as one) and Pro export formats.
* Improved: the export button of every table; Pro adds TSV, XML and JSON next to CSV.
* New: Page overlay, Bing keywords and Activity log are listed in the menu (Pro).
= 2.1.0 =
* New: My dashboard — choose from 20+ widgets, drag them into your own order and switch each between half and full width. Saved per user.
* New: Row evolution — the chart icon next to any row (page, country, channel, campaign, product, event…) shows its daily trend with a choice of metrics.
* New: Compare segments — up to four segments side by side: key metrics, a chart and a breakdown by channel, device, country, campaign or page.
* New: Page titles report (Behaviour › Page titles). Older days are filled in automatically in the background after the update.
* Improved: “Show all rows” keeps the bars, row evolution and click actions of the table.
= 2.0.2 =
* Faster: the Transitions view of very busy pages now uses the latest 20,000 views (scaled to all views) — about 6× faster on pages with 100,000+ views.
* Fixed: in some report tables the row name was hidden behind the bar when the value was large.
= 2.0.1 =
* New: pre-processed segments — saved segments can be archived every night, so segmented reports load as fast as normal ones (e.g. 0.03 s instead of over a minute for 60 days on a busy site). Past days are processed in the background.
* Live (unsaved) segments now show a hint, and a notice while a pre-processed segment is still being prepared.
* Fixed: the lightweight tracking endpoint could not be switched on together with High-traffic mode in one save.
* Fixed: the WordPress admin bar no longer appears inside the heatmap preview; copy buttons work in all browsers.
= 2.0.0 =
* New dashboard with left navigation: Dashboard, Visitors, Behaviour, Acquisition, Goals, Ecommerce.
* New: GeoIP locations (countries, regions, cities, world map) with DB-IP Lite or MaxMind GeoLite2, monthly auto-update.
* New: goals with conversion reports and "apply to past data".
* New: WooCommerce ecommerce analytics with shopping path, abandoned carts, products and sales by source.
* New: segments for every report, saved and shared segments, website switcher.
* New: visits log, visit timeline, visitor profiles, transitions, annotations.
* New: Core Web Vitals, site search without results, custom dimensions, engagement reports, AI-assistant channel, crawler log.
* New: high-traffic mode (daily archiving, background queue, lightweight endpoint).
* New: consent modes, opt-out shortcode, GDPR delete/export, access per role.
* Existing data and settings are kept when updating from 1.x.
= 1.0.0 =
* First release.
