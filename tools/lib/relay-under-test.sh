#!/usr/bin/env bash
#
# Shared fixture for the network harnesses: spawns a real relay on a free port
# against a throwaway config and database, waits until it answers a REQ, and
# raises its rate limits over NIP-86 so the harness can drive load unthrottled.
#
# Every direct nak call has its stdin closed: nak reads an event or a filter from
# an open non-terminal stdin and would block when the script is run non-interactively.
#
# Requires: nak, php, curl, sha256sum, base64 (and python3 for free-port pick).
#
# Tunables (env):
#   PORT       relay port                                   (default: a free one)
#   DATABASE   an existing database to run against            (default: a fresh temp one)
#   PHP_BIN    php binary to launch the relay with           (default: php)
#
# Provides: ROOT, WS, HTTP, WORKDIR, ADMIN_SEC, ADMIN_PUB, RELAY_PID,
# and the functions start_relay_under_test, raise_rate_limits and
# relay_is_alive. A harness that starts background work registers its pids in
# BACKGROUND_PIDS so cleanup ends them before the relay.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN=${PHP_BIN:-php}

for bin in nak "${PHP_BIN}" curl sha256sum base64; do
    command -v "$bin" >/dev/null 2>&1 || { echo "missing required tool: $bin" >&2; exit 1; }
done

pick_port() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'
    else
        echo 8798
    fi
}
PORT=${PORT:-$(pick_port)}

WS="ws://127.0.0.1:${PORT}"
HTTP="http://127.0.0.1:${PORT}"

WORKDIR="$(mktemp -d)"
CONFIG="${WORKDIR}/relay.php"
RELAY_PID=""
BACKGROUND_PIDS=()

cleanup() {
    for pid in "${BACKGROUND_PIDS[@]}"; do kill "${pid}" 2>/dev/null || true; done
    [ -n "${RELAY_PID}" ] && kill "${RELAY_PID}" 2>/dev/null || true
    [ -n "${RELAY_PID}" ] && wait "${RELAY_PID}" 2>/dev/null || true
    rm -rf "${WORKDIR}"
}
trap cleanup EXIT

ADMIN_SEC="$(nak key generate </dev/null)"
ADMIN_PUB="$(nak key public "${ADMIN_SEC}")"

cat > "${CONFIG}" <<PHP
<?php

declare(strict_types=1);

return [
    'admin_pubkey' => '${ADMIN_PUB}',
    'host' => '127.0.0.1',
    'port' => ${PORT},
    'relay_url' => '${WS}',
    'database_path' => '${DATABASE:-${WORKDIR}/relay.sqlite}',
    'connection_limits' => [
        'max_connections' => 100000,
    ],
    'trusted_proxies' => ['127.0.0.1'],
    'log_level' => 'warning',
];
PHP

relay_is_alive() {
    kill -0 "${RELAY_PID}" 2>/dev/null
}

show_relay_log_and_fail() {
    echo "$1; see ${WORKDIR}/relay.log" >&2
    cat "${WORKDIR}/relay.log" >&2 || true
    exit 1
}

start_relay_under_test() {
    echo "==> relay on ${WS}  (admin ${ADMIN_PUB:0:12}...)"
    HUBSTR_RELAY_CONFIG="${CONFIG}" "${PHP_BIN}" -d xdebug.mode=off "${ROOT}/bin/hubstr-relay.php" > "${WORKDIR}/relay.log" 2>&1 &
    RELAY_PID=$!

    echo -n "==> waiting for relay (websocket)"
    local ready=0
    for _ in $(seq 1 100); do
        if nak req -k 1 -l 1 "${WS}" >/dev/null 2>&1 </dev/null; then ready=1; break; fi
        relay_is_alive || { echo; show_relay_log_and_fail "relay died on startup"; }
        echo -n "."; sleep 0.2
    done
    echo
    [ "${ready}" = 1 ] || { echo "relay did not become ready" >&2; exit 1; }
}

raise_rate_limits() {
    local body hash auth
    body='{"method":"setratelimits","params":[{"events_per_minute":100000000,"subscriptions_per_minute":100000000}]}'
    hash="$(printf '%s' "${body}" | sha256sum | cut -d' ' -f1)"
    auth="$(nak event -k 27235 -c '' \
        -t "u=${HTTP}" -t method=POST -t "payload=${hash}" \
        --sec "${ADMIN_SEC}" -q 2>/dev/null </dev/null | base64 -w0)"
    curl -s -X POST "${HTTP}" \
        -H "Authorization: Nostr ${auth}" \
        -H 'Content-Type: application/nostr+json+rpc' \
        --data-raw "${body}"
}
