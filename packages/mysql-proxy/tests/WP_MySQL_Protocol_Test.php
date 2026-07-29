<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\MySQL_Protocol;

class WP_MySQL_Protocol_Test extends TestCase {
	public function test_server_status_flag_values(): void {
		$this->assertSame( 0x0200, MySQL_Protocol::SERVER_STATUS_NO_BACKSLASH_ESCAPES );
		$this->assertSame( 0x0400, MySQL_Protocol::SERVER_STATUS_METADATA_CHANGED );
		$this->assertSame( 0x0800, MySQL_Protocol::SERVER_QUERY_WAS_SLOW );
		$this->assertSame( 0x1000, MySQL_Protocol::SERVER_PS_OUT_PARAMS );
		$this->assertSame( 0x2000, MySQL_Protocol::SERVER_STATUS_IN_TRANS_READONLY );
		$this->assertSame( 0x4000, MySQL_Protocol::SERVER_SESSION_STATE_CHANGED );
	}
}
