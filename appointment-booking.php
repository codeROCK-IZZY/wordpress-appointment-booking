<?php
/**
 * Plugin Name:       Appointment Booking
 * Plugin URI:        https://github.com/codeROCK-IZZY/wordpress-appointment-booking
 * Description:       Online booking that cannot double-book, keeps opening hours right when the clocks change, and offers times that do not strand unbookable gaps.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Israel Rocklyn
 * Author URI:        https://israelrocklyn.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       appointment-booking
 *
 * @package AppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

define( 'APPOINTMENT_BOOKING_VERSION', '1.0.0' );
define( 'APPOINTMENT_BOOKING_FILE', __FILE__ );
define( 'APPOINTMENT_BOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'APPOINTMENT_BOOKING_URL', plugin_dir_url( __FILE__ ) );

require_once APPOINTMENT_BOOKING_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \AppointmentBooking\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AppointmentBooking\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \AppointmentBooking\Plugin::class, 'boot' ) );
