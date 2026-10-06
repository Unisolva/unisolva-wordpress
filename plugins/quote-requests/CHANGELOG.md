# Changelog

All notable changes to Quote Requests are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the version numbers follow [Semantic Versioning](https://semver.org/).

## 0.2.1 - 2026-10-06

### Added

- Next steps after a request is sent. Under the thank-you text the visitor now gets a link to the shop page of WooCommerce, shown as a button, and a link to the home page. A store without a published shop page (none set, or one that is a draft, private or in the trash) gets the home page link alone. Visitors without JavaScript get the same links.
- Three settings under "Texts": "Show links after a request is sent" (on by default), and the labels of the two links ("Continue browsing products" and "Back to home page"). A site that updates from 0.2.0 gets these defaults; nothing has to be saved again.
- The line "We sent a copy of this request to your email address." It is shown when the customer confirmation of that request was handed to the mail system, and left out when the visitor gave no email address, when the confirmation is turned off or when the mail system refused the message. "Sent" means handed over, not delivered.
- The filter `quote_requests_thanks_links`, which changes, reorders, adds or removes the links from code. It is documented with an example in `docs/hooks.md`.

### Changed

- The subject prefix belongs to the team email. The setting is now called "Subject prefix of the team email" and no longer changes the customer's copy: its subject always starts with the site name in brackets, and the link at the end of that email shows the site name. Until now a prefix such as "New Quote Request", chosen for the team inbox, also headed the email the customer received.
- The answer of the REST route that stores a request has two more keys, `links` and `copy_sent`. The existing keys are unchanged.

### Fixed

- A site title that contains a character such as `&` or an apostrophe appeared with HTML entities (for example `&amp;`) in email subjects and in the plain-text part of the customer's copy. It is now plain text there.
- A site without a site title is named by the host of its address (for example `example.com`) in email subjects and in the customer's copy, instead of by nothing.

## 0.2.0 - 2026-10-03

### Added

- Configurable form fields. Rename, reorder, hide and require the built-in fields, and add up to 20 fields of your own, from the new "Form fields" table on the settings screen.
- Seven field types: one line of text, several lines of text, email address, phone number, number, choice from a list and tick box.
- A contact rule: at least one of phone and email (the default), phone mandatory, email mandatory, or both mandatory.
- An optional consent box, with its own text. It is on by default.
- Regions for any country. The form can offer one country or let the visitor choose, optionally from a list of countries you pick. Where WooCommerce has a list of regions for the country, the visitor picks one. Where it has none, the visitor types the region.
- A cacheable REST route that returns the regions of one country: `GET /wp-json/quote-requests/v1/regions?country=XX`.
- An "Update regions" button that gives visitors without JavaScript the regions of the country they chose.
- The filters `quote_requests_fields` and `quote_requests_validate`, documented with examples in `docs/hooks.md`.
- The filter `quote_requests_legacy_order_statuses`, which decides whether three old quote order statuses are registered, and the filter `quote_requests_show_crm_state`, which shows the CRM state of requests in the admin.
- An upgrade routine from 0.1 that converts the settings and the stored requests, and the constant `QUOTE_REQUESTS_HOLD_UPGRADE` to postpone it.
- A README, a hooks reference, and a continuous integration workflow with PHPUnit, the coding standard and the JavaScript tests.

### Changed

- New installs start with neutral fields: name, phone, email, company, region and message. The extra details field of 0.1 is no longer a default. Sites that used it keep it as a field of their own.
- Emails, the admin view of a request and the personal data export are built from the list of fields. A value is shown under the label the field had when the request was sent.
- `Quote_Requests\Store::get()`, and so the `quote_requests_created` action, no longer returns the extra details value of 0.1 as a `details` key. It is an entry of the `fields` list with the key `details`.
- The settings are saved by anyone who has the `manage_woocommerce` capability. In 0.1 WordPress asked for the administrator capability on save, so a shop manager could open the screen but not save it.
- The order statuses `wc-quote-requested`, `wc-quote-approved` and `wc-quote-rejected` are registered only on a site that has orders with one of them. The plugin looks once, when the data upgrade runs and on activation, and stores the answer; until it has looked (for example while the upgrade is held) the statuses stay registered. A new install without such orders no longer gets them. Their labels can be translated.
- The list of requests no longer shows the CRM column and the CRM filter, and a request no longer shows its CRM state, unless the filter `quote_requests_show_crm_state` returns true. The plugin has no CRM connection of its own. The state is stored as before.
- The Phone column of the list of requests is now called Contact. A request without a phone number shows its email address there, as a mail link, instead of an empty phone link.
- With the consent box turned off, a request is never recorded as consented, also when the request carries a tick. The record stores no consent text that was not shown.
- Number fields accept Arabic-Indic and Eastern Arabic-Indic digits. The value is stored with the digits 0 to 9.
- A `quote_requests_fields` callback that returns something that is not an array is ignored, and the form stays as saved. The settings and the field list are built once per request, so the filter runs once per request.
- The temporary data kept for visitors without JavaScript is stored in transients named `quote_requests_done_`, `quote_requests_err_` and `quote_requests_keep_`. 0.1 used the prefixes `qr_done_` and `qr_err_`.
- The text of the emails is a neutral dark grey.

### Fixed

- The region field no longer disappears when the chosen country has no region list in WooCommerce. The visitor can type the region instead.
- A request cannot be sent while a region list is still loading, so it cannot leave without its region. A browser that restores the chosen country on reload gets the regions of that country.
- Submitting a region no longer fails on a server without the PHP mbstring extension.
- When a form already has 20 fields of its own, a new row can no longer push an existing field out.
- Field labels are escaped in the messages of the settings screen.

### Security

- What the plugin keeps for a visitor without JavaScript (the result of a request, the errors with the typed values, and what was typed before "Update regions") is one temporary entry of each kind for each form token and requester address. An entry lives 10 minutes, a repeated post overwrites it instead of adding rows, and the page shows an entry only to the request that carries its key. In 0.1 every failed post added a row.

## 0.1.2 - 2026-10-02

### Fixed

- The rate limit is counted in one atomic step, so parallel requests cannot pass it.
- The state kept for visitors without JavaScript is bounded.
- The customer confirmation does not repeat free text typed by the visitor.
- When no valid recipient address is saved, requests go to the site admin email.
- The visitor's quote list is kept when a product lookup fails.
- Phone numbers copied with invisible direction marks are accepted.

## 0.1.1 - 2026-10-02

### Fixed

- The Reply-To header of the team email holds only the visitor's address. A name that contained a comma could split it into two addresses, and mail services then rejected the message.

### Changed

- The version number changed so browsers fetch the updated stylesheet.

## 0.1.0 - 2026-10-02

### Added

- First release: "Add to quote" buttons, a header link with a live count, a quote page with a form, stored requests with team and customer emails, catalog mode, automatic deletion after a retention period, and WordPress personal data export and erasure.
