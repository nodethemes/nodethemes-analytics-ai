=== NodeThemes Analytics AI - Privacy-First Analytics with AI Insights ===
Contributors: nodethemes
Tags: analytics, statistics, matomo, woocommerce analytics, privacy
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPLv2 or later

Matomo-style website analytics inside WordPress: GeoIP locations, goals, WooCommerce revenue, segments, Web Vitals and AI insights from your own OpenAI / Claude / Gemini key.

== Description ==
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
* Built for busy sites: daily pre-calculated reports, a background queue for hits and an optional lightweight tracking endpoint
* Privacy: cookieless by default, IP addresses never stored, Do Not Track / GPC, consent modes (WP Consent API), opt-out shortcode [ntaa_optout], GDPR delete and export, raw-data retention
* Access control per user role, CSV export of every table
* AI summary of your traffic with your own OpenAI (ChatGPT), Anthropic (Claude) or Google (Gemini) key

== Free and Pro ==
Pro (sold at nodethemes.com) is a separate add-on plugin (NodeThemes Analytics AI Pro). It adds in-depth AI reports and chat,
funnels, heatmaps and session recordings, A/B tests, Google Analytics 4 import and Search Console keywords, form/media/JS-error/content tracking,
cohorts, users flow, attribution, custom reports, alerts, scheduled email reports, Tag Manager, multiple websites and a reporting API.
The free plugin only contacts nodethemes.com to check for new versions of itself, and the GeoIP provider you choose to download the location database.

== Privacy ==
Cookieless by default. IP addresses are never stored — the location is looked up on arrival and only country/region/city are kept.
Only aggregated numbers are sent to the AI provider you choose — never personal data.
IP geolocation by DB-IP (https://db-ip.com), licensed under CC BY 4.0, when a DB-IP database is selected.

== Setup ==
1. Upload and activate. Tracking starts immediately.
2. Analytics AI > Settings > Locations (GeoIP): click "Download now" to install the free location database.
3. Analytics AI > Settings > AI: paste your API key, click "Save settings", then "Test connection".
4. Analytics AI > Dashboard > Goals > Manage goals: create your first goal.
5. Busy site? Settings > Performance: turn on High-traffic mode and add a server cron job.

Tip: keep keys out of the database by adding define('NTAA_OPENAI_KEY','...'); (or NTAA_ANTHROPIC_KEY / NTAA_GEMINI_KEY) to wp-config.php.

== Changelog ==
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
