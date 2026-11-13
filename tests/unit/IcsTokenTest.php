<?php
/**
 * Calendar files and manage-link tokens.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Tests;

use AppointmentBooking\Core\Ics;
use AppointmentBooking\Core\Token;
use PHPUnit\Framework\TestCase;

/**
 * ICS and token tests.
 */
final class IcsTokenTest extends TestCase {

	public function test_event_uses_utc_crlf_and_escapes_text(): void {
		$ics = Ics::event( 'abk-7@example.com', 1780390800, 1780394400, 'Colour; cut, finish', "Line one\nLine two", 'Main St, 1', false, 1780000000 );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20260602T090000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20260602T100000Z\r\n", $ics );
		$this->assertStringContainsString( 'SUMMARY:Colour\; cut\, finish', $ics );
		$this->assertStringContainsString( 'DESCRIPTION:Line one\nLine two', $ics );
		$this->assertStringContainsString( 'STATUS:CONFIRMED', $ics );
		$this->assertSame( 0, preg_match( "/[^\r]\n/", $ics ), 'every line ends in CRLF' );
		$cancel = Ics::event( 'abk-7@example.com', 1780390800, 1780394400, 'X', '', '', true, 1780000000 );
		$this->assertStringContainsString( "METHOD:CANCEL\r\n", $cancel );
		$this->assertStringContainsString( "SEQUENCE:1\r\n", $cancel );
		$this->assertStringContainsString( 'UID:abk-7@example.com', $cancel );
	}

	public function test_long_lines_fold_without_splitting_characters(): void {
		$line   = 'DESCRIPTION:' . str_repeat( 'Café ', 40 );
		$folded = Ics::fold( $line );
		foreach ( explode( "\r\n", $folded ) as $i => $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertTrue( mb_check_encoding( $part, 'UTF-8' ) );
			if ( $i > 0 ) {
				$this->assertSame( ' ', $part[0] );
			}
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	public function test_tokens_verify_only_for_the_right_booking_and_email(): void {
		$t     = new Token( 'secret' );
		$token = $t->issue( 42, 'Jo@Example.com' );
		$email = static fn ( int $id ): ?string => 42 === $id ? 'jo@example.com' : null;
		$this->assertSame( 42, $t->verify( $token, $email ) );
		$this->assertNull( $t->verify( '43' . substr( $token, 2 ), $email ), 'another id' );
		$this->assertNull( $t->verify( $token, static fn (): string => 'someone@example.com' ), 'the booking now belongs to someone else' );
		$this->assertNull( ( new Token( 'other' ) )->verify( $token, $email ), 'another site' );
		$this->assertNull( $t->verify( 'garbage', $email ) );
	}
}
