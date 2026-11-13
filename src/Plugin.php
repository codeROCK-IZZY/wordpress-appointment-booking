<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking;

use AppointmentBooking\Admin\BookingsPage;
use AppointmentBooking\Admin\SettingsPage;
use AppointmentBooking\Core\Booker;
use AppointmentBooking\Core\BookingStore;
use AppointmentBooking\Core\SlotPolicy;
use AppointmentBooking\Data\Schema;
use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Front\Widget;
use AppointmentBooking\Mail\Mailer;
use AppointmentBooking\Privacy\Privacy;
use AppointmentBooking\Rest\Controller;

/**
 * Hook registration and shared services.
 */
final class Plugin {

	public const CRON = 'abk_send_reminders';

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		if ( get_option( 'abk_db_version' ) !== Schema::DB_VERSION ) {
			Schema::install();
		}
		add_action( self::CRON, array( Mailer::class, 'send_reminders' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::CRON );
				}
			}
		);
		Widget::register();
		Controller::register();
		Privacy::register();
		if ( is_admin() ) {
			BookingsPage::register();
			SettingsPage::register();
		}
	}

	/**
	 * Create tables and default settings.
	 */
	public static function activate(): void {
		Schema::install();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
	}

	/**
	 * Remove the scheduled event (bookings are kept until uninstall).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The booking store. Filterable so a site can swap storage (and so the integration suite can compare a naive one).
	 */
	public static function store(): BookingStore {
		$store = apply_filters( 'appointment_booking_store', null );
		return $store instanceof BookingStore ? $store : new WpdbStore();
	}

	/**
	 * A booker for the current settings.
	 */
	public static function booker(): Booker {
		$config = Settings::config();
		return new Booker( $config, self::store(), new SlotPolicy( $config->policy ) );
	}
}
