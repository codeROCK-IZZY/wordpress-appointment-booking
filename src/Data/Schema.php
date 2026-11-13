<?php
/**
 * Database tables.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Data;

/**
 * Two tables: bookings, and the five-minute units each booking claims. The claims table's primary key
 * (staff, unit) is what makes double booking impossible: the database refuses a second claim on any unit.
 */
final class Schema {

	public const DB_VERSION = '1';

	/**
	 * Bookings table.
	 */
	public static function bookings(): string {
		global $wpdb;
		return $wpdb->prefix . 'abk_bookings';
	}

	/**
	 * Claims table.
	 */
	public static function claims(): string {
		global $wpdb;
		return $wpdb->prefix . 'abk_claims';
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::bookings() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				service varchar(64) NOT NULL,
				staff varchar(64) NOT NULL,
				start_at bigint(20) NOT NULL,
				end_at bigint(20) NOT NULL,
				block_end bigint(20) NOT NULL,
				name varchar(100) NOT NULL,
				email varchar(190) NOT NULL,
				phone varchar(40) NOT NULL DEFAULT '',
				notes text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				page_url varchar(255) NOT NULL DEFAULT '',
				reminded tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY staff_start (staff, start_at),
				KEY status_start (status, start_at),
				KEY email (email)
			) {$charset};"
		);
		dbDelta(
			'CREATE TABLE ' . self::claims() . " (
				staff varchar(64) NOT NULL,
				unit bigint(20) NOT NULL,
				booking_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (staff, unit),
				KEY booking_id (booking_id)
			) {$charset};"
		);
		update_option( 'abk_db_version', self::DB_VERSION );
	}

	/**
	 * Drop the tables (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::claims() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::bookings() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
	}
}
