<?php
/**
 * Which of the feasible start times to show customers.
 *
 * - `every`: every start on the granularity grid that fits. The most choice, but a 60-minute booking at 10:15 strands
 *   15 minutes that nothing can use.
 * - `grid`: only starts on a grid the length of the service, from opening time (a 60-minute service at 09:00, 10:00, ...).
 *   Little waste, much less choice.
 * - `gap_aware` (the default): every feasible start that leaves, on each side, either no gap or a gap of at least three
 *   times the shortest block this person offers; plus the earliest and latest start in each free interval, so a short
 *   free interval can still be used. The factor 3 was chosen on 20 simulated tuning businesses (see eval/).
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Slot policy.
 */
final class SlotPolicy {

	/**
	 * Constructor.
	 *
	 * @param string $name   One of Config::POLICIES.
	 * @param float  $factor Gap-aware only: a gap counts as usable when it is at least this many shortest blocks long.
	 */
	public function __construct( public readonly string $name = 'gap_aware', public readonly float $factor = 3.0 ) {}

	/**
	 * Choose starts from the feasible ones in one free interval.
	 *
	 * @param list<int> $feasible Feasible starts, ascending.
	 * @param int       $a        Interval start.
	 * @param int       $b        Interval end.
	 * @param int       $block    Seconds the booking takes (with buffer).
	 * @param int       $min      Seconds of the shortest block this person offers.
	 * @param int       $anchor   Opening time of the range the interval is in.
	 * @return list<int>
	 */
	public function choose( array $feasible, int $a, int $b, int $block, int $min, int $anchor ): array {
		if ( ! $feasible || 'every' === $this->name ) {
			return $feasible;
		}
		if ( 'grid' === $this->name ) {
			return array_values( array_filter( $feasible, static fn ( int $t ): bool => 0 === ( $t - $anchor ) % $block ) );
		}
		$usable = (int) ceil( $min * $this->factor );
		$keep   = array();
		foreach ( $feasible as $i => $t ) {
			$left  = $t - $a;
			$right = $b - ( $t + $block );
			$edge  = 0 === $i || count( $feasible ) - 1 === $i;
			if ( $edge || ( ( 0 === $left || $left >= $usable ) && ( 0 === $right || $right >= $usable ) ) ) {
				$keep[] = $t;
			}
		}
		return $keep;
	}
}
