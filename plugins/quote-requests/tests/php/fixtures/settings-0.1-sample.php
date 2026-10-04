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

// A sample of the settings Quote Requests 0.1 stores, with neutral values.
return array(
	'recipients'            => array(
		'sales@example.com',
		'info@example.com',
	),
	'customer_confirmation' => true,
	'subject_prefix'        => 'New Quote Request',
	'quote_page'            => 11,
	'privacy_page'          => 12,
	'region_label'          => 'State',
	'region_country'        => 'US',
	'region_outside'        => true,
	'fields'                => array(
		'email'   => array(
			'show'     => true,
			'required' => false,
		),
		'company' => array(
			'show'     => true,
			'required' => false,
		),
		'region'  => array(
			'show'     => true,
			'required' => true,
		),
		'details' => array(
			'show'     => true,
			'required' => false,
		),
	),
	'label_company'         => 'Company or organisation',
	'label_details'         => 'Project details',
	'consent_text'          => '',
	'button_label'          => 'Add to quote',
	'button_added_label'    => 'In your quote (%d)',
	'empty_text'            => 'No products yet. Browse products, or describe what you need below.',
	'thanks_text'           => 'Thank you, %name%. We will contact you as soon as possible.',
	'fallback_contact'      => 'Email sales@example.com.',
	'hide_prices'           => true,
	'remove_add_to_cart'    => true,
	'redirect_cart'         => true,
	'auto_button_single'    => false,
	'auto_button_loop'      => false,
	'retention_months'      => 24,
	'store_client'          => true,
	'trust_proxy'           => false,
	'rate_limit'            => 5,
);
