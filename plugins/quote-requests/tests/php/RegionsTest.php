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
use PHPUnit\Framework\TestCase;
use Quote_Requests\Regions;
use Quote_Requests\Settings;

final class RegionsTest extends TestCase {

	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
		Regions::$provider          = static function ( string $country ): array {
			$lists = array(
				'EG' => array(
					'EGC'   => 'Cairo',
					'EGALX' => 'Alexandria',
				),
				'CA' => array( 'QC' => 'Qu&eacute;bec' ),
				'AE' => array(),
			);
			return $lists[ $country ] ?? array();
		};
		Regions::$country_names     = array(
			'EG' => 'Egypt',
			'CA' => 'Canada',
			'AE' => 'United Arab Emirates',
			'CI' => 'C&ocirc;te d&#039;Ivoire',
		);
		$this->settings( array() );
	}

	protected function tearDown(): void {
		Regions::$provider      = null;
		Regions::$country_names = null;
		qr_test_reset_options( array() );
	}

	/** Stores settings with the 0.2 shape (contact_rule present), single mode and EG unless overridden. */
	private function settings( array $over ): void {
		qr_test_set_option( Settings::OPTION, array_merge(
			array(
				'contact_rule'   => 'either',
				'region_mode'    => 'single',
				'region_country' => 'EG',
				'region_outside' => true,
			),
			$over
		) );
	}

	public function test_code_and_name_resolve_to_the_same_region(): void {
		foreach ( array( 'EGC', 'egc', 'Cairo', ' cairo ', 'CAIRO' ) as $v ) {
			$this->assertSame( array( 'country' => 'EG', 'code' => 'EGC', 'name' => 'Cairo' ), Regions::resolve( 'EG', $v ), "value '$v'" );
		}
	}

	public function test_spaces_inside_a_name_are_collapsed(): void {
		$this->settings( array( 'region_outside' => true ) );
		$found = Regions::resolve( 'EG', "Outside   \n Egypt" );
		$this->assertSame( 'OUTSIDE', $found['code'] );
	}

	public function test_accents_and_entities_match(): void {
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'CA' ) ) );
		foreach ( array( 'Quebec', 'Québec', 'Qu&eacute;bec', 'QUÉBEC', 'qc' ) as $v ) {
			$this->assertSame( array( 'country' => 'CA', 'code' => 'QC', 'name' => 'Québec' ), Regions::resolve( 'CA', $v ), "value '$v'" );
		}
	}

	public function test_unknown_value_in_a_listed_country_is_rejected(): void {
		$this->assertNull( Regions::resolve( 'EG', 'Atlantis' ) );
		$this->assertNull( Regions::resolve( 'EG', 'Cai' ) );
	}

	public function test_country_without_a_list_accepts_text(): void {
		$this->settings( array( 'region_mode' => 'choose' ) );
		$this->assertSame( array( 'country' => 'AE', 'code' => '', 'name' => 'Dubai Marina' ), Regions::resolve( 'AE', 'Dubai Marina' ) );
		$this->assertSame( 'Dubai Marina', Regions::resolve( 'AE', "  Dubai \n  Marina " )['name'], 'one line, spaces collapsed' );
		$long  = str_repeat( 'abcdefghi ', 15 );
		$found = Regions::resolve( 'AE', $long );
		$this->assertSame( 99, mb_strlen( $found['name'] ), 'the cut lands on a space, which is trimmed' );
		$this->assertSame( rtrim( $found['name'] ), $found['name'] );
		$this->assertSame( '', $found['code'] );
		$this->assertSame( 100, mb_strlen( Regions::resolve( 'AE', str_repeat( 'é', 150 ) )['name'] ), 'multibyte text is cut by characters' );
	}

	public function test_country_not_allowed_is_rejected(): void {
		$this->assertNull( Regions::resolve( 'CA', 'QC' ), 'single mode allows only the default country' );
		$this->assertNull( Regions::resolve( 'ZZ', 'anything' ) );
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'EG', 'AE' ) ) );
		$this->assertNull( Regions::resolve( 'CA', 'QC' ), 'choose mode allows only the chosen countries' );
		$this->assertNull( Regions::resolve( 'CA', '' ), 'an empty value does not make a country allowed' );
		$this->assertSame( 'EGC', Regions::resolve( 'eg', 'Cairo' )['code'], 'the country code is case insensitive' );
	}

	public function test_outside_only_in_single_mode_with_a_list(): void {
		$states = Regions::states( 'EG' );
		$this->assertSame( array( 'EGC', 'EGALX', 'OUTSIDE' ), array_keys( $states ) );
		$this->assertSame( 'Outside Egypt', $states['OUTSIDE'] );
		$this->assertSame( array( 'country' => 'EG', 'code' => 'OUTSIDE', 'name' => 'Outside Egypt' ), Regions::resolve( 'EG', 'OUTSIDE' ) );

		$this->settings( array( 'region_outside' => false ) );
		$this->assertArrayNotHasKey( 'OUTSIDE', Regions::states( 'EG' ) );
		$this->assertNull( Regions::resolve( 'EG', 'Outside Egypt' ) );

		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'EG' ) ) );
		$this->assertArrayNotHasKey( 'OUTSIDE', Regions::states( 'EG' ) );

		$this->settings( array( 'region_country' => 'AE' ) );
		$this->assertSame( array(), Regions::states( 'AE' ), 'a country without a list gets no Outside entry' );

		$this->settings( array( 'region_country' => 'EG' ) );
		$this->assertArrayNotHasKey( 'OUTSIDE', Regions::states( 'CA' ), 'Outside belongs to the single country only' );
	}

	public function test_empty_value_returns_the_country_with_empty_code_and_name(): void {
		$this->assertSame( array( 'country' => 'EG', 'code' => '', 'name' => '' ), Regions::resolve( 'EG', '' ) );
		$this->assertSame( array( 'country' => 'EG', 'code' => '', 'name' => '' ), Regions::resolve( 'EG', "  \n " ) );
		$this->settings( array( 'region_mode' => 'choose' ) );
		$this->assertSame( array( 'country' => 'AE', 'code' => '', 'name' => '' ), Regions::resolve( 'AE', '' ) );
	}

	public function test_empty_country_means_the_default_country(): void {
		$this->assertSame( array( 'country' => 'EG', 'code' => 'EGC', 'name' => 'Cairo' ), Regions::resolve( '', 'Cairo' ) );
		$this->assertSame( array( 'country' => 'EG', 'code' => '', 'name' => '' ), Regions::resolve( '', '' ) );
		$this->settings( array( 'region_mode' => 'choose', 'region_country' => 'CA' ) );
		$this->assertSame( array( 'country' => 'CA', 'code' => 'QC', 'name' => 'Québec' ), Regions::resolve( '', 'Quebec' ) );
	}

	public function test_no_default_country_and_no_woocommerce_rejects_everything(): void {
		$this->settings( array( 'region_country' => '' ) );
		Regions::$country_names = null;
		Regions::$provider      = null;
		$this->assertSame( '', Regions::default_country() );
		$this->assertSame( array(), Regions::countries() );
		$this->assertSame( array(), Regions::states( 'EG' ) );
		$this->assertNull( Regions::resolve( '', 'Cairo' ) );
		$this->assertNull( Regions::resolve( 'EG', '' ) );
	}

	public function test_mode_follows_the_setting(): void {
		$this->assertSame( 'single', Regions::mode() );
		$this->settings( array( 'region_mode' => 'choose' ) );
		$this->assertSame( 'choose', Regions::mode() );
		$this->settings( array( 'region_mode' => 'bogus' ) );
		$this->assertSame( 'single', Regions::mode() );
	}

	public function test_countries_in_single_mode_is_the_default_country_only(): void {
		$this->assertSame( array( 'EG' => 'Egypt' ), Regions::countries() );
		$this->settings( array( 'region_country' => 'CI' ) );
		$this->assertSame( array( 'CI' => "Côte d'Ivoire" ), Regions::countries(), 'names are HTML-decoded' );
		$this->settings( array( 'region_country' => 'XX' ) );
		$this->assertSame( array( 'XX' => 'XX' ), Regions::countries(), 'an unknown code is its own name' );
	}

	public function test_countries_in_choose_mode_are_the_chosen_list_or_all(): void {
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'CA', 'EG', 'ZZ' ) ) );
		$this->assertSame( array( 'EG' => 'Egypt', 'CA' => 'Canada' ), Regions::countries(), 'known countries only, in the store order' );
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array() ) );
		$this->assertSame( array( 'EG', 'CA', 'AE', 'CI' ), array_keys( Regions::countries() ) );
		$this->assertSame( "Côte d'Ivoire", Regions::countries()['CI'] );
	}

	public function test_default_country_stays_inside_the_chosen_list(): void {
		$this->settings( array( 'region_mode' => 'choose', 'region_country' => 'CA', 'region_countries' => array( 'EG', 'CA' ) ) );
		$this->assertSame( 'CA', Regions::default_country() );
		$this->settings( array( 'region_mode' => 'choose', 'region_country' => 'AE', 'region_countries' => array( 'EG', 'CA' ) ) );
		$this->assertSame( 'EG', Regions::default_country(), 'the first chosen country when the configured one is not in the list' );
		$this->settings( array( 'region_mode' => 'choose', 'region_country' => 'AE', 'region_countries' => array( 'ZZ', 'CA', 'EG' ) ) );
		$this->assertSame( 'EG', Regions::default_country(), 'the first country that is shown, not the first stored code (ZZ is unknown)' );
		$this->assertSame( array( 'country' => 'EG', 'code' => 'EGC', 'name' => 'Cairo' ), Regions::resolve( '', 'Cairo' ) );
		$this->settings( array( 'region_mode' => 'choose', 'region_country' => 'AE', 'region_countries' => array( 'ZZ' ) ) );
		$this->assertSame( array(), Regions::countries() );
		$this->assertNull( Regions::resolve( '', 'Cairo' ), 'nothing is allowed when no chosen code is known' );
		$this->settings( array( 'region_mode' => 'single', 'region_country' => 'AE', 'region_countries' => array( 'EG', 'CA' ) ) );
		$this->assertSame( 'AE', Regions::default_country() );
	}

	public function test_country_name_is_found_for_any_known_code_whatever_the_list(): void {
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'EG' ) ) );
		$this->assertSame( 'Egypt', Regions::country_name( 'EG' ) );
		$this->assertSame( 'Canada', Regions::country_name( 'ca' ), 'a country that is no longer in the chosen list still has its name' );
		$this->assertSame( "Côte d'Ivoire", Regions::country_name( 'CI' ), 'HTML-decoded' );
		$this->assertSame( 'ZZ', Regions::country_name( 'ZZ' ), 'an unknown code is its own name' );
		$this->assertSame( '', Regions::country_name( '' ), 'no code, no name' );
		Regions::$country_names = array();
		$this->assertSame( 'EG', Regions::country_name( 'EG' ), 'without a country list the code stands in' );
	}

	public function test_location_in_choose_mode_adds_the_country_name(): void {
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'EG', 'CA' ) ) );
		$quote = array( 'region_country' => 'EG', 'region_code' => 'EGC', 'region_name' => 'Cairo' );
		$this->assertSame( 'Cairo, Egypt', Regions::location( $quote ) );
		$this->assertSame( 'Cairo (EGC), Egypt', Regions::location( $quote, true ) );
		$this->settings( array( 'region_mode' => 'choose', 'region_countries' => array( 'CA' ) ) );
		$this->assertSame( 'Cairo, Egypt', Regions::location( $quote ), 'a country no longer in the chosen list still has its name' );
	}

	public function test_location_in_single_mode_shows_the_region_only_for_the_default_country(): void {
		$quote = array( 'region_country' => 'EG', 'region_code' => 'EGC', 'region_name' => 'Cairo' );
		$this->assertSame( 'Cairo', Regions::location( $quote ) );
		$this->assertSame( 'Cairo (EGC)', Regions::location( $quote, true ) );
		$this->assertSame( 'Cairo', Regions::location( array( 'region_name' => 'Cairo' ) ), 'a record without a country (0.1) is shown as before' );
		$this->assertSame( 'EGC', Regions::location( array( 'region_code' => 'EGC' ), true ), 'code only' );
	}

	public function test_location_in_single_mode_adds_the_country_when_it_is_not_the_default(): void {
		$quote = array( 'region_country' => 'CA', 'region_code' => 'QC', 'region_name' => 'Québec' );
		$this->assertSame( 'Québec, Canada', Regions::location( $quote ) );
		$this->assertSame( 'Québec (QC), Canada', Regions::location( $quote, true ) );
	}

	public function test_location_with_a_country_and_no_region_is_the_country_alone(): void {
		$this->settings( array( 'region_mode' => 'choose' ) );
		$this->assertSame( 'Egypt', Regions::location( array( 'region_country' => 'EG', 'region_code' => '', 'region_name' => '' ) ) );
		$this->settings( array() );
		$this->assertSame( 'Canada', Regions::location( array( 'region_country' => 'CA', 'region_code' => '', 'region_name' => '' ) ), 'single mode, foreign country' );
		$this->assertSame( 'ZZ', Regions::location( array( 'region_country' => 'ZZ' ) ), 'an unknown code stands for its own name' );
	}

	public function test_location_of_a_record_with_no_region_and_no_country_is_empty(): void {
		$this->assertSame( '', Regions::location( array( 'region_country' => '', 'region_code' => '', 'region_name' => '' ) ) );
		$this->assertSame( '', Regions::location( array() ) );
		$this->assertSame( '', Regions::location( array( 'region_country' => 'EG' ) ), 'single mode, default country, no region: as before' );
		$this->settings( array( 'region_mode' => 'choose' ) );
		$this->assertSame( '', Regions::location( array(), true ) );
	}

	/** WordPress stands in for mb_substr() and mb_strlen() on a host without mbstring, and for no other mb_ function. */
	public function test_no_other_mb_function_is_called_without_a_guard(): void {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) as $file ) {
			foreach ( file( $file ) as $n => $line ) {
				if ( preg_match( '/\bmb_(?!substr\b|strlen\b)(\w+)\s*\(/', $line, $m ) && false === strpos( $line, "function_exists( 'mb_" . $m[1] . "' )" ) ) {
					$found[] = basename( $file ) . ':' . ( $n + 1 ) . ' mb_' . $m[1];
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	public function test_state_names_are_html_decoded(): void {
		$this->assertSame( array( 'QC' => 'Québec' ), Regions::states( 'CA' ) );
	}
}
