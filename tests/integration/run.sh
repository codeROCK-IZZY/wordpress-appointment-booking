#!/usr/bin/env bash
# Installs WordPress in the compose stack, activates the plugin and runs the integration checks, then measures double
# bookings under concurrent requests over real HTTP: this plugin's atomic claims against "check, then insert".
# Usage: bash tests/integration/run.sh   (ROUNDS=20 PARALLEL=40 for a longer run)
set -euo pipefail
cd "$(dirname "$0")/../.."

wp() { docker compose exec -T cli wp "$@"; }
SITE=http://127.0.0.1:8080
ROUNDS=${ROUNDS:-10}
PARALLEL=${PARALLEL:-30}

docker compose up -d
echo "waiting for WordPress"
for _ in $(seq 1 60); do curl -fsS -o /dev/null "$SITE/wp-admin/install.php" 2>/dev/null && break; sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url="$SITE" --title="Northside Physio" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --hard >/dev/null
docker compose exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cp wp-content/plugins/appointment-booking/tests/integration/capture-mail.php wp-content/plugins/appointment-booking/tests/integration/naive-store.php wp-content/mu-plugins/'
wp plugin activate appointment-booking
wp option delete abk_test_naive >/dev/null 2>&1 || true
wp option update abk_settings '{"business":"Northside Physio","location":"12 High St","timezone":"Europe/London","policy":"gap_aware","granularity":15,"notice":0,"horizon":30,"closures":[],"services":[{"name":"Consultation","duration":30,"buffer":0,"price":40},{"name":"Treatment","duration":60,"buffer":15,"price":85}],"staff":[{"name":"Alex","hours":"Mon-Sun 08:00-20:00","services":"all"}]}' --format=json >/dev/null
wp eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}abk_claims" ); $wpdb->query( "DELETE FROM {$wpdb->prefix}abk_bookings" );'

echo "== checks inside WordPress"
wp eval-file wp-content/plugins/appointment-booking/tests/integration/checks.php

fail=0
ok() { echo "  ok    $1"; }
bad() { echo "  FAIL  $1"; fail=1; }

echo "== over HTTP"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/wp-json/abk/v1/services")
[ "$CODE" = "200" ] && ok "services endpoint over HTTP" || bad "services returned $CODE"

# A start time on day N (from today, in the business timezone) at a given local time.
start_at() { wp eval "echo ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/London' ) ) )->modify( '+$1 days' )->setTime( $2, $3 )->getTimestamp();"; }

# Fire PARALLEL booking requests at once for the same person; half for a 60-minute treatment at 10:00 and half for a
# 30-minute consultation at 10:30, which overlaps it. Prints how many bookings were stored for that hour.
race() {
  local day=$1 t1 t2
  t1=$(start_at "$day" 10 0)
  t2=$(start_at "$day" 10 30)
  seq 1 "$PARALLEL" | xargs -P "$PARALLEL" -I{} sh -c '
    if [ $(( {} % 2 )) -eq 0 ]; then svc=treatment; t='"$t1"'; else svc=consultation; t='"$t2"'; fi
    curl -s -o /dev/null -X POST "'"$SITE"'/wp-json/abk/v1/bookings" --data "service=$svc" --data staff=alex --data "start=$t" --data-urlencode "name=Racer {}" --data-urlencode "email=racer{}@example.com"'
  wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$wpdb->prefix}abk_bookings WHERE status = 'confirmed' AND staff = 'alex' AND start_at >= %d AND start_at < %d\", $t1, $t1 + 3600 ) );"
}

echo "== concurrent bookings: $ROUNDS rounds of $PARALLEL simultaneous requests for overlapping times"
atomic_extra=0
for r in $(seq 1 "$ROUNDS"); do
  n=$(race $(( r + 4 )))
  atomic_extra=$(( atomic_extra + n - 1 ))
  [ "$n" = "1" ] || echo "    round $r: $n bookings"
done
[ "$atomic_extra" = "0" ] && ok "atomic claims: exactly one booking in every round (double bookings: 0)" || bad "atomic claims let $atomic_extra double bookings through"

wp option update abk_test_naive 1 >/dev/null
naive_extra=0
naive_rounds=0
for r in $(seq 1 "$ROUNDS"); do
  n=$(race $(( r + 4 + ROUNDS )))
  naive_extra=$(( naive_extra + n - 1 ))
  [ "$n" -gt 1 ] && naive_rounds=$(( naive_rounds + 1 ))
done
wp option delete abk_test_naive >/dev/null
echo "  info  check-then-insert: $naive_extra double bookings, in $naive_rounds of $ROUNDS rounds"
mkdir -p eval/reports
printf '{"rounds": %d, "parallel": %d, "atomic_double_bookings": %d, "check_then_insert_double_bookings": %d, "check_then_insert_rounds_with_doubles": %d}\n' \
  "$ROUNDS" "$PARALLEL" "$atomic_extra" "$naive_extra" "$naive_rounds" > eval/reports/concurrency.json

echo "== uninstall"
wp plugin deactivate appointment-booking >/dev/null
wp plugin uninstall appointment-booking --skip-delete >/dev/null
LEFT=$(wp db query "SHOW TABLES LIKE '%abk%'" --skip-column-names | wc -l)
OPT=$(wp option get abk_settings 2>/dev/null || true)
[ "$LEFT" = "0" ] && [ -z "$OPT" ] && ok "uninstall removes tables and settings" || bad "uninstall left $LEFT tables, option '$OPT'"
wp plugin activate appointment-booking >/dev/null

[ "$fail" = "0" ] && echo "all HTTP checks passed" || { echo "HTTP checks failed"; exit 1; }
