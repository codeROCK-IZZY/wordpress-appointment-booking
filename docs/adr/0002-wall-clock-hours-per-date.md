# 2. Store opening hours as wall-clock times and resolve them per date

## Context

A business opens at 09:00 local time all year. Its UTC offset does not stay the same: London is UTC+0 in winter and
UTC+1 in summer. Booking code often works out the offset once, from the server's clock or from WordPress's
`gmt_offset` option, and adds it to every date. That code shows the right times until the clocks change. It is then
an hour out for months, usually without anyone noticing, until customers start arriving at the wrong time.

WordPress makes this easier to get wrong. Its General settings let a site choose a manual offset such as "UTC+1", and
`wp_timezone_string()` then returns `+01:00`, which never changes.

## Decision

- Opening hours are minutes after local midnight per weekday (`Mon-Fri 09:00-17:00`). For each date, the plugin builds
  that date's local midnight in the business timezone with `DateTimeImmutable` and sets the wall-clock time. PHP's
  timezone database then gives the right instant for that date.
- Everything after that works in Unix seconds: free intervals, bookings, claims and calendar files (written in UTC
  with a `Z`, so every calendar app converts them correctly).
- The settings page warns when the timezone is a fixed UTC offset and asks for a city instead.
- The widget shows times in the business timezone. When the visitor's browser is in another zone, it also shows the
  visitor's own time next to each slot.

## Consequences

- `eval/run.php` checks every day of 2026 in twelve timezones: the plugin's start times are exactly 09:00-16:30
  local on every one of the 4,380 days. The offset-worked-out-once shortcut is wrong on 154 to 238 days a year in
  every zone that changes its clocks.
- On the night the clocks go forward, a time that does not exist (such as 01:30 in London) moves forward by PHP's
  rules. Opening hours rarely cross that hour, and the hours parser does not try to second-guess it.
