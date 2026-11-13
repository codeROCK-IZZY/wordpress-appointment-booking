<?php
/**
 * Weekly opening hours, written the way a business owner would: "Mon-Fri 09:00-12:30, 13:30-17:00; Sat 10:00-14:00".
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Opening hours per ISO weekday (1 = Monday), as minutes after local midnight.
 */
final class Hours {

	private const DAYS = array(
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 7,
	);

	/**
	 * Constructor.
	 *
	 * @param array<int, list<array{0: int, 1: int}>> $ranges Weekday => sorted, non-overlapping [open, close] minutes.
	 */
	public function __construct( public readonly array $ranges ) {}

	/**
	 * Parse hours text. Throws on anything it cannot read, naming the part.
	 *
	 * @param string $text Hours text.
	 * @throws \InvalidArgumentException When the text cannot be parsed.
	 */
	public static function parse( string $text ): self {
		$ranges = array();
		foreach ( array_filter( array_map( 'trim', explode( ';', strtolower( $text ) ) ) ) as $part ) {
			if ( ! preg_match( '/^([a-z]{3})(?:\s*-\s*([a-z]{3}))?\s+(.+)$/', $part, $m ) || ! isset( self::DAYS[ $m[1] ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Cannot read "%s": start with a day such as Mon or Mon-Fri.', $part ) );
			}
			$from = self::DAYS[ $m[1] ];
			$to   = '' !== $m[2] ? ( self::DAYS[ $m[2] ] ?? 0 ) : $from;
			if ( $to < $from ) {
				throw new \InvalidArgumentException( sprintf( 'Cannot read the days in "%s".', $part ) );
			}
			$spans = array();
			foreach ( array_map( 'trim', explode( ',', $m[3] ) ) as $span ) {
				if ( ! preg_match( '/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $span, $t ) ) {
					throw new \InvalidArgumentException( sprintf( 'Cannot read the times "%s": use 09:00-17:00.', $span ) );
				}
				$open  = (int) $t[1] * 60 + (int) $t[2];
				$close = (int) $t[3] * 60 + (int) $t[4];
				if ( (int) $t[2] > 59 || (int) $t[4] > 59 || $close > 1440 || $open >= $close ) {
					throw new \InvalidArgumentException( sprintf( 'The times "%s" must open before they close, within one day.', $span ) );
				}
				$spans[] = array( $open, $close );
			}
			for ( $d = $from; $d <= $to; $d++ ) {
				$ranges[ $d ] = self::merge( array_merge( $ranges[ $d ] ?? array(), $spans ) );
			}
		}
		ksort( $ranges );
		return new self( $ranges );
	}

	/**
	 * Ranges for a weekday.
	 *
	 * @param int $weekday ISO weekday.
	 * @return list<array{0: int, 1: int}>
	 */
	public function on( int $weekday ): array {
		return $this->ranges[ $weekday ] ?? array();
	}

	/**
	 * Back to text, in the same format parse() reads.
	 */
	public function text(): string {
		$names = array_flip( self::DAYS );
		$out   = array();
		foreach ( $this->ranges as $day => $spans ) {
			$out[] = ucfirst( $names[ $day ] ) . ' ' . implode(
				', ',
				array_map( static fn ( array $s ): string => sprintf( '%02d:%02d-%02d:%02d', intdiv( $s[0], 60 ), $s[0] % 60, intdiv( $s[1], 60 ), $s[1] % 60 ), $spans )
			);
		}
		return implode( '; ', $out );
	}

	/**
	 * Sort and merge overlapping spans.
	 *
	 * @param list<array{0: int, 1: int}> $spans Spans.
	 * @return list<array{0: int, 1: int}>
	 */
	private static function merge( array $spans ): array {
		usort( $spans, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
		$out = array();
		foreach ( $spans as [ $open, $close ] ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $open <= $out[ $last ][1] ) {
				$out[ $last ] = array( $out[ $last ][0], max( $out[ $last ][1], $close ) );
			} else {
				$out[] = array( $open, $close );
			}
		}
		return $out;
	}
}
