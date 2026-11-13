<?php
/**
 * WordPress privacy tools: export and erase a person's bookings by email address.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Privacy;

use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Mail\Mailer;

/**
 * Exporter and eraser.
 */
final class Privacy {

	/**
	 * Register with WordPress's privacy tools.
	 */
	public static function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( array $exporters ): array {
				$exporters['appointment-booking'] = array(
					'exporter_friendly_name' => __( 'Appointment bookings', 'appointment-booking' ),
					'callback'               => array( self::class, 'export' ),
				);
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( array $erasers ): array {
				$erasers['appointment-booking'] = array(
					'eraser_friendly_name' => __( 'Appointment bookings', 'appointment-booking' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $erasers;
			}
		);
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public static function export( string $email ): array {
		$data = array();
		foreach ( WpdbStore::by_email( $email ) as $row ) {
			$d      = Mailer::details( $row );
			$data[] = array(
				'group_id'    => 'appointment-booking',
				'group_label' => __( 'Appointment bookings', 'appointment-booking' ),
				'item_id'     => 'booking-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Service', 'appointment-booking' ),
						'value' => $d['service'],
					),
					array(
						'name'  => __( 'When', 'appointment-booking' ),
						'value' => $d['when'],
					),
					array(
						'name'  => __( 'With', 'appointment-booking' ),
						'value' => $d['staff'],
					),
					array(
						'name'  => __( 'Name', 'appointment-booking' ),
						'value' => $row['name'],
					),
					array(
						'name'  => __( 'Phone', 'appointment-booking' ),
						'value' => $row['phone'],
					),
					array(
						'name'  => __( 'Notes', 'appointment-booking' ),
						'value' => $row['notes'],
					),
					array(
						'name'  => __( 'Status', 'appointment-booking' ),
						'value' => $row['status'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase: personal details are removed; the time slot stays booked (so the business keeps its schedule) but no
	 * longer says who it is for.
	 *
	 * @param string $email Email.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email ): array {
		$rows = WpdbStore::by_email( $email );
		foreach ( $rows as $row ) {
			WpdbStore::anonymise( (int) $row['id'] );
		}
		return array(
			'items_removed'  => (bool) $rows,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
