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
namespace Quote_Requests;

defined( 'ABSPATH' ) || exit;

final class Products {

	private static function product( int $id ): ?\WC_Product {
		if ( $id <= 0 || 'product' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$p = wc_get_product( $id );
		return $p instanceof \WC_Product ? $p : null;
	}

	private static function category_name( int $id ): string {
		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		usort( $terms, static fn( $a, $b ) => $b->parent <=> $a->parent ); // Prefer a child category over its parent.
		return (string) $terms[0]->name;
	}

	public static function snapshot( int $id ): ?array {
		$p = self::product( $id );
		if ( ! $p ) {
			return null;
		}
		return array(
			'product_id' => $id,
			'sku'        => (string) $p->get_sku(),
			'name'       => wp_strip_all_tags( $p->get_name() ),
			'family'     => self::category_name( $id ),
		);
	}

	public static function card( int $id ): ?array {
		$p = self::product( $id );
		if ( ! $p ) {
			return null;
		}
		$img = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '';
		return array(
			'id'     => $id,
			'name'   => wp_strip_all_tags( $p->get_name() ),
			'url'    => (string) get_permalink( $id ),
			'image'  => (string) $img,
			'family' => self::category_name( $id ),
		);
	}
}
