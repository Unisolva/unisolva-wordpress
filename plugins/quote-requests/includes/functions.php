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
