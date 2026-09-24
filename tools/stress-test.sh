#!/usr/bin/env bash
#
# Stress test for the Hubstr relay.
#
# Spawns a real relay process (see tools/lib/relay-under-test.sh), then drives load with nak:
#   - hundreds of signed events from one authenticated user (the admin/tenant)
#   - thousands of REQ subscriptions from unauthenticated guests
# Stored events are verified with a COUNT query, and read throughput reported.
#
# Tunables (env), on top of the fixture's PORT, DATABASE and PHP_BIN:
#   EVENTS     number of events the authenticated user sends   (default 300)
#   REQS       number of unauthenticated REQ subscriptions      (default 3000)
#   WRITE_PAR  parallel write connections                       (default 20)
#   READ_PAR   parallel read connections                        (default 100)
#
# The relay is launched with xdebug.mode=off; xdebug cuts throughput
# drastically and would skew the numbers. Override the binary with PHP_BIN.
#
# Usage:  tools/stress-test.sh

set -euo pipefail

EVENTS=${EVENTS:-300}
REQS=${REQS:-3000}
WRITE_PAR=${WRITE_PAR:-20}
READ_PAR=${READ_PAR:-100}

# shellcheck source=tools/lib/relay-under-test.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/relay-under-test.sh"

start_relay_under_test
echo "==> raising rate limits (NIP-86): $(raise_rate_limits)"

now() { date +%s.%N; }
elapsed() { awk "BEGIN{printf \"%.2f\", $2 - $1}"; }
rate() { awk "BEGIN{d=$3-$2; printf \"%.0f\", (d>0)? $1/d : 0}"; }

echo "==> phase 1: ${EVENTS} events from authenticated user (par=${WRITE_PAR})"
w_start="$(now)"
seq 1 "${EVENTS}" | xargs -P "${WRITE_PAR}" -I{} \
    sh -c "nak event -k 1 -c 'stress event {}' --sec '${ADMIN_SEC}' -q '${WS}' >/dev/null 2>&1" || true
w_end="$(now)"

STORED="$(nak count -k 1 -a "${ADMIN_PUB}" -q "${WS}" 2>&1 </dev/null | grep -oE '[0-9]+$' | tail -1 || true)"
STORED="${STORED:-0}"
echo "    sent=${EVENTS} stored=${STORED} in $(elapsed "${w_start}" "${w_end}")s ($(rate "${STORED}" "${w_start}" "${w_end}") ev/s)"

echo "==> phase 2: ${REQS} REQ subscriptions from unauthenticated guests (par=${READ_PAR})"
r_start="$(now)"
OK="$(seq 1 "${REQS}" | xargs -P "${READ_PAR}" -I{} \
    sh -c "nak req -k 1 -a '${ADMIN_PUB}' -l 50 -q '${WS}' >/dev/null 2>&1 && echo ok" | grep -c ok || true)"
r_end="$(now)"
echo "    succeeded=${OK}/${REQS} in $(elapsed "${r_start}" "${r_end}")s ($(rate "${OK}" "${r_start}" "${r_end}") req/s)"

echo "==> summary"
fail=0
if [ "${STORED}" -ge "${EVENTS}" ]; then
    echo "    PASS  all ${EVENTS} authenticated events stored"
else
    echo "    FAIL  only ${STORED}/${EVENTS} events stored"; fail=1
fi
if [ "${OK}" -ge "${REQS}" ]; then
    echo "    PASS  all ${REQS} guest requests served"
else
    echo "    WARN  ${OK}/${REQS} guest requests served"
fi

exit "${fail}"
