=== Quote Requests ===
Contributors: unisolva
Tags: request a quote, quote, catalog mode, woocommerce, b2b
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors collect products into a quote list and send one quote request. Hides prices, replaces the cart, stores every request.

== Description ==

Quote Requests turns a WooCommerce catalog into a quote request site.

* "Add to quote" buttons on product pages and product lists, plus a header link with a live count.
* One quote page with the list and a short form. You set the form fields yourself: rename, reorder, hide and require the built-in fields (name, phone, email, company, region, message) and add up to 20 of your own (text, several lines, email, phone, number, choice from a list, tick box).
* A contact rule (phone, email, either or both) and an optional consent box.
* Regions for any country: a list where WooCommerce knows the regions of the country, a line of text where it does not. One country, or the visitor chooses.
* Every request is saved before any email is sent, so a mail problem never loses a request.
* Team notification with Reply-To set to the customer, optional customer confirmation.
* Catalog mode: hide prices, remove add to cart, redirect cart and checkout to the quote page.
* Works without JavaScript, works with page caching, accessible (labels, inline errors, 44 px targets).
* Spam protection without third-party services: signed form token, honeypot, per-IP rate limit.
* Filters `quote_requests_fields` and `quote_requests_validate` and action `quote_requests_created` for your own code and CRM integrations.

== Installation ==

1. Install and activate WooCommerce.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the Quote Requests zip, install it and activate it.
3. Create a page with the shortcode `[quote_requests]`.
4. Go to WooCommerce > Quote request settings, choose that page as the Quote page and enter the email addresses that should receive requests.
5. Open a product page: the "Add to quote" button is there.

== Privacy ==

The plugin stores, per request: the values the visitor enters in the form fields (the fields are set in the plugin settings: by default name, phone, email, company, region and message, plus any fields you add), products and quantities, the consent time and text and, when "Store visitor details" is on (default), IP address, browser (including its user agent string), operating system, device type, preferred language, time zone offset, the page the request was sent from, the landing page and the external referrer. Requests are deleted automatically after the retention period (default 24 months). The quote list lives in the visitor's browser (local storage `quote_requests_list`, or a cookie of the same name without JavaScript) for 30 days. For a visitor without JavaScript, the result or the typed values of a send are kept in a WordPress transient for 10 minutes. The plugin makes no requests to external services. It adds a suggested privacy policy section and supports WordPress personal data export and erasure by email.

== Frequently Asked Questions ==

= Where do I set the recipients? =
WooCommerce > Quote request settings, under Notifications. The stored requests are under WooCommerce > Quote requests.

= How do I create the quote page? =
Create a page with the shortcode `[quote_requests]`, then choose it as the Quote page in the settings. The buttons and the header link appear after you do.

= How do I change the form fields? =
WooCommerce > Quote request settings, under Form fields. Rename, reorder, hide or require the built-in fields and add fields of your own. Developers can change the list with the filter `quote_requests_fields`.

= Does it work for countries other than one? =
Yes. Choose "Visitor chooses the country" under Regions. Where WooCommerce has a list of regions for the country the visitor picks from it, otherwise the visitor types the region.

= Do I need to turn on "Trust proxy header"? =
Only if the site is behind a proxy or CDN that sets the visitor address. Without it all visitors share the proxy's address, which weakens the rate limit and the separation of the form state kept for visitors without JavaScript. Turn it on only behind such a proxy, because otherwise a visitor can send the header themselves.

= My quote page is cached. What do I do after changing the settings? =
Purge the page cache.

= How do I put the quote list link in my header? =
Use the shortcode `[quote_requests_link]` or the template tag `quote_requests_link()`.

== Changelog ==

= 0.2.0 =
* Added: configurable form fields (rename, reorder, hide, require, add up to 20 of your own) with seven field types.
* Added: contact rule (phone, email, either or both) and an optional consent box.
* Added: regions for any country, with the visitor choosing the country and a line of text where WooCommerce has no region list. A cacheable regions route.
* Added: filters `quote_requests_fields` and `quote_requests_validate`, a hooks reference, and an upgrade routine from 0.1 with the constant `QUOTE_REQUESTS_HOLD_UPGRADE` to postpone it.
* Added: filters `quote_requests_legacy_order_statuses` and `quote_requests_show_crm_state`.
* Changed: the three old quote order statuses are registered only on a site that has orders with one of them (looked up once, by the data upgrade and on activation). Their labels can be translated.
* Changed: the list of requests no longer shows the CRM column, the CRM filter and the CRM row unless the filter `quote_requests_show_crm_state` turns them on. The Phone column is now Contact and shows the email address of a request without a phone number.
* Changed: with the consent box off, a request is never recorded as consented. Number fields accept Arabic-Indic digits and store the digits 0 to 9.
* Changed: the temporary data kept for visitors without JavaScript is stored in transients named `quote_requests_done_`, `quote_requests_err_` and `quote_requests_keep_`.
* Changed: `Store::get()` (used by code on the `quote_requests_created` action) no longer returns the 0.1 `details` key. It is an entry of `fields` with the key `details`.
* Changed: neutral default fields. Emails, the admin view and the personal data export are built from the field list. Settings are saved by users with the `manage_woocommerce` capability.
* Fixed: the region field no longer disappears for a country without a region list. A request can no longer be sent while a region list is loading. No error on a server without the mbstring extension.
* Security: everything kept for a visitor without JavaScript (result, errors, "Update regions") is one entry per form and requester address, so repeated posts cannot add rows.

= 0.1.2 =
* Fixed: atomic rate limit, bounded no-JavaScript state, no free text repeated in the customer email, recipient fallback to the admin email, quote list kept when a product lookup fails, phone numbers with invisible direction marks accepted.

= 0.1.1 =
* Fixed: Reply-To holds only the visitor's address. New version number so browsers fetch the updated stylesheet.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.2.0 =
Updating from 0.1 converts your settings and stored requests on the first page load after the update. Back up your database first. To postpone the conversion, define QUOTE_REQUESTS_HOLD_UPGRADE in wp-config.php.
