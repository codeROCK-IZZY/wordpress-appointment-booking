<?php
/**
 * The public booking widget (a shortcode and a block sharing one renderer), and the page a customer reaches from
 * the link in their confirmation email to view or cancel a booking.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Front;

use AppointmentBooking\Mail\Mailer;
use AppointmentBooking\Rest\Controller;
use AppointmentBooking\Settings;

/**
 * Renderer.
 */
final class Widget {

	/**
	 * Register the shortcode, the block, assets and the no-JavaScript cancel handler.
	 */
	public static function register(): void {
		add_shortcode( 'appointment_booking', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'admin_post_nopriv_abk_cancel', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_abk_cancel', array( self::class, 'handle_cancel' ) );
	}

	/**
	 * Register the block from block.json.
	 */
	public static function register_block(): void {
		wp_register_script( 'abk-block-editor', APPOINTMENT_BOOKING_URL . 'blocks/booking-form/index.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), APPOINTMENT_BOOKING_VERSION, true );
		register_block_type( APPOINTMENT_BOOKING_DIR . 'blocks/booking-form', array( 'render_callback' => array( self::class, 'render_block' ) ) );
	}

	/**
	 * Front-end assets, loaded only on pages that render the widget.
	 */
	public static function register_assets(): void {
		wp_register_style( 'abk-booking', APPOINTMENT_BOOKING_URL . 'assets/booking.css', array(), APPOINTMENT_BOOKING_VERSION );
		wp_register_script(
			'abk-booking',
			APPOINTMENT_BOOKING_URL . 'assets/booking.js',
			array(),
			APPOINTMENT_BOOKING_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Shortcode: [appointment_booking service="treatment" staff="alex" title="Book a visit"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'service' => '',
				'staff'   => '',
				'title'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'appointment_booking'
		);
		return self::render( (string) $atts['service'], (string) $atts['staff'], (string) $atts['title'] );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	public static function render_block( array $attributes ): string {
		return self::render( (string) ( $attributes['service'] ?? '' ), (string) ( $attributes['staff'] ?? '' ), (string) ( $attributes['title'] ?? '' ) );
	}

	/**
	 * The widget, or the manage view when the page was opened from a confirmation email.
	 *
	 * @param string $service Preselected service id.
	 * @param string $staff   Preselected staff id.
	 * @param string $title   Heading.
	 */
	public static function render( string $service, string $staff, string $title ): string {
		wp_enqueue_style( 'abk-booking' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed token is the credential.
		$token = isset( $_GET['abk_booking'] ) ? sanitize_text_field( wp_unslash( $_GET['abk_booking'] ) ) : '';
		if ( '' !== $token ) {
			return self::manage( $token );
		}
		wp_enqueue_script( 'abk-booking' );
		$config  = Settings::config();
		$strings = array(
			'loading'  => __( 'Loading…', 'appointment-booking' ),
			'noTimes'  => __( 'No free times on this day. Try another.', 'appointment-booking' ),
			'anyone'   => __( 'Anyone available', 'appointment-booking' ),
			'book'     => __( 'Book', 'appointment-booking' ),
			'booking'  => __( 'Booking…', 'appointment-booking' ),
			'booked'   => __( "You're booked", 'appointment-booking' ),
			'calendar' => __( 'Add to calendar', 'appointment-booking' ),
			'manage'   => __( 'Change or cancel', 'appointment-booking' ),
			'emailed'  => __( 'We have emailed you the details.', 'appointment-booking' ),
			'yourTime' => __( 'your time', 'appointment-booking' ),
			'failed'   => __( 'Something went wrong. Please try again.', 'appointment-booking' ),
			'full'     => __( 'no times', 'appointment-booking' ),
			'free'     => __( 'free', 'appointment-booking' ),
		);
		$data    = array(
			'rest'    => esc_url_raw( rest_url( Controller::NS ) ),
			'service' => $service,
			'staff'   => $staff,
			'page'    => esc_url_raw( (string) get_permalink() ),
			'tz'      => $config->timezone->getName(),
			'strings' => $strings,
		);
		ob_start();
		?>
		<div class="abk" data-abk="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>">
			<?php if ( '' !== $title ) : ?>
				<h2 class="abk-title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Online booking needs JavaScript. Please call or email us to book.', 'appointment-booking' ); ?></p></noscript>
			<div class="abk-pick">
				<label><?php esc_html_e( 'Service', 'appointment-booking' ); ?> <select class="abk-service"></select></label>
				<label><?php esc_html_e( 'With', 'appointment-booking' ); ?> <select class="abk-staff"></select></label>
			</div>
			<div class="abk-days" role="group" aria-label="<?php esc_attr_e( 'Day', 'appointment-booking' ); ?>"></div>
			<div class="abk-times" role="group" aria-live="polite" aria-label="<?php esc_attr_e( 'Time', 'appointment-booking' ); ?>"></div>
			<form class="abk-form" hidden novalidate>
				<p class="abk-chosen" aria-live="polite"></p>
				<label><?php esc_html_e( 'Name', 'appointment-booking' ); ?> <input name="name" autocomplete="name" required maxlength="100"></label>
				<label><?php esc_html_e( 'Email', 'appointment-booking' ); ?> <input name="email" type="email" autocomplete="email" required maxlength="190"></label>
				<label><?php esc_html_e( 'Phone (optional)', 'appointment-booking' ); ?> <input name="phone" type="tel" autocomplete="tel" maxlength="40"></label>
				<label><?php esc_html_e( 'Anything we should know? (optional)', 'appointment-booking' ); ?> <textarea name="notes" rows="2" maxlength="1000"></textarea></label>
				<p class="abk-hp" aria-hidden="true"><label>Website <input name="abk_website" tabindex="-1" autocomplete="off"></label></p>
				<p class="abk-error" role="alert"></p>
				<button type="submit"><?php esc_html_e( 'Book', 'appointment-booking' ); ?></button>
			</form>
			<div class="abk-done" hidden tabindex="-1"></div>
			<p class="abk-tz">
				<?php
				/* translators: %s: timezone name such as Europe/London. */
				echo esc_html( sprintf( __( 'Times are in %s.', 'appointment-booking' ), $config->timezone->getName() ) );
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * View or cancel a booking from the emailed link.
	 *
	 * @param string $token Token.
	 */
	private static function manage( string $token ): string {
		$row = Controller::from_token( $token );
		if ( null === $row ) {
			return '<div class="abk abk-manage"><p>' . esc_html__( 'That link is not valid. It may have been copied incompletely.', 'appointment-booking' ) . '</p></div>';
		}
		$d = Mailer::details( $row );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$note = isset( $_GET['abk_note'] ) ? sanitize_text_field( wp_unslash( $_GET['abk_note'] ) ) : '';
		ob_start();
		?>
		<div class="abk abk-manage">
			<h2 class="abk-title"><?php echo esc_html( $d['service'] ); ?></h2>
			<?php if ( '' !== $note ) : ?>
				<p class="abk-note" role="status"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>
			<dl>
				<dt><?php esc_html_e( 'When', 'appointment-booking' ); ?></dt><dd><?php echo esc_html( $d['when'] ); ?></dd>
				<dt><?php esc_html_e( 'With', 'appointment-booking' ); ?></dt><dd><?php echo esc_html( $d['staff'] ); ?></dd>
				<dt><?php esc_html_e( 'Name', 'appointment-booking' ); ?></dt><dd><?php echo esc_html( $row['name'] ); ?></dd>
				<dt><?php esc_html_e( 'Status', 'appointment-booking' ); ?></dt><dd><?php echo 'confirmed' === $row['status'] ? esc_html__( 'Confirmed', 'appointment-booking' ) : esc_html__( 'Cancelled', 'appointment-booking' ); ?></dd>
			</dl>
			<?php if ( 'confirmed' === $row['status'] ) : ?>
				<p><a href="<?php echo esc_url( add_query_arg( 'token', rawurlencode( $token ), rest_url( Controller::NS . '/bookings/ics' ) ) ); ?>"><?php esc_html_e( 'Add to calendar', 'appointment-booking' ); ?></a></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="abk_cancel">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<button type="submit"><?php esc_html_e( 'Cancel this booking', 'appointment-booking' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cancel from the manage page (works without JavaScript). The signed token is the credential, so there is no
	 * nonce: the link is opened from an email, often in a browser with no session.
	 */
	public static function handle_cancel(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The signed token is the credential.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$row   = Controller::from_token( $token );
		if ( null === $row ) {
			wp_die( esc_html__( 'That link is not valid.', 'appointment-booking' ), '', array( 'response' => 404 ) );
		}
		$result = Controller::cancel_row( $row );
		$page   = '' !== $row['page_url'] ? $row['page_url'] : home_url( '/' );
		$note   = true === $result ? __( 'Your booking is cancelled. We have emailed you a confirmation.', 'appointment-booking' ) : $result;
		wp_safe_redirect(
			add_query_arg(
				array(
					'abk_booking' => rawurlencode( $token ),
					'abk_note'    => rawurlencode( (string) $note ),
				),
				$page
			)
		);
		exit;
	}
}
