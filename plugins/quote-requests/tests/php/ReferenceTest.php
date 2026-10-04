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
use Quote_Requests\Reference;

final class ReferenceTest extends TestCase {
	public function test_format(): void {
		$this->assertSame( 'Q-2026-0001', Reference::format( 2026, 1 ) );
		$this->assertSame( 'Q-2027-0123', Reference::format( 2027, 123 ) );
		$this->assertSame( 'Q-2026-12345', Reference::format( 2026, 12345 ) );
	}
}
