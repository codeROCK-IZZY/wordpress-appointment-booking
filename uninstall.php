<?php
/**
 * Removes the tables, settings and scheduled event when the plugin is deleted.
 *
 * @package AppointmentBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\AppointmentBooking\Data\Schema::drop();
delete_option( 'abk_settings' );
delete_option( 'abk_db_version' );
wp_clear_scheduled_hook( 'abk_send_reminders' );
