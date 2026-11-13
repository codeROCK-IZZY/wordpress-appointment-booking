# Known limitations

- **One resource per booking.** A booking needs one person. Bookings that also need a room or a machine, group
  classes with several places, and multi-service bookings are not supported.
- **No payments or deposits.** Prices are shown in emails and settings, but nothing is charged. Deposits are the
  usual way to cut no-shows, and they are not here.
- **No waitlist.** The evaluation shows most unbooked time comes from demand clustering on certain days and hours,
  not from gaps. A waitlist that offers freed times to people turned away would recover more than slot placement
  does.
- **Customers cannot reschedule in one step.** They cancel and book again.
- **No external calendar sync.** Bookings go out as .ics files, but staff time blocked in Google or Outlook is not
  read back.
- **The concurrency figures come from one machine.** The check-then-insert race showed up in 2 of 10 rounds in Docker
  on a laptop. On a real server it can be more or less frequent. The atomic result does not depend on timing.
- **The slot-offering results are from simulated businesses.** Demand patterns, customer flexibility and choice
  behaviour are assumptions, and real booking logs would be needed to re-tune the threshold.
- **Times in the widget are labelled in the business timezone**, with the visitor's own time underneath when it
  differs. Emails use the business timezone only.
- **The booking rate limit (five successful bookings per address per hour) uses REMOTE_ADDR.** Behind a proxy that
  hides client addresses, everyone shares one limit. Sites behind a proxy should raise it with the
  `appointment_booking_per_hour` filter.
