#!/usr/bin/env bash
#
# Leak test for the Hubstr relay.
#
# Spawns a real relay process (see tools/lib/relay-under-test.sh) and drives
# sustained connection churn for a fixed duration: repeated short-lived REQ
# subscriptions (each opens and closes a websocket) interleaved with writes.
# Meanwhile it samples the relay's resident memory (RSS) and open file
# descriptor count at a fixed interval, so a memory or connection leak shows
# up as monotonic growth rather than a plateau.
#
# The connection churn is the point: a subscription that does not tear its
# connection down leaks a socket per request, which surfaces here as a rising
# FD count even while throughput looks fine.
#
# Tunables (env), on top of the fixture's PORT, DATABASE and PHP_BIN:
#   DURATION   soak duration in seconds                    (default 120)
#   INTERVAL   sampling interval in seconds                (default 5)
#   BATCH      short-lived REQ connections per round        (default 60)
#   READ_PAR   parallel read connections                    (default 60)
#   WRITES     writes per round                             (default 5)
#
# Usage:  tools/leak-test.sh

set -euo pipefail

DURATION=${DURATION:-120}
INTERVAL=${INTERVAL:-5}
BATCH=${BATCH:-60}
READ_PAR=${READ_PAR:-60}
WRITES=${WRITES:-5}

# shellcheck source=tools/lib/relay-under-test.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/relay-under-test.sh"

start_relay_under_test
raise_rate_limits >/dev/null
echo "==> rate limits raised; seeding events"
seq 1 50 | xargs -P 10 -I{} sh -c "nak event -k 1 -c 'seed {}' --sec '${ADMIN_SEC}' -q '${WS}' >/dev/null 2>&1" || true

load_loop() {
    while :; do
        seq 1 "${BATCH}" | xargs -P "${READ_PAR}" -I{} \
            sh -c "nak req -k 1 -a '${ADMIN_PUB}' -l 10 -q '${WS}' >/dev/null 2>&1" || true
        seq 1 "${WRITES}" | xargs -P "${WRITES}" -I{} \
            sh -c "nak event -k 1 -c 'soak {}' --sec '${ADMIN_SEC}' -q '${WS}' >/dev/null 2>&1" || true
    done
}
load_loop &
LOAD_PID=$!
BACKGROUND_PIDS+=("${LOAD_PID}")

rss_kb() { awk '/^VmRSS:/{print $2}' "/proc/$1/status" 2>/dev/null || echo 0; }
fd_count() { ls "/proc/$1/fd" 2>/dev/null | wc -l; }
tree_rss_kb() {
    local total; total=0
    for pid in $1 $(pgrep -P "$1" 2>/dev/null || true); do
        total=$((total + $(rss_kb "$pid")))
    done
    echo "${total}"
}

echo "==> soak: ${DURATION}s, sampling every ${INTERVAL}s (churning ${BATCH} conns/round, par=${READ_PAR})"
printf "    %6s  %10s  %12s  %6s\n" "t(s)" "rss(MB)" "tree(MB)" "fds"

samples_rss=(); samples_fd=()
start="$(date +%s)"
while :; do
    t=$(( $(date +%s) - start ))
    [ "${t}" -ge "${DURATION}" ] && break
    relay_is_alive || show_relay_log_and_fail "    relay died during soak"

    rss=$(rss_kb "${RELAY_PID}"); tree=$(tree_rss_kb "${RELAY_PID}"); fds=$(fd_count "${RELAY_PID}")
    samples_rss+=("${rss}"); samples_fd+=("${fds}")
    printf "    %6s  %10.1f  %12.1f  %6s\n" "${t}" "$(awk "BEGIN{print ${rss}/1024}")" "$(awk "BEGIN{print ${tree}/1024}")" "${fds}"
    sleep "${INTERVAL}"
done

kill "${LOAD_PID}" 2>/dev/null || true
sleep 3
final_rss=$(rss_kb "${RELAY_PID}"); final_fds=$(fd_count "${RELAY_PID}")

first_rss=${samples_rss[0]}; peak_rss=0
for v in "${samples_rss[@]}"; do [ "${v}" -gt "${peak_rss}" ] && peak_rss=${v}; done
first_fd=${samples_fd[0]}; peak_fd=0
for v in "${samples_fd[@]}"; do [ "${v}" -gt "${peak_fd}" ] && peak_fd=${v}; done

echo "==> summary"
printf "    RSS   first=%.1fMB  peak=%.1fMB  final(after drain)=%.1fMB\n" \
    "$(awk "BEGIN{print ${first_rss}/1024}")" "$(awk "BEGIN{print ${peak_rss}/1024}")" "$(awk "BEGIN{print ${final_rss}/1024}")"
printf "    FDs   first=%s  peak=%s  final(after drain)=%s\n" "${first_fd}" "${peak_fd}" "${final_fds}"

fail=0
if [ "${final_fds}" -gt $(( first_fd + 20 )) ]; then
    echo "    FAIL  file descriptors did not return to baseline after drain (leak suspected)"; fail=1
else
    echo "    PASS  file descriptors returned to baseline after drain"
fi
grow=$(awk "BEGIN{printf \"%d\", (${final_rss}-${first_rss})*100/(${first_rss}>0?${first_rss}:1)}")
if [ "${grow}" -gt 50 ] && [ $(( final_rss - first_rss )) -gt 51200 ]; then
    echo "    WARN  RSS grew ${grow}% over the soak (>50MB); inspect for a leak"
else
    echo "    PASS  RSS stayed within ${grow}% of the first sample"
fi

exit "${fail}"
