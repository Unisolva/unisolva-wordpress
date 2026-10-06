# Quote Requests

Quote Requests lets the visitors of a WooCommerce shop collect products into a quote list and send one quote request instead of buying. It stores every request, emails your sales team, and can hide prices and replace the cart with the quote page.

- "Add to quote" buttons on product pages and product lists, and a header link with a live count.
- One quote page with the product list and a short form.
- Form fields you set up yourself: rename, reorder, hide, require, and add your own.
- Regions for any country: a list where WooCommerce knows the regions, a line of text where it does not.
- Every request is saved before any email is sent, so a mail problem never loses a request.
- Catalog mode: hide prices, remove "Add to cart", send the cart and checkout to the quote page.
- Works without JavaScript, works with page caching.
- Spam protection without third-party services.
- No requests to outside services.

## Requirements

- WordPress 6.5 or newer
- PHP 8.1 or newer
- WooCommerce 8.0 or newer (tested up to 11.1)

The plugin declares compatibility with WooCommerce high-performance order storage.

## Install

1. Download the release zip from the Releases page of this repository. The zip unpacks to a folder named `quote-requests`.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip and install it.
3. Activate Quote Requests. WooCommerce must be active.

## First setup

1. Create a page for the quote list, for example "Request a quote", and put the shortcode `[quote_requests]` in it.
2. Go to WooCommerce > Quote request settings.
3. Under Pages, choose that page as the Quote page. Until you do, the buttons and the header link are not shown.
4. Under Notifications, enter the email addresses that should receive requests, one per line. If you leave the list empty, requests go to the site admin email.
5. Optional: choose your privacy policy page, and set up the form fields as described below.
6. Open a product page. The "Add to quote" button is there by default.

You can read the stored requests under WooCommerce > Quote requests.

To place the buttons yourself, or to put the link in your theme's header, see [docs/hooks.md](docs/hooks.md#template-tags-and-shortcodes).

## Settings

All settings are on WooCommerce > Quote request settings. Shop managers can save them.

### Form fields

The form fields are a list. Each row has a label, a type, a help text, and switches for Required and Show. Use Move up and Move down to change the order, and Add field for a new one.

Six fields are built in:

| Field | Notes |
|---|---|
| Name | Always shown and always required. |
| Phone | Whether it is required follows the contact rule. |
| Email | Whether it is required follows the contact rule. |
| Company | Can be hidden or required. |
| Region | Can be hidden or required. See Regions below. |
| Message | Always shown. Required only when the visitor's quote list is empty, so a request always says something. |

You can rename and reorder the built-in fields, and hide those that can be hidden. You cannot delete them.

You can add up to 20 fields of your own. The types are:

| Type | What the visitor sees |
|---|---|
| One line of text | A text box. |
| Several lines of text | A larger text box. |
| Email address | A text box that must hold a valid email address. |
| Phone number | A text box that must hold 7 to 20 digits. |
| Number | A text box that must hold a number. Arabic-Indic digits are accepted and stored as the digits 0 to 9. |
| Choice from a list | A drop-down list. You give the choices, one per line, as `value \| Label`, or as one text used for both. |
| Tick box | A checkbox. |

Each field has a key, which names it in stored requests. It is made of lowercase letters, digits and underscores, up to 40 characters. Leave the key empty and it is made from the label. A key cannot be changed after the field is saved. A few keys are reserved because the form uses them itself, and the settings screen tells you if you pick one.

Limits: one-line text up to 200 characters, several lines up to 2000, a choice value up to 100, name up to 100, company up to 150, region text up to 100.

If you remove a field, requests that already hold a value for it keep that value, under the label it had when the request was sent.

### Contact rule

Choose which contact details a visitor must give:

- at least one of phone and email (the default)
- phone mandatory, email optional
- email mandatory, phone optional
- phone and email mandatory

A contact field that the rule makes mandatory is always shown, also when code changes the field list with a filter. A request with no way to reply is rejected.

### Consent

"Require a consent checkbox" is on by default. The visitor must tick a box before sending. You can change its text, and link your privacy policy page. When a visitor ticks the box, the request stores that, with the time, the text shown and the privacy page address. Turn the setting off and the box is not shown, and no request is recorded as consented, whatever it carries.

### Regions

- Single country: the region list shows one country, your store country unless you enter another. With "Offer Outside [country]" on, the list has one more choice for visitors from elsewhere.
- Visitor chooses the country: the form first asks for the country, then the region. You can limit the countries offered; with none selected, every country is offered. The country you enter is preselected.

Where WooCommerce has a list of regions for the country, the visitor picks from the list. Where it has none, the visitor types the region in a text box of up to 100 characters. The lists are loaded when the country changes, so the page does not carry every country's regions. Without JavaScript, an "Update regions" button reloads the form for the chosen country and keeps what was typed.

### Notifications

- Recipients: one address per line.
- Send customer confirmation: when the visitor gave a valid email address, they get a short email with the reference and the products. It repeats nothing the visitor typed except their name in the greeting.
- Subject prefix of the team email: the text in brackets at the start of the subject of the team email. Empty means the site name. The customer's copy does not use it: its subject always starts with the site name.

Team emails have the Reply-To header set to the visitor's address.

### Texts

The button label, the label after a product is added, the text for an empty list, the thank-you text (`%name%` is replaced by the visitor's name), and a fallback contact shown when a request cannot be sent, for example an email address or phone number.

After a request is sent, the visitor sees the thank-you text, the reference and the products, and then:

- Show links after a request is sent: on by default. The visitor gets a link to the shop page of WooCommerce, shown as a button, and a link to the home page. A store without a published shop page gets the home page link alone. Turn it off to show no links.
- Label of the shop page link: default "Continue browsing products".
- Label of the home page link: default "Back to home page". An emptied label goes back to its default.
- When the customer confirmation was handed to the mail system for this request, the visitor also reads "We sent a copy of this request to your email address." The line is left out when the visitor gave no email address, when the confirmation is turned off, or when the mail system refused the message.

To change, add or remove links from code, see the filter `quote_requests_thanks_links` in [docs/hooks.md](docs/hooks.md#quote_requests_thanks_links).

### Catalog mode and buttons

- Hide prices.
- Remove "Add to cart" (products can no longer be bought).
- Redirect the cart and checkout to the quote page. The order-received page is not redirected.
- Add the button to product pages, and to product lists.

### Privacy and retention

- Keep requests for (months): from 1 to 120, default 24. A daily job deletes older requests permanently.
- Store visitor details: on by default. See What the plugin stores.
- Trust proxy header for the IP: off by default. See Notes for site owners.
- Max requests per IP per hour: from 1 to 100, default 5.

## How it protects against spam

There is no CAPTCHA and no outside service. The plugin uses four checks:

1. A signed form token. The page carries a token signed with a key derived from your WordPress salts, and the script refreshes it when the page loads, so a cached page still works. A request is accepted only if the token is valid and between 3 seconds and 2 hours old. A bot that posts at once, or with a token older than 2 hours, is rejected.
2. A honeypot. The form has a hidden field. A request that fills it in is rejected.
3. A rate limit. At most 5 stored requests per visitor address per hour by default; you can change it. The count is kept as a keyed hash of the address, not the address itself, and old counts are deleted daily.
4. Server-side checks. Every field is checked again on the server, the products are read from your catalog (a product that does not exist or is not published is dropped), and a request holds at most 50 product lines with a quantity of at most 9999 each.

The quote page asks search engines not to index it.

## What the plugin stores

For each request, the plugin creates one record (a private post type, visible only to users who can manage WooCommerce) with:

- the values of the form fields, with the labels they had at the time;
- the products and quantities, with the product name, SKU and category as they were at the time;
- the consent: whether it was given, when, the text shown and the privacy page address;
- the reference, such as `Q-2026-0001`;
- the result of each email (handed over, failed or skipped).

When "Store visitor details" is on (the default), each record also holds the IP address, the browser's user agent string, the browser name and version, the operating system, the device type, the preferred language, the time zone offset, the page the request was sent from, the first page of the visit, and the external site the visitor came from (its address and path, without the query string). Turn the setting off and none of these are stored.

For how long:

- Records are deleted automatically after the retention period (default 24 months). The job runs daily through WordPress's scheduler, so it needs site traffic to run, and it deletes up to 200 records per run.
- The quote list lives in the visitor's browser (local storage `quote_requests_list`, or a cookie of the same name when they have no JavaScript) for 30 days, and is not stored on your server until the visitor sends the request.
- For a visitor without JavaScript, the page that follows a send, or an "Update regions" step, keeps the result or the typed values in a WordPress transient for 10 minutes.
- Rate limit counters are deleted daily.

The plugin adds a suggested section to your privacy policy text (Settings > Privacy), and supports WordPress's personal data export and erasure, matched by the email address in the request. Deactivating the plugin keeps all records. Deleting it removes the daily job, the counters the plugin keeps in the options table and its stored answer about old order statuses, and keeps the records, which are business data. The settings option and the data version option stay too, so a reinstall finds the settings as they were.

## Extending the plugin

There are three ways, from simple to flexible:

1. Settings. Most sites need nothing more: fields, rules, regions, texts and catalog mode are all set on the settings screen.
2. Hooks. Filter `quote_requests_fields` changes the form fields from code, filter `quote_requests_validate` adds your own checks, action `quote_requests_created` runs code after a request is stored, for example to pass it to another system, and filter `quote_requests_thanks_links` changes the links shown after a request is sent. The constant `QUOTE_REQUESTS_HOLD_UPGRADE` postpones the data upgrade after an update from 0.1.
3. Template tags, shortcodes and CSS custom properties, to place the buttons and the link and to match your colours.

All of them, with examples, are in [docs/hooks.md](docs/hooks.md).

## Notes for site owners

- Page caching. The quote page is sent with no-cache headers, and the form token is refreshed by the script, so most cache setups work. If your quote page is cached anyway by a cache plugin or a CDN, purge the cache after you change the settings, otherwise visitors keep seeing the old form.
- Proxy header. The rate limit works per visitor address. If your site sits behind a proxy or a CDN, WordPress sees the proxy's address for every visitor, so all visitors share one limit and, after a few requests, nobody can send a request. The address stored with each request would be the proxy's too. In that case turn on "Trust proxy header for the IP". The plugin then reads the visitor address from the `CF-Connecting-IP` header, or the first address of `X-Forwarded-For`, and falls back to the connection address if neither holds a valid address. Turn it on only if a proxy or CDN really sets that header. Without one, any visitor can send the header themselves and choose the address they appear to come from, which gets around the limit. Behind a proxy or CDN with "Trust proxy header" off, all visitors share one address: that weakens the rate limit, and it weakens the separation of the form state kept for visitors without JavaScript, which is kept per form and per address.

## Upgrading from 0.1

Install 0.2 over 0.1 like any plugin update. Your form keeps working as it did:

- The 0.1 settings are converted. The fields keep their labels and their shown and required switches. The extra details field of 0.1 becomes a field of your own with the key `details` and its old label. Phone stays mandatory unless email was shown and required, in which case the contact rule is "phone and email mandatory". The consent box stays on and the region mode is a single country.
- Stored requests keep their data. The old details value of each request moves into the new storage.
- New installs start with the neutral default fields: name, phone, email, company, region and message.
- If your shop has WooCommerce orders with the old quote order statuses (`wc-quote-requested`, `wc-quote-approved`, `wc-quote-rejected`), the plugin keeps registering those statuses so the orders stay in the order list. A site without such orders no longer gets them. See [docs/hooks.md](docs/hooks.md#quote_requests_legacy_order_statuses).
- If your code reads a quote with `Quote_Requests\Store::get()` or on the `quote_requests_created` action, note that the old details value is no longer a `details` key of the record. It is an entry of the `fields` list, with the key `details`. See [docs/hooks.md](docs/hooks.md#quote_requests_created).

The data upgrade runs by itself on the first page load after the update. To run it later, define the constant `QUOTE_REQUESTS_HOLD_UPGRADE`, as described in [docs/hooks.md](docs/hooks.md#quote_requests_hold_upgrade). Back up your database before you update.

## Running the tests

The unit tests need no WordPress. From the plugin folder:

```
phpunit -c phpunit.xml.dist
node --test tests/js/*.test.js
```

`phpunit` is PHPUnit 10 or 11 on PHP 8.1 or newer. The JavaScript tests use Node's built-in test runner and have no dependencies; the automated checks use Node 22. The coding standard (WordPress Coding Standards) is checked with `phpcs --standard=phpcs.xml.dist`.

## License

Quote Requests is free software, released under the GNU General Public License, version 2 or (at your option) any later version. See `license.txt`.

Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
