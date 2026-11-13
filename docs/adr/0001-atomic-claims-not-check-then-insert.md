# 1. Claim time atomically instead of checking, then inserting

## Context

The usual way to take a booking is to look for an overlapping booking and insert one if there is none. Two requests
that arrive together both look, both see the time as free, and both insert. That race is common on busy days, such as
when a class opens or a popular stylist's diary is released. The business finds out when two customers arrive at once.

Locking the whole table, or `SELECT ... FOR UPDATE`, works on some hosts and not others. It depends on the storage
engine and the isolation level, and cheap shared hosting is where most WordPress sites live.

## Decision

Each booking claims its time in five-minute units in a claims table whose primary key is `(staff, unit)`:

1. Insert the booking as `pending`.
2. Insert all of its units, including the buffer after the service, in **one** multi-row `INSERT`. InnoDB applies a
   statement entirely or not at all, so a single unit already taken fails the whole claim.
3. On success, mark the booking `confirmed`. On a duplicate key, delete the pending row and tell the customer the time
   has just gone.

The units are on an absolute five-minute grid, not the opening-hours grid, so two bookings that overlap always share a
unit, even if the hours or the slot interval changed between them.

## Consequences

- Measured over real HTTP against WordPress and MariaDB (`tests/integration/run.sh`): rounds of simultaneous
  requests for overlapping times end with exactly one booking each. The same requests against a check-then-insert
  store (installed only for the test) produce double bookings. Numbers are in the README.
- A booking only confirms after its claim, so availability reads only confirmed bookings. A pending row is never
  shown as busy, and a process that dies between steps leaves an orphaned pending row that blocks nothing.
- The cost is one row per five minutes of booked time. A busy single-person diary books about 100 rows a day,
  which is small next to `wp_postmeta`.
- A booking that starts off the five-minute grid (only possible with hand-edited hours such as 09:07) can falsely
  conflict with the booking before it. Opening hours are on five-minute boundaries in practice.
