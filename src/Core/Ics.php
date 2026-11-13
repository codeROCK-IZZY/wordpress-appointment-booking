<?php
/**
 * Calendar files (RFC 5545) for confirmations and cancellations.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * ICS builder.
 */
final class Ics {

	/**
	 * One event as a calendar file. Times are written in UTC, so every calendar app shows the right local time.
	 *
	 * @param string $uid         Stable unique id (the same for the confirmation and the cancellation).
	 * @param int    $start       Start.
	 * @param int    $end         End.
	 * @param string $summary     Title.
	 * @param string $description Description.
	 * @param string $location    Location.
	 * @param bool   $cancelled   Whether this cancels the event.
	 * @param int    $now         Time stamp.
	 */
	public static function event( string $uid, int $start, int $end, string $summary, string $description, string $location, bool $cancelled, int $now ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Appointment Booking//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:' . ( $cancelled ? 'CANCEL' : 'PUBLISH' ),
			'BEGIN:VEVENT',
			'UID:' . self::escape( $uid ),
			'SEQUENCE:' . ( $cancelled ? 1 : 0 ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $now ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $start ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $end ),
			'SUMMARY:' . self::escape( $summary ),
			'DESCRIPTION:' . self::escape( $description ),
			'LOCATION:' . self::escape( $location ),
			'STATUS:' . ( $cancelled ? 'CANCELLED' : 'CONFIRMED' ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Escape a text value.
	 *
	 * @param string $text Text.
	 */
	public static function escape( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), $text );
	}

	/**
	 * Fold a line at 75 octets without splitting a UTF-8 character.
	 *
	 * @param string $line Line.
	 */
	public static function fold( string $line ): string {
		$out    = array();
		$limit  = 75;
		$length = strlen( $line );
		while ( $length > $limit ) {
			$cut = $limit;
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out[]  = substr( $line, 0, $cut );
			$line   = substr( $line, $cut );
			$length = strlen( $line );
			$limit  = 74; // Continuation lines start with a space.
		}
		$out[] = $line;
		return implode( "\r\n ", $out );
	}
}
