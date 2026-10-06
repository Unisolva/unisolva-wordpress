<?php
/**
 * Quote Requests
 *
 * Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
 *
 * This program is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @package Quote_Requests
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Add to quote" control for a product.
 *
 * @param int $product_id Product ID; 0 means the current post.
 */
function quote_requests_button( int $product_id = 0 ): string {
	return Quote_Requests\Buttons::button( $product_id ? $product_id : (int) get_the_ID() );
}

/** Header link to the quote page with the live product count. */
function quote_requests_link(): string {
	return Quote_Requests\Buttons::link();
}

/**
 * Stores a quote request that another form collected, the way the built-in form stores one.
 *
 * The request goes through the same steps as one of the built-in form: the rate limit, the validation against the
 * form fields of the settings, the product checks, the record, the emails and the quote_requests_created action.
 * It does not go through the spam checks of the built-in form (its signed token and its honeypot): the caller is
 * responsible for its own spam protection, and should call this only for a submission it has accepted.
 *
 * The function is ready once the plugin has registered its data, which it does on the init action at priority 10,
 * with WooCommerce active. Call it on wp_loaded or later, or on init with a priority above 10. A call that comes
 * too early, or without WooCommerce, stores nothing and answers with the code "not_ready".
 *
 * @param array $fields Values keyed by field key: name, phone, email, company, region, message and the keys of
 *                      your own fields. "region" takes a region code or name. "region_country" takes a two-letter
 *                      country code when the visitor may choose the country. "consent" is the consent tick, as the
 *                      built-in form posts it: 1, true, "1", "on", "yes" or "true" when ticked.
 *                      Other keys are ignored. Pass the values as the visitor typed them, without slashes.
 * @param array $items  The quote list: a list of array( 'id' => int, 'qty' => int ). quote_requests_parse_items()
 *                      makes it from the value of a hidden input named quote_requests_items.
 * @param array $args   Optional. "source": a short label stored on the record, such as "elementor" (lowercase
 *                      letters, digits, dash and underscore, at most 40 characters; default "other"; the built-in
 *                      form stores "form"). "ip": the visitor address for the rate limit and the stored visitor
 *                      details (default: the address of the current request, read as the built-in form reads it).
 *                      A call without a request (WP-CLI, cron) and without "ip" uses an empty address, so all such
 *                      calls share one rate limit counter: pass the visitor's address when you have it.
 *                      "page": the address of the page the form was on. It is stored as the page the request was
 *                      sent from, as its path, only when it is an http or https address on this site's host;
 *                      anything else is ignored. "consent_text": the text beside the consent tick of your form,
 *                      as plain text (tags are removed, at most 1000 characters). "privacy_url": the http or https
 *                      address of the privacy page that text links to; anything else is ignored. Give both when
 *                      your form words the consent itself, because the record must show what the visitor agreed
 *                      to: when the consent is ticked they are stored in place of the consent text and the privacy
 *                      page of the settings, which only the built-in form shows. Each one that is absent or empty
 *                      leaves the value of the settings in place.
 * @return array array( 'ok' => true, 'id' => int, 'ref' => string, 'dropped' => int ) for a stored request, where
 *               "dropped" is the number of lines of $items that were left out (0 when all were taken), as in the
 *               answer of the built-in form. Otherwise
 *               array( 'ok' => false, 'code' => string, 'message' => string, 'errors' => array ). "errors" holds
 *               a message per field key and is empty unless the code is "invalid". The codes are "invalid",
 *               "rate_limited", "store_failed" and "not_ready".
 */
function quote_requests_submit( array $fields, array $items = array(), array $args = array() ): array {
	return Quote_Requests\Submission::submit( $fields, $items, $args );
}

/**
 * Reads the quote list from the value of a hidden input named quote_requests_items, which the front script fills
 * with JSON such as [{"id":12,"qty":3}].
 *
 * The value may still have the slashes WordPress adds to posted values. Anything that is not such a list gives an
 * empty list, never an error.
 *
 * @param mixed $json The posted value.
 * @return array List of array( 'id' => int, 'qty' => int ), at most 50 lines, ready for quote_requests_submit().
 */
function quote_requests_parse_items( $json ): array {
	return Quote_Requests\Validator::items_from_json( $json );
}
