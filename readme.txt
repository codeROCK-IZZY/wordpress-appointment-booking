=== Appointment Booking ===
Contributors: israelrocklyn
Tags: booking, appointments, calendar, scheduling, salon
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Online booking that cannot double-book, keeps opening hours right when the clocks change, and offers times that do not strand unbookable gaps.

== Description ==

* A booking widget as a block or the [appointment_booking] shortcode: service, person (or anyone), a strip of days with free times, the times, and a short form. Visitors in another timezone see their own time next to each slot.
* No double bookings, even when two people press "Book" at the same moment: each booking claims its time in one atomic database statement.
* Opening hours are wall-clock times in the business timezone, so 09:00 stays 09:00 across daylight saving changes. The settings page warns when the timezone is a fixed UTC offset.
* Gap-aware times: only offer starts that leave gaps long enough to sell, so the day fills better.
* Buffers after services, lunch breaks, closures, minimum notice and a booking horizon.
* Confirmation emails with a calendar file, a link to view or cancel without an account, a reminder the day before, and cancellation emails that remove the calendar entry.
* A day agenda per person in the admin, with cancel actions.
* Personal data export and erasure through WordPress's privacy tools.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Set your timezone, services and staff hours under Bookings → Settings.
3. Add the "Appointment booking" block to a page.

== Frequently Asked Questions ==

= Can two customers book the same time? =

No. The time is claimed in five-minute units with a database primary key, and the whole claim is one statement: of two overlapping bookings arriving together, exactly one succeeds and the other customer is asked to pick another time.

= What happens when the clocks change? =

Nothing visible: hours are worked out for each date in your timezone. Choose a city (for example Europe/London), not a UTC offset.

== Changelog ==

= 1.0.0 =
* First release.
