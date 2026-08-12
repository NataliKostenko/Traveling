=== SureCookie - Cookie Consent Banner, Cookie Scanner & Script Blocking ===
Contributors: brainstormforce
Tags: cookie consent, cookie banner, gdpr, ccpa, privacy
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.me/BrainstormForce

Cookie consent banner with real browser scanning, script blocking, local consent logs, GDPR/CCPA support, and no visitor limits.

== Description ==

SureCookie is a WordPress cookie consent plugin that helps you scan cookies, display a customizable cookie banner, block non-essential scripts before consent, and store consent logs inside your WordPress database.

It is built for site owners, E-commerce stores, agencies, bloggers, and WordPress professionals who want more than a basic cookie notice. SureCookie helps you understand what cookies and third-party services are running on your site, lets visitors manage their choices, and gives you a practical consent workflow without visitor-based pricing.

👉 <a href="https://app.zipwp.com/blueprint/surecookie-n9i" target="_blank" rel="noopener">Try the live demo of SureCookie.</a>

[youtube https://www.youtube.com/watch?v=IwU3Qa1VQYI]

= Not Just a Cookie Banner =

A cookie notice can tell visitors that your site uses cookies, but that alone does not manage consent properly. If analytics scripts, marketing pixels, video embeds, maps, or tag manager scripts run before visitors choose, the banner is only cosmetic.

SureCookie is built to connect the banner with the rest of the consent workflow. It helps you scan your WordPress site, review detected cookies, block non-essential scripts before consent, store consent logs locally, and keep your cookie policy easier to maintain.

= How SureCookie Works =

SureCookie follows a simple WordPress cookie consent workflow:

1. Scan selected pages with the real browser cookie scanner.
2. Review detected cookies, scripts, resources, and third-party domains.
3. Organize cookies into consent categories such as Essential, Functional, Analytics, and Marketing.
4. Enable script blocking so non-essential scripts wait for consent.
5. Show the cookie consent banner and preference modal to visitors.
6. Store consent logs locally in WordPress for review and export.
7. Generate or connect a cookie policy page that reflects your cookie setup.

This makes SureCookie more than a WordPress cookie banner. It works as a practical consent management plugin for cookie scanning, cookie blocking, consent records, and policy support.

= Why SureCookie? =

Many cookie banner plugins focus only on the visitor-facing notice. SureCookie focuses on what happens before and after that notice: cookie detection, script blocking, consent management, local consent logs, and policy support.

It covers the full cookie consent workflow:

* Scan selected pages to detect cookies, scripts, and third-party services
* Review cookies by category, with scan history, before publishing them to visitors
* Block scripts, iframes, embeds, and objects before consent
* Show a customizable cookie banner and preference modal
* Let visitors accept all, decline, or choose specific categories
* Support GDPR and CCPA cookie consent workflows
* Store consent logs locally in WordPress
* Generate a cookie policy page with dynamic cookie tables
* Re-request consent when your policy or setup changes
* Support Google Consent Mode and WP Consent API
* Run without visitor-based limits

= Free Features Included =

The WordPress.org version of SureCookie includes the core consent workflow:

* Cookie consent banner: Show a clean banner or notice for visitors.
* Preference modal: Let visitors review and manage cookie categories.
* Accept, Accept All, Decline, and Preferences buttons: Control the button text and order.
* Real browser cookie scanner: Scan selected pages using a browser-based scanning service.
* Cookie categories: Use Essential, Functional, Analytics, Marketing, and Uncategorized categories.
* Custom cookies: Add and manage cookies manually when needed.
* Script blocking: Block non-essential scripts before consent is given.
* Resource blocking: Manage scripts, iframes, embeds, and objects found during scans.
* Consent logs: Store visitor choices inside your WordPress database.
* Consent log filters: Search and filter logs by action, country, IP, and session ID.
* Consent PDF export: Export individual consent records for documentation.
* Consent retention settings: Control how long consent logs are kept.
* Monthly automatic scanning: Schedule recurring scans on the free plan.
* Scan history and change detection: See what changed between scans, including newly detected cookies and domains.
* Rule-based category suggestions: Get suggested categories for newly detected cookies.
* Cookie policy page: Generate a page with dynamic cookie tables.
* Re-consent controls: Add a Cookie Preferences link through a shortcode or menu item.
* Re-request consent: Ask all visitors to review choices again when needed.
* Google Consent Mode support: Send consent states to supported Google services.
* WP Consent API support: Share consent state with compatible WordPress plugins.
* Multilingual support: Work with WPML and Polylang for banner and admin text.
* RTL support: Display frontend cookie policy content correctly for RTL languages.
* MCP and WordPress Abilities support: Enable AI assistant access to SureCookie management actions when supported.
* No visitor-based limits: SureCookie does not charge by traffic or monthly visitors.

= Free vs Pro Clarity =

This WordPress.org listing focuses on the free SureCookie plugin. The free plugin includes the core banner, scanner, blocking, consent logs, cookie policy page, monthly automatic scanning, Google Consent Mode, WP Consent API, and multilingual support.

Some advanced workflows are reserved for SureCookie Pro, such as weekly automatic scans, email scan digests, auto-apply behavior, compliance guard workflows, regional targeting, and consent forwarding. This keeps the free feature list clear and avoids confusion for WordPress.org users.

[Learn more about SureCookie Pro](https://surecookie.com)

= Real Browser Cookie Scanner =

SureCookie uses a browser-based scanning service at [https://library.surecookie.com/](https://library.surecookie.com/) to inspect selected pages.

Instead of only reading static HTML, the scanner loads your pages in a real browser environment. This helps detect cookies and resources that appear after page load, through tag managers, or through third-party scripts.

The scanner can help identify:

* Cookies added by analytics tools
* Marketing and advertising cookies
* Functional and preference cookies
* WooCommerce and WordPress session cookies
* Third-party domains and resources
* Tag manager loaded scripts
* Dynamically injected scripts and cookies

SureCookie keeps scanning separate from visitor traffic, so your cookie banner and consent logs are not affected by how many people visit your site.

= Monthly Automatic Scanning =

SureCookie includes a free automatic scanning base.

When enabled, it can run scheduled monthly scans through WP-Cron. The scan uses the same scanner engine, respects the configured scan scope, and records the latest scan history.

The scan history can show:

* Newly detected cookies
* Removed cookies
* Recategorized cookies
* Newly detected third-party domains
* Whether the scan was manual or automatic
* The last scan date and cookie count

Weekly automatic scans, email digests, auto-apply, and compliance guard behavior are extension points for the Pro version. They are not described here as free features.

= Script Blocking Before Consent =

SureCookie can block non-essential scripts before the visitor gives consent.

When blocking is enabled, SureCookie processes the frontend HTML and converts matching resources so they do not execute until the relevant cookie category is allowed. It can handle scripts as well as embedded content such as iframes, embeds, and objects.

This matters because a banner alone does not stop tracking. SureCookie is designed to connect consent choices with technical enforcement.

The script blocker also includes safeguards so it skips admin pages, REST requests, AJAX requests, feeds, JSON responses, XML responses, and scanner bypass requests.

= Customizable Banner and Preferences =

SureCookie gives you control over the visitor-facing consent experience.

You can customize:

* Banner message and description
* Rich text banner content
* Accept, Accept All, Decline, and Preferences button labels
* Button order
* Banner position and width
* Banner logo
* Banner animation
* Background overlay
* Preference modal heading and description
* Cookie category labels and descriptions
* Custom CSS

Visitors can accept all cookies, decline non-essential cookies, or open the preferences modal and choose specific categories.

= Consent Logs Stored Locally =

SureCookie stores consent records inside your WordPress database.

This is different from many SaaS consent management platforms where consent records live on an external platform. With SureCookie, your consent logs stay on your WordPress site and can be reviewed from the admin area.

Consent logs can include:

* User session ID
* Consent action, such as accepted, declined, or partially accepted
* Cookie category preferences
* Masked IP address
* Timestamp
* Country

Admins can view, search, filter, delete, and export logs from WordPress. SureCookie also includes retention settings so you can control how long logs are kept.

= Cookie Policy Page and Cookie Policy Generator =

SureCookie includes a cookie policy generator that can create a Cookie Policy page for your website.

The generated page uses native WordPress blocks and includes a dynamic shortcode that displays cookie tables grouped by category and provider. The cookie policy content can include:

* Cookie categories
* Cookie names
* Purpose or description
* Duration
* Domain
* Last updated timestamp
* A table of contents when multiple categories are available

You can edit the generated policy page in the WordPress editor and keep the dynamic cookie table connected to your scanned and manually added cookies.

= Re-Consent and Re-Request Consent =

Visitors should be able to change their choices later.

SureCookie includes a re-consent shortcode:

`[surecookie_reconsent_button]`

You can use it to add a Cookie Preferences button on your site. SureCookie can also add a virtual Cookie Preferences item to a selected WordPress navigation menu.

Admins can also re-request consent from all visitors. This is useful after updating your cookie policy, adding new tracking tools, changing categories, or making a major consent workflow change.

= Google Consent Mode =

SureCookie includes Google Consent Mode support.

When enabled, SureCookie can send consent states for supported Google services and update consent when visitors change their preferences. It maps SureCookie categories to Google consent signals and can detect Google services from enqueued scripts or the page HTML.

SureCookie also avoids showing ineffective block toggles for Google resources that are managed by Google Consent Mode.

= WP Consent API =

SureCookie supports WP Consent API.

It reads the SureCookie consent cookie and syncs the visitor's consent state so compatible WordPress plugins can check consent using the standardized WordPress consent API flow.

Default category mapping includes:

* Essential to functional
* Functional to preferences
* Analytics to statistics
* Marketing to marketing

Developers can customize mappings through filters.

= Multilingual and RTL Support =

SureCookie includes multilingual compatibility for WPML and Polylang.

It can register and translate banner text, button labels, preference modal text, category labels, and related frontend strings. It also includes RTL support for cookie policy layouts.

= MCP and WordPress Abilities =

SureCookie includes MCP and WordPress Abilities integration for supported setups.

When enabled, AI assistants and compatible tools can use structured SureCookie abilities to manage settings, cookie categories, consent logs, cookie management, and site scanner actions. Scanner start actions still require care because they contact an external scanning service.

= No Visitor-Based Limits =

SureCookie does not charge based on monthly traffic, pageviews, or visitor count.

The cookie banner, consent choices, and consent logs are not priced by how many visitors see the banner.

= Who Should Use SureCookie? =

SureCookie is a good fit for:

* WordPress site owners who need a cookie consent banner
* E-commerce stores using analytics, ads, pixels, embeds, or marketing tools
* Agencies managing WordPress cookie consent for client sites
* Bloggers and publishers using third-party scripts or content embeds
* Course, membership, and community sites that use analytics or conversion tracking
* Businesses using tag managers, analytics platforms, marketing pixels, heatmaps, video embeds, maps, forms, or similar third-party services
* Developers who want a WordPress-native consent workflow with hooks and APIs
* Teams that want consent logs stored inside WordPress instead of a third-party consent platform

= Works Well For Common WordPress Setups =

SureCookie is designed for everyday WordPress sites that need a cookie consent banner and practical script control. It can help with:

* E-commerce stores that use analytics, checkout tracking, or marketing pixels
* Agency-managed sites that need repeatable consent settings
* Publisher sites that use ads, embeds, and analytics tools
* Lead generation sites that use forms, CRM scripts, and conversion tracking
* Sites that need a cookie policy page with dynamic cookie tables
* Sites that want local consent logs for internal records

= Important Legal Note =

SureCookie provides technical tools for cookie scanning, script blocking, consent collection, consent logging, and cookie policy management.

No WordPress plugin can guarantee legal compliance on its own. Privacy and cookie requirements depend on your website, visitors, region, policies, data processing practices, and legal obligations. For legal advice, consult a qualified legal professional.

== External Services ==

SureCookie uses external services for cookie scanning, site verification, and geolocation.

= Cookie Scanning =

SureCookie connects to [https://library.surecookie.com/](https://library.surecookie.com/) to provide real browser-based cookie scanning and smart categorization.

When you run a scan, SureCookie sends the selected page URLs to the scanning service. A browser-based scanner visits those pages and detects cookies, scripts, resources, and third-party domains.

= Scanner Registration and Authentication =

Before the first scan, SureCookie performs a one-time registration handshake with `library.surecookie.com/api/register`.

The request sends:

* Site URL
* WordPress admin email
* SureCookie version
* A temporary installation nonce (one-time, random)

The scanning service verifies the site through a temporary REST endpoint, then returns site-specific credentials and a verification token. These credentials are stored locally in a non-autoloaded WordPress option and are used for authenticated scan requests.

= Consent IP Logs and Region Detection =

For consent logging and country detection, visitor IP addresses may be processed through MaxMind-backed region detection through SureCookie's service. This helps SureCookie record the country in the consent log.

IP addresses are masked before being stored in your WordPress database.

MaxMind attribution: [https://www.maxmind.com](https://www.maxmind.com)

= Service URLs =

* SureCookie scanning and geolocation service: [https://library.surecookie.com/](https://library.surecookie.com/)
* SureCookie privacy policy: [https://surecookie.com/privacy-policy/](https://surecookie.com/privacy-policy/)
* MaxMind: [https://www.maxmind.com](https://www.maxmind.com)

== Data Stored Locally ==

SureCookie stores plugin settings and consent data in your WordPress database.

This can include:

* Banner and preference modal settings
* Cookie categories
* Custom cookies
* Scanned cookies
* Scanned resources
* Scan history
* Consent logs
* Cookie policy page ID
* Automatic scan settings
* Scanner credentials

Consent logs and scan results can be viewed, exported, or deleted from the WordPress admin.

== Privacy and Data Processing ==

SureCookie processes data through its API service at `library.surecookie.com` for cookie scanning, scanner authentication, and region-aware consent logging. Here is what happens and why.

= What We Send to SureCookie Services =

During the one-time scanner registration and site verification flow, SureCookie sends the site URL, the WordPress administrator email, the SureCookie version, and a temporary installation nonce. The scanning service uses these to verify your site and issue credentials, then returns a verification token that SureCookie exposes at a temporary REST endpoint for domain verification.

After registration, scan requests use site-specific credentials stored locally in WordPress and do not resend the admin email.

When a scan runs, SureCookie sends selected page URLs to the scanning service so a real browser can detect cookies, scripts, resources, and third-party domains.

= What Is Processed and Why =

* Cookie scanning: Selected page URLs are scanned in a real browser environment to detect cookies, scripts, resources, and third-party domains.
* Cookie categorization: Detected cookie details, such as names, domains, and durations, are used to help categorize cookies.
* Region detection: Visitor IP addresses may be processed through MaxMind-backed region detection to determine country-level location for consent logs. IP addresses are masked before being stored in your WordPress database.
* Consent records: Consent choices, timestamps, category preferences, and session details are logged locally in your WordPress database.

= Where Data Lives =

* Consent logs, scan results, settings, and scanner credentials are stored in your WordPress database.
* Data sent to `library.surecookie.com` for scanning and geolocation may be temporarily processed for those features.
* SureCookie does not sell this data or use it for advertising.

= Data Retention and Control =

* Consent logs and scan results can be viewed, exported, or deleted from your WordPress admin.
* Consent log retention periods are configurable in plugin settings.
* Scanner credentials are stored locally in a non-autoloaded WordPress option.

= Security =

* API communication uses HTTPS.
* Scan requests use authenticated site credentials after the registration flow.
* Site credentials are used to sign later scan requests.

Because visitor data and selected page URLs can be processed through SureCookie services, you should mention this in your site's privacy policy where appropriate.

Full details: [https://surecookie.com/privacy-policy/](https://surecookie.com/privacy-policy/)

For questions about data processing, visit [https://surecookie.com/support/](https://surecookie.com/support/).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/surecookie` directory, or install the plugin through the WordPress Plugins screen.
2. Activate SureCookie from the Plugins screen.
3. Go to the SureCookie dashboard in WordPress.
4. Configure your banner content, cookie categories, and consent settings.
5. Select pages and run a cookie scan.
6. Review detected cookies and resources.
7. Enable script blocking if you want non-essential scripts blocked before consent.
8. Generate or connect your Cookie Policy page.
9. Test the banner and preference modal on the frontend.

== Frequently Asked Questions ==

= Is SureCookie a consent management platform? =

SureCookie provides consent management features inside WordPress, including a cookie banner, preference modal, cookie scanner, script blocking, consent logs, and cookie policy support. It is not a SaaS platform that charges by visitor count.

= Can SureCookie be used as a cookie blocker? =

Yes. When script blocking is enabled, SureCookie can act as a cookie blocker for non-essential scripts and embedded resources until the visitor gives consent for the related category.

= Does SureCookie help with cookie compliance? =

SureCookie helps with the technical parts of cookie compliance, such as scanning cookies, showing a consent banner, blocking non-essential scripts, storing consent logs, and maintaining a cookie policy page. It does not replace legal advice or guarantee compliance by itself.

= Is SureCookie just a cookie banner plugin? =

No. SureCookie includes a cookie banner, but it also includes cookie scanning, script blocking, consent logs, cookie policy page support, re-consent controls, Google Consent Mode, WP Consent API, and monthly automatic scanning in the free plugin.

= What features are included in the free plugin? =

The free plugin includes the banner, preference modal, real browser cookie scanner, custom cookies, script blocking, consent logs, consent log export, cookie policy page generation, re-consent button, re-request consent, monthly automatic scanning, scan history, Google Consent Mode, WP Consent API, multilingual support, and no visitor-based limits.

= How does the cookie scanner work? =

SureCookie sends selected page URLs to `library.surecookie.com`, where a browser-based scanner loads the pages and detects cookies, third-party domains, scripts, and resources. This helps detect cookies that may not appear in static HTML.

= Does SureCookie support automatic scanning? =

Yes. The free plugin includes monthly automatic scanning through WP-Cron. Weekly scanning, email digests, auto-apply, and compliance guard behavior are Pro extensions.

= Can SureCookie show what changed between scans? =

Yes. SureCookie records the latest scan history and can show newly detected cookies, removed cookies, recategorized cookies, and new third-party domains.

= Can SureCookie block scripts before consent? =

Yes. SureCookie can block non-essential scripts and embedded resources before consent is given. It can process scripts, iframes, embeds, and objects when blocking is enabled.

= Does SureCookie support Google Consent Mode? =

Yes. SureCookie includes Google Consent Mode support and can update Google consent signals based on visitor choices.

= Does SureCookie support WP Consent API? =

Yes. SureCookie can sync consent state with WP Consent API so compatible plugins can read consent through the standardized API.

= Can visitors change their consent choices later? =

Yes. Visitors can reopen the preferences modal through the settings button, a shortcode, or a configured menu item.

= What shortcode opens Cookie Preferences? =

You can use this shortcode:

`[surecookie_reconsent_button]`

It renders a Cookie Preferences button that opens the preferences modal.

= Can I re-request consent from all visitors? =

Yes. SureCookie includes a Re-request Consent option. When used, existing consent is treated as stale and visitors are asked to make a new choice.

= Are consent logs stored locally? =

Yes. Consent logs are stored in your WordPress database. Logs can include the action, preferences, timestamp, masked IP address, country, and session ID.

= Can I export consent logs? =

Yes. SureCookie supports consent log PDF export for individual records.

= Does SureCookie generate a cookie policy page? =

Yes. SureCookie can generate a WordPress page with editable policy content and a dynamic cookie table shortcode.

= Does SureCookie work with WooCommerce? =

Yes. SureCookie is designed for WordPress sites, including E-commerce stores. It can recognize common WooCommerce session cookies as essential during classification.

= Does SureCookie work with multilingual websites? =

Yes. SureCookie includes WPML and Polylang compatibility for banner text, preference modal text, category labels, and other frontend strings.

= Does SureCookie support RTL languages? =

Yes. SureCookie includes RTL styling support, including for the cookie policy page.

= Does SureCookie include AI assistant support? =

SureCookie includes MCP and WordPress Abilities integration for supported setups. When enabled, compatible AI assistants can interact with structured SureCookie management actions.

= Will SureCookie slow down my website? =

SureCookie is designed to stay lightweight on the frontend. Script blocking runs only where needed and skips admin, AJAX, REST, feeds, JSON, and XML responses.

= Are there visitor limits? =

No. SureCookie does not charge by visitors, traffic, or pageviews.

= Does SureCookie guarantee GDPR or CCPA compliance? =

No. SureCookie provides tools for cookie consent workflows, but no plugin can guarantee legal compliance by itself. Your compliance depends on your site, region, policies, and data practices. Please consult a legal professional for legal advice.

= What data is sent to external services? =

For cookie scanning, selected page URLs and site verification details are sent to `library.surecookie.com`. For country detection in consent logs, visitor IP addresses may be processed through the SureCookie service using MaxMind-backed data.

== Screenshots ==

1. SureCookie dashboard overview.
2. Cookie scan and detected cookies.
3. Cookie banner customization.
4. Preference modal customization.
5. Resource blocking settings.
6. Consent logs.
7. Cookie policy page.

== Upgrade Notice ==
= 1.3.1 =
Cookies with the same name are no longer duplicated when scanned again. Scanned cookies now update existing entries instead of being added again.

= 1.3.0 =
Adds the Known Services library, browser-based scanning for sites where the scanner is blocked, the new Scripts and Embeds page, bulk cookie category changes, remembered manual categories, and duplicate cookie fixes.

= 1.2.0 =
Adds Re-request Consent, monthly automatic scanning foundation, scan history change detection, background overlay controls, consent log improvements, and better Google script handling.

= 1.1.0 =
Adds AI Assistants integration, HTML support in banner content fields, improved settings organization, multilingual improvements, and cookie policy timestamp support.

= 1.0.0 =
Initial public release of SureCookie.

== Changelog ==
= 1.3.1 - 03-August-2026 =
- Fix: Prevented duplicate cookies by ensuring scanned cookies with the same name are update existing entries instead of being added again.

= 1.3.0 - 03-August-2026 =
- New: Introducing "Known Services", a library of popular third-party services (Google Analytics, Meta Pixel, YouTube, Stripe, Hotjar and more) that you can add in one click. Adding a service declares its cookies in your cookie list and cookie policy, and blocks its scripts and embeds until consent, without waiting for a scan to find them. The free plan includes 5 services; SureCookie Pro unlocks the full automated library of 150+ services. ( https://surecookie.com/docs/using-known-services/ )
- New: Introducing Assisted Scanner mechanism i.e. "Scan from Your Browser", a fallback scan for sites where the hosting firewall blocks SureCookie Scanner agent. ( https://surecookie.com/docs/scanning-your-site-from-your-browser/ )
- New: "Resource Blocking" has been rebuilt as the "Scripts and Embeds" page, a single list of every script and embed found on your site plus any you add yourself.
- New: You can now choose the consent state Google Consent Mode starts from before a visitor answers the banner. A "Worldwide" rule sets the baseline for Functional, Analytics and Marketing / Ads storage, and the regional rules below it override that baseline for the countries you list, so a region such as the EU can stay denied while other regions start from your own setting. Everything stays denied until you change it, so existing sites are unaffected.
- New: Under Scripts and Embeds, you can now add your own scripts and embeds to block, even if they are not detected by a scan. This is useful for scripts that only run on certain pages or under certain conditions, such as tag managers, marketing pixels, or custom embeds.
- New: You can now change the category of several cookies at once. Select them on the All Cookies page and move them together, with a reminder of what a category change means for visitor consent.
- New: Deleting a cookie category now asks where its cookies should go, with a "Move Cookies To" picker instead of always sending them to Uncategorized.
- Improvement: The built-in service catalog has grown to 150+ services with reviewed categories, so services such as Stripe, Cloudflare Turnstile, hCaptcha and Google Sign-In are treated as Essential or Functional instead of falling into Marketing. The catalog also refreshes on its daily schedule as intended.
- Improvement: The admin has been reorganized. "Cookie Manager" is now "Tracking Manager" and holds Scanning, Known Services, Cookies (All Cookies and Cookie Policy), Scripts and Embeds, Geographic Rules and Consent Sharing in one place.
- Improvement: Reduced the plugin's footprint on every page load. The scan log, active scan state, connection details and notice state are no longer loaded on requests that do not need them, and the scan log is capped at 500 lines so sites running automatic scans no longer grow it without limit.
- Improvement: Hardened the security of the plugin.
- Compatibility: If you use SureCookie Pro, update it to 1.1.0 or later alongside this release. Geographic Rules and Consent Sharing now live under Tracking Manager, and older Pro versions still register those pages at their previous location.
- Fix: Consent log PDF exports breaks in right-to-left languages such as Arabic and Hebrew.
- Fix: A cookie category you assign by hand is now remembered. Previously the next scan re-sorted that cookie using the scanner's own classification and your change was lost, including when a cookie stopped being detected and came back later.
- Fix: Security cookies and scripts found by a scan, such as captcha, bot protection and CSRF tokens, were filed as Marketing or left Uncategorized. They are now treated as Essential, so a visitor who declines marketing no longer breaks logins, forms and captchas on your site. Run a scan to re-sort anything already detected.
- Fix: Google Consent Mode sent no consent signals at all on sites that load Google Tag Manager from their own domain or through server-side tagging. SureCookie looked for Google's own script URLs to decide whether to act, and those setups have none, so neither the consent defaults nor the visitor's later choice ever reached Google. With Google Consent Mode turned on, the consent defaults are now always set at the top of every page before your tags run, no matter how those tags are loaded, and the visitor's choice is sent to Google as soon as they answer the banner.
- Fix: The Provider and Duration columns showed "-" for every scanned cookie. Both now fill in, including on cookies already stored, so no rescan is needed.
- Fix: Blocked embeds added to the page later, lazy loaded or loaded over AJAX, showed an empty box with no "Accept & Load" button.
- Fix: The Google Consent Mode conflict warning for Site Kit never appeared in the WordPress admin.
- Fix: Consent submissions returned an error when consent logging was turned off, even though the visitor's choice was saved correctly.
- Fix: Screen readers announced every blocked embed on a page with the same "Accept & Load" label; each button now names the service it will load.
- Fix: Removed the "Replace data on next scan" toggle. It had no effect, as every scan already replaces the detected resource list.
- Fix: Deactivating or uninstalling the plugin now clears the scheduled tasks and cached service data added in this release.
- Tweak: Scan results now tell you what actually happened. Separate notices cover a scan blocked by your host, a scan that reached the site but found nothing, a scan taking longer than usual, and a scan that finished only part of its pages. A retry adds to what was already found instead of replacing it, and the completion notice now points at both places results land, the Cookies page and the Scripts and Embeds page.
- Tweak: The Consent Logs table header now wraps on narrow screens instead of overflowing.
- Tweak: Scans could sit at "Queued..." forever on hosts where WordPress cron does not run, even though the scan had already finished. Opening the Scanning screen now refreshes the status directly.
- Tweak: When a hosting firewall blocked our scanner, the Scanning screen simply reported 0 cookies with no explanation. It now says the host blocked the scan, links to the allowlisting guide, and lists anything it could still detect. An invalid, expired or self-signed SSL certificate is now reported as a certificate problem rather than a firewall problem.

= 1.2.4 - 21-July-2026 =
- Improvement: The banner button order setting now displays a notice, with a shortcut to your Geographic Targeting rules, explaining that visitors matched by a region-specific rule see that region's button order instead of the global one.
- Improvement: The admin "What's New" panel no longer loads its font from Google's servers, removing an external request while keeping its appearance unchanged.
- Compatibility: SureCookie now blocks Presto Player video and audio embeds (YouTube, Vimeo, Bunny.net, self-hosted, and audio) until the visitor consents, showing an "Accept & Load" placeholder and restoring the player in place once the matching cookie category is accepted.
- Fix: Licensed users no longer see a misleading "5 pages per scan" limit before their site connects with SureCookie; the cookie scanner now prompts them to run their first scan to unlock their plan's full scan limits.
- Fix: SureCookie Pro users can now activate their license during onboarding, so their premium plan and higher scan limits are detected correctly instead of the site being set up on the free tier.
- Fix: Re-consent entry points (the shortcode button and menu item) now open the cookie preferences even when the consent banner is turned off site-wide, instead of appearing as unresponsive controls.
- Fix: The SureCookie review request notice in the WordPress admin now displays its logo icon at the correct, compact size instead of appearing oversized.
- Developer note: Custom post types can now be included in the site scanner and Cookie Policy pickers (and the automatic "All Published Content" scan scope) via the `surecookie_searchable_post_types` filter, with picker results grouped by content type. ( https://surecookie.com/docs/including-custom-post-types-in-scanning-and-the-cookie-policy-picker/ )

= 1.2.3 - 14-July-2026 =
- Improvement: Added notification dot for unread consent logs in admin menu.
- Improvement: The consent banner stylesheet now loads without blocking page render (served asynchronously), taking it off the critical rendering path and improving FCP / LCP / Lighthouse scores.
- Improvement: The bundled Figtree fonts are now served as woff2 (about 60% smaller) and use font-display: swap, so banner text paints immediately in a fallback font and the fonts no longer sit on the critical path.
- Fix: Accessibility issue with focus outlines for buttons and links in the banner interface.
- Fix: Script blocking now works even if the remote list fails, using a built-in baseline of common third-party scripts.
- Compatibility: SureCookie now activates cleanly on SQLite-based WordPress installs by skipping MySQL-only steps, fixing prior index and column errors.

= 1.2.2 - 10-July-2026 =
- Fix: Enabling Resource Blocking could break the page layout on WordPress 7.0 sites using classic themes with block-based content - block and global styles were pushed into the page body instead of the header, inverting the CSS cascade and collapsing multi-column sections. Resource Blocking no longer interferes with WordPress 7.0's on-demand block-style loading.
- Fix: Blocked video embeds (Vimeo, YouTube, and other WordPress responsive embeds) no longer leave a large empty gap around the "content is blocked" placeholder. The placeholder now overlays the embed's reserved aspect-ratio space instead of adding its own height on top of it.

= 1.2.1 - 09-July-2026 =
- Improvement: Reduced the consent banner script (public.js) by over 96% — from ~1.4 MB to ~50 KB minified — by removing admin-only code that was bundled into the public page.
- Improvement: Frontend scripts now load with the defer strategy and no longer pull in WordPress's api-fetch library, shrinking the script dependency chain and improving LCP / Lighthouse scores.
- Improvement: Consent-log delivery failures now log a console warning instead of failing silently.
- Improvement: A misbehaving surecookie-pro's floating widget can no longer prevent the consent banner from rendering.
- Improvement: On local and development sites (localhost, .local, .localhost, and private/loopback IP ranges), cookie scanning is now blocked up front with a clear notice — instead of appearing to start and then failing — since the SureCookie agent app cannot reach a local site.
- Compatibility: The consent banner now renders reliably alongside JS-delay/optimization plugins (e.g. WP Rocket, Perfmatters) that run scripts after the page has loaded.
- Compatibility: The Cookie Policy page link, cookie category names and descriptions, and the Re-request Consent button label now translate via WPML and Polylang to match the active language.
- Fix: Consent-log entries are no longer silently lost on pages served from a long-lived page cache — the banner now automatically refreshes its security token and retries.
- Fix: Added .site, .test domain support to the SureCookie agent app for staging development environments for further testing and scanning.
- Fix: The bullet-number lists in the messages editor now renders correctly when the description text includes HTML.
- Fix: Deleting a core cookie category unable to process and stuck in the deleting state.
- Fix: SureCookie agent's auth details not getting deleted when the plugin's "Delete Data on Uninstall" option is enabled.
- Fix: "Powered by" label from the banner is not being translated with provided translation files.
- Developer note: `window.surecookieManager` is available from DOMContentLoaded (never at HTML parse time). Integrations should use the `surecookie_*` events, and third-party scripts registering `surecookie.*` filters should be enqueued with the defer strategy.

= 1.2.0 - 25-June-2026 =
- New: Introducing "Automatic Scanning" feature to keep your site compliant with regular interval scheduled scan.
- New: Introducing "Re-request Consent" feature to allow admins to trigger a new consent request for all visitors.
- New: Introducing "Background Overlay" option to the banner layout settings, which dims the page behind the banner until a choice is made.
- New: Added highlighting logs count to the SureCookie admin menu > Logs, to show the number of new consent logs since the last visit.
- Improvement: Added "Reset to Default" button for the Banner Content and Preference Modal Description fields, allowing admins to revert to default text easily.
- Improvement: Updated scanning capacity sync with the SureCookie agent app for a more consistent scanning experience.
- Fix: The content blocker scanner showed a block toggle for Google scripts managed by Google Consent Mode, even though the toggle had no effect. These resources now display an explanatory note instead, clarifying that Consent Mode manages them.
- Fix: Deactivation conflict with other plugins resolved on network setup level.
- Compatibility - Synced up Resource blocked scripts with Google consent mode scripts, so that the toggle is not shown for Google scripts managed by Consent Mode.

= 1.1.0 - 15-June-2026 =
- New: Introduced AI Assistants integration using MCP (Model Context Protocol), along with WordPress Abilities APIs for AI-powered SureCookie management.
- New: "Banner Message" and "Preference Modal Description" fields in the Banner Content settings now support HTML tags for enhanced formatting options.
- Improvement: Improved the Settings dashboard by organizing configurations into well-defined categories for better clarity and user experience.
- Improvement: Added "Last updated" timestamps support to the Cookie Policy page.
- Fix: Cookie policy page with RTL language had layout issues on the frontend. This has been resolved to ensure proper display in RTL languages.

= 1.0.0 - 08-June-2026 =
- Official public release of SureCookie.
- Improvement: SureCookie is now ready with translations for 20 languages, including English, Spanish, Indonesian, Portuguese (Brazil), French, German, Russian, Italian, Turkish, Dutch, Japanese, Chinese (Simplified), Polish, Arabic, Swedish, Vietnamese, Hebrew, Thai, Greek, and Czech.
- Fix: Admin cookie category labels and banner content placeholders now translate via WPML/Polylang to match the active locale.

= 0.0.1-beta.4 - 20-May-2026 =
- Improvement: Added a credential reset notice to existing users to re-connect site with SureCookie agent app after security improvements.
- Improvement: WordPress 7.0 compatibility.

= 0.0.1-beta.3 - 19-May-2026 =
- New: Introduced "Learn" section in the dashboard with educational resources about cookie compliance.
- Improvement: Hardened the security of the plugin.

= 0.0.1-beta.2 - 1-May-2026 =
- New: Cookie Policy custom post type for managing policy pages within the plugin.
- New: Type-module script blocking until user grants consent.
- New: Dynamic script detection and blocking when consent is previously given or denied.
- New: Block and manage `<embed>` and `<object>` tags via the resource blocking system.
- New: Scanner capacity API, client, and UI support for the cookie scanner.
- New: 1-hour page visitor token to maintain blocking state before consent is given.
- New: Editable preference modal text with translation support and live preview in the banner.
- Improvement: UTM-tagged upgrade links across the plugin via helper functions for better attribution.
- Improvement: Internal libraries updated for better maintainability.
- Improvement: Added a clear action for the selected Cookie Policy page.
- Improvement: Routed dashboard support ticket link based on free/pro plan.
- Improvement: Added scroll support for the preference modal on larger screen sizes.
- Improvement: Added accessibility (a11y) support for cookie consent text.
- Improvement: Moved Resource Blocking under Advanced settings for better organization.
- Improvement: Refetch scanned cookies with a success notice after a scan completes.
- Improvement: Enforced Accept All button visibility and ordering across banner layouts.
- Improvement: "Copy Logs" button now only shows while scanning is in progress.
- Improvement: Hardened the security of the plugin.
- Fix: Consent log action filter not working due to a reserved REST parameter.
- Fix: Button rendering delay on the consent banner.
- Fix: Prevent early textdomain load notice on WordPress 6.7.
- Fix: Script type preservation issue when blocking and unblocking scripts.
- Fix: WPML improvements for multilingual sites.
- Fix: Banner reappear issue and consent log filter issue with naming.
- Fix: Brand casing in the consent PDF legal text.
- Fix: URL stripped issue for custom CSS.
- Fix: Null template handling in the script blocking interceptor.

= 0.0.1-beta.1 =
- New: WP Consent API integration for standardized consent management across plugins.
- New: Multilingual compatibility for admin texts with WPML and Polylang for global reach.
- New: Introduced geo-based rules specification for Google Consent Mode.
- Improvement: Admin dashboard translations added for multiple languages: Spanish (es_ES), Portuguese (pt_BR), French (fr_FR), German (de_DE), Dutch (nl_NL), Italian (it_IT), Polish (pl_PL), Indonesian (id_ID), and Russian (ru_RU).
- Improvement: Admin dashboard UI enhancements for better user experience and accessibility.
- Improvement: Dashboard UI/UX refinements to improve better user experience.

= 0.0.0-alpha.3 =
- Fix: Removed unnecessary files from the plugin package.

= 0.0.0-alpha.2 =
- New: Geographic Targeting introduced.
- New: Auth system to connect plugin to surecookie agent app for scanning and geolocation.
- Improvements in alpha.1 release.

= 0.0.0-alpha.1 =
Alpha public release of SureCookie.


== Useful Links ==

* [SureCookie Website](https://surecookie.com/)
* [Documentation](https://surecookie.com/docs/)
* [Support Forum](https://wordpress.org/support/plugin/surecookie/)
* [Privacy Policy](https://surecookie.com/privacy-policy/)
* [Live Demo](https://app.zipwp.com/blueprint/surecookie-n9i)

== About Brainstorm Force ==

SureCookie is built by Brainstorm Force, the team behind Astra and other widely used WordPress products.

== Support ==

* [Support Forum](https://wordpress.org/support/plugin/surecookie/)
* [Documentation](https://surecookie.com/docs/)
