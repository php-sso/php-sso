#!/usr/bin/env bash
# Runs the OpenID conformance suite against a throwaway copy of php-sso. See README.md.
#
#   conformance/run.sh up            start the suite and a test php-sso, and prepare the test clients
#   conformance/run.sh run [PLAN]    run a test plan (default: Basic OP) and save the results
#   conformance/run.sh down          stop and remove everything
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)            # holds php-sso/ and conformance-suite/
SUITE=$ROOT/conformance-suite
STATE=$HERE/.state
TAG=release-v5.3.4
NETWORK=conformance_default
PLAN_DEFAULT='oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]'

up() {
    [ -d "$SUITE" ] || { echo "Clone the suite first: git clone --depth 1 --branch $TAG https://gitlab.com/openid/conformance-suite.git $SUITE" >&2; exit 1; }
    mkdir -p "$STATE/var" "$STATE/results"
    chmod 777 "$STATE/var"

    (cd "$SUITE" && IMAGE_TAG=$TAG docker compose -f docker-compose-prebuilt.yml -p conformance up -d)
    until [ "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/api/runner/available)" = 200 ]; do sleep 3; done

    docker rm -f sso-conformance >/dev/null 2>&1 || true
    docker run -d --name sso-conformance --network "$NETWORK" --network-alias sso --user "$(id -u):$(id -g)" \
        -v "$ROOT":/work -v "$STATE/var":/work/php-sso/var -w /work/php-sso \
        -e SSO_PUBLIC_URL=http://sso:9000 -e ADMIN_PASSWORD=conformance-admin \
        -e SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS=1 -e SSO_ALLOW_INSECURE_HTTP=1 -e PHP_CLI_SERVER_WORKERS=4 \
        php:8.4-cli php -S 0.0.0.0:9000 -t public >/dev/null
    until docker exec sso-conformance php -r 'exit(@file_get_contents("http://sso:9000/.well-known/openid-configuration") ? 0 : 1);' 2>/dev/null; do sleep 1; done
    docker exec sso-conformance php conformance/setup.php
    echo "Suite UI: https://localhost.emobix.co.uk:8443/  (accept the self-signed certificate)"
}

run() {
    local plan=${1:-$PLAN_DEFAULT}
    mkdir -p "$STATE/results"
    docker run --rm --network "$NETWORK" -v "$SUITE":/suite:ro -v "$HERE":/conf:ro -v "$STATE/results":/out -w /suite/scripts \
        python:3.12-slim sh -c "pip install -q --disable-pip-version-check -r requirements.txt && python run-test-plan.py --export-dir /out --verbose '$plan' /conf/plan-config.json"
}

down() {
    docker rm -f sso-conformance >/dev/null 2>&1 || true
    (cd "$SUITE" && IMAGE_TAG=$TAG docker compose -f docker-compose-prebuilt.yml -p conformance down)
}

case "${1:-}" in
    up) up ;;
    run) run "${2:-}" ;;
    down) down ;;
    *) sed -n '2,7p' "$0"; exit 1 ;;
esac
