<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Proxy_Exception;

class WP_MySQL_Protocol_Packet_Test extends TestCase {
	public function test_build_packet_rejects_payload_exceeding_max_packet_length(): void {
		$this->iniSet( 'memory_limit', '512M' );
		$this->expectException( MySQL_Proxy_Exception::class );
		MySQL_Protocol::build_packet( 0, str_repeat( 'x', MySQL_Protocol::MAX_PACKET_LENGTH + 1 ) );
	}

	public function test_build_packet_allows_exact_max_packet_length_wire_frame(): void {
		$this->iniSet( 'memory_limit', '512M' );
		$packet = MySQL_Protocol::build_packet( 7, str_repeat( 'a', MySQL_Protocol::MAX_PACKET_LENGTH ) );
		$this->assertSame( 4 + MySQL_Protocol::MAX_PACKET_LENGTH, strlen( $packet ) );
		$this->assertSame( "\xff\xff\xff\x07", substr( $packet, 0, 4 ) );
	}

	public function test_build_packets_splits_boundary_payloads_and_wraps_sequence_id(): void {
		$this->iniSet( 'memory_limit', '512M' );

		// Just below MAX_PACKET_LENGTH: single packet.
		$sequence_id = 3;
		$payload     = str_repeat( 'a', MySQL_Protocol::MAX_PACKET_LENGTH - 1 );
		$frames      = $this->decode_wire_packets( MySQL_Protocol::build_packets( $sequence_id, $payload ) );
		$this->assertSame( 4, $sequence_id );
		$this->assertCount( 1, $frames );
		$this->assertSame( 3, $frames[0]['sequence_id'] );
		$this->assertSame( MySQL_Protocol::MAX_PACKET_LENGTH - 1, $frames[0]['length'] );

		// Exact MAX_PACKET_LENGTH: full packet followed by an empty terminating packet.
		$sequence_id = 255;
		$payload     = str_repeat( 'b', MySQL_Protocol::MAX_PACKET_LENGTH );
		$frames      = $this->decode_wire_packets( MySQL_Protocol::build_packets( $sequence_id, $payload ) );
		$this->assertSame( 1, $sequence_id );
		$this->assertCount( 2, $frames );
		$this->assertSame( 255, $frames[0]['sequence_id'] );
		$this->assertSame( MySQL_Protocol::MAX_PACKET_LENGTH, $frames[0]['length'] );
		$this->assertSame( 0, $frames[1]['sequence_id'] );
		$this->assertSame( 0, $frames[1]['length'] );

		// MAX_PACKET_LENGTH + 1: full packet followed by a 1-byte packet.
		$sequence_id = 10;
		$payload     = str_repeat( 'c', MySQL_Protocol::MAX_PACKET_LENGTH ) . 'Z';
		$frames      = $this->decode_wire_packets( MySQL_Protocol::build_packets( $sequence_id, $payload ) );
		$this->assertSame( 12, $sequence_id );
		$this->assertCount( 2, $frames );
		$this->assertSame( 10, $frames[0]['sequence_id'] );
		$this->assertSame( MySQL_Protocol::MAX_PACKET_LENGTH, $frames[0]['length'] );
		$this->assertSame( 11, $frames[1]['sequence_id'] );
		$this->assertSame( 1, $frames[1]['length'] );
		$this->assertSame( 'Z', $frames[1]['payload'] );

		// Exact 2 * MAX_PACKET_LENGTH: two full packets followed by an empty packet.
		$sequence_id = 254;
		$payload     = str_repeat( 'd', 2 * MySQL_Protocol::MAX_PACKET_LENGTH );
		$frames      = $this->decode_wire_packets( MySQL_Protocol::build_packets( $sequence_id, $payload ) );
		$this->assertSame( 1, $sequence_id );
		$this->assertCount( 3, $frames );
		$this->assertSame( 254, $frames[0]['sequence_id'] );
		$this->assertSame( MySQL_Protocol::MAX_PACKET_LENGTH, $frames[0]['length'] );
		$this->assertSame( 255, $frames[1]['sequence_id'] );
		$this->assertSame( MySQL_Protocol::MAX_PACKET_LENGTH, $frames[1]['length'] );
		$this->assertSame( 0, $frames[2]['sequence_id'] );
		$this->assertSame( 0, $frames[2]['length'] );
	}

	private function decode_wire_packets( string $bytes ): array {
		$frames = array();
		$offset = 0;
		$total  = strlen( $bytes );
		while ( $offset < $total ) {
			$this->assertGreaterThan( $offset + 3, $total );
			$length      = unpack( 'V', substr( $bytes, $offset, 3 ) . "\0" )[1];
			$sequence_id = ord( $bytes[ $offset + 3 ] );
			$offset     += 4;
			$frames[]    = array(
				'length'      => $length,
				'sequence_id' => $sequence_id,
				'payload'     => $length <= 16 ? substr( $bytes, $offset, $length ) : null,
			);
			$offset     += $length;
		}
		$this->assertSame( $total, $offset );
		return $frames;
	}
}
