# 3. Offer start times that do not strand short gaps

## Context

Most booking plugins offer every start time that fits, every 15 minutes. Customers pick times that suit them, and a
60-minute booking at 10:15 can leave 10:00-10:15 and other short gaps that no service fits into. The diary looks full
while staff stand idle. The opposite habit is a fixed grid (a 60-minute service only on the hour). It wastes little
time but offers few choices, so customers whose window falls between grid times go elsewhere.

## Decision

Offer a feasible start only if, on each side, it leaves either no gap or a gap at least three times the shortest
service that person offers. Always offer the earliest and the latest start in each free interval, so short intervals
can still be used.

The factor was chosen on 20 simulated tuning businesses (salons, clinics, spas and tutors, two weeks each, with more
demand than capacity). Customers want a service on a preferred day or a few days later, at any time or within a
two-to-four-hour window. Half take the earliest suitable time and half pick one at random.

| Factor | Customers booked | Staff time booked | Times shown per customer |
|---|---:|---:|---:|
| every start (no filter) | 63.7% | 65.0% | 15.5 |
| 3 | **65.1%** | 67.3% | 9.2 |
| 4 | 64.8% | 67.4% | 7.8 |
| 6 | 64.7% | 67.6% | 5.8 |
| edges only | 61.4% | 64.8% | 3.5 |

Factors 1, 1.5 and 2 were tried first on the first 10 tuning businesses: +0.1, +0.7 and +0.7 points over every
start, against +1.3 for factor 3, so the search moved upward.

Factor 3 booked the most customers. Factors 4 and 6 sold slightly more staff time but turned more customers away.

Assigning an "anyone" booking to the person it strands the least time for made no measurable difference (65.1%
both ways). It is kept because it costs nothing, but no gain is claimed for it.

## Consequences

- Held-out businesses (30, run once after freezing): 67.1% of customers booked against 65.5% for every start,
  3.8% more revenue from the same staff hours, and about 10 times shown per customer instead of 16. A held-back
  shape (more staff, 10-minute slots, heavier demand, narrower windows): +1.9 points.
- The effect is real but modest. Utilisation stays under 70% because demand clusters on certain days and hours, not
  because of fragmentation. Most unused time is time nobody asked for.
- A business can switch to "every time" or "fixed grid" in settings.
