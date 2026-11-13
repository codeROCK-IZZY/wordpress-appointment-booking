# Appointment booking: evaluation

Simulated businesses taking bookings for two weeks with more demand than they can serve, and opening hours checked on every day of 2026 in twelve timezones.

## Tuning businesses: 20 businesses, 6,207 customers

| Times offered | Customers booked | Staff time booked | Revenue | Times shown per customer |
|---|---:|---:|---:|---:|
| Every start time that fits (common default) | 3,951 (63.7%) | 65.0% | $255,305 | 15.5 |
| Fixed grid the length of the service | 3,845 (62.0%) | 60.4% | $240,265 | 7.6 |
| Gap-aware, first free person (ablation) | 4,040 (65.1%) | 67.3% | $264,545 | 9.2 |
| Gap-aware, best-fitting person (this plugin) | 4,041 (65.1%) | 67.3% | $264,595 | 9.2 |

## Held-out businesses: 30 businesses, 10,354 customers

| Times offered | Customers booked | Staff time booked | Revenue | Times shown per customer |
|---|---:|---:|---:|---:|
| Every start time that fits (common default) | 6,786 (65.5%) | 66.8% | $472,335 | 16 |
| Fixed grid the length of the service | 6,605 (63.8%) | 63.4% | $450,400 | 7.4 |
| Gap-aware, first free person (ablation) | 6,949 (67.1%) | 69.6% | $490,810 | 9.6 |
| Gap-aware, best-fitting person (this plugin) | 6,946 (67.1%) | 69.6% | $490,425 | 9.6 |

## Different shape (held back): 10 businesses, 10,640 customers

| Times offered | Customers booked | Staff time booked | Revenue | Times shown per customer |
|---|---:|---:|---:|---:|
| Every start time that fits (common default) | 5,519 (51.9%) | 73.0% | $374,370 | 20.5 |
| Fixed grid the length of the service | 5,061 (47.6%) | 61.7% | $319,885 | 6 |
| Gap-aware, first free person (ablation) | 5,729 (53.8%) | 77.8% | $397,410 | 12.2 |
| Gap-aware, best-fitting person (this plugin) | 5,719 (53.8%) | 77.5% | $395,700 | 12.2 |

## Opening hours across daylight saving (every day of 2026, 09:00-17:00 local)

| Timezone | Changes clocks | Days wrong: this plugin | Days wrong: offset worked out once |
|---|---|---:|---:|
| Europe/London | yes | 0 | 210 |
| Europe/Berlin | yes | 0 | 210 |
| America/New_York | yes | 0 | 238 |
| America/Chicago | yes | 0 | 238 |
| America/Los_Angeles | yes | 0 | 238 |
| America/Sao_Paulo | no | 0 | 0 |
| America/Santiago | yes | 0 | 154 |
| Australia/Sydney | yes | 0 | 182 |
| Pacific/Auckland | yes | 0 | 175 |
| Asia/Kolkata | no | 0 | 0 |
| Africa/Lagos | no | 0 | 0 |
| Asia/Tokyo | no | 0 | 0 |

## Gate (regression limits, not targets)

- PASS heldout_booked_gap_minus_every_min: 0.0155 (limit 0)
- PASS heldout_revenue_gap_vs_every_min: 1.0383 (limit 1)
- PASS different_booked_gap_minus_every_min: 0.0188 (limit -0.01)
- PASS dst_plugin_wrong_days_max: 0 (limit 0)
