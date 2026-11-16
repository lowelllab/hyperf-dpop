#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

mode=${1:-workspace}
case "$mode" in
    workspace) ;;
    candidate)
        if (( $# > 2 )); then
            echo "Expected candidate [both|default|lowest]" >&2
            exit 2
        fi
        case ${2:-both} in
            both|default|lowest) ;;
            *) echo "Expected candidate [both|default|lowest]" >&2; exit 2 ;;
        esac ;;
    published)
        if [[ $# != 5 ]]; then
            echo "Usage: $0 published CORE_VERSION ADAPTER_VERSION CORE_COMMIT ADAPTER_COMMIT" >&2
            exit 2
        fi ;;
    *)
        echo "Expected workspace, candidate, or published" >&2
        exit 2 ;;
esac

project="dpop-compat-$$-$RANDOM"
compose=(docker compose -p "$project" -f compose.yaml -f compose.gateway.yaml)
cleanup() {
    "${compose[@]}" down --volumes --remove-orphans
}
trap cleanup EXIT
run() {
    "${compose[@]}" run --pull never --rm --no-deps -T test "$@"
}
service_address() {
    docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1"
}

gateway() {
    local mysql redis service address driver mtls
    mysql=$("${compose[@]}" ps -q mysql)
    redis=$("${compose[@]}" ps -q redis)
    export DPOP_GATEWAY_MYSQL_HOST
    export DPOP_GATEWAY_REDIS_HOST
    DPOP_GATEWAY_MYSQL_HOST=$(service_address "$mysql")
    DPOP_GATEWAY_REDIS_HOST=$(service_address "$redis")
    for driver in mysql redis; do
        export DPOP_TEST_REPLAY_DRIVER="$driver"
        for mtls in false true; do
            export DPOP_MTLS_ENABLED="$mtls"
            echo "Gateway with PHP ${DPOP_EXPECTED_PHP:-8.5.12}, replay=$driver, mTLS=$mtls"
            if ! "${compose[@]}" up -d --wait --no-deps --force-recreate --pull never gateway-app ||
               ! "${compose[@]}" up -d --wait --no-deps --force-recreate gateway; then
                "${compose[@]}" logs --tail 25 gateway-app gateway
                return 1
            fi
            "${compose[@]}" exec -T gateway-app php tests/gateway-e2e.php client
            if [[ $mtls == false ]]; then
                "${compose[@]}" run --rm --no-deps -T gateway-wire
                "${compose[@]}" exec -T gateway-app php tests/gateway-e2e.php recovery-prepare
                service=$("${compose[@]}" ps -q "$driver")
                address=$(service_address "$service")
                docker network disconnect "${project}_default" "$service"
                "${compose[@]}" exec -T gateway-app php tests/gateway-e2e.php recovery-outage
                docker network connect --alias "$driver" "${project}_default" "$service"
                if [[ $(service_address "$service") != "$address" ]]; then
                    echo "Unexpected database address after restoring the test network" >&2
                    return 1
                fi
                "${compose[@]}" exec -T gateway-app php tests/gateway-e2e.php recovery-restored
            fi
            "${compose[@]}" exec -T gateway-app +                cp build/gateway/result.json "build/gateway/result-$driver-$mtls.json"
            "${compose[@]}" stop gateway gateway-app
        done
    done
}

"${compose[@]}" up -d --wait mysql redis
export DPOP_TEST_REPLAY_DRIVER=mysql DPOP_MTLS_ENABLED=false

if [[ $mode == workspace ]]; then
    run composer install --no-interaction --no-progress --prefer-dist
    run composer check-platform-reqs
    run php tools/runtime.php
    run php -n -r '
        require "packages/hyperf-dpop/src/RuntimeRequirements.php";
        try {
            Acme\HyperfDpop\RuntimeRequirements::assertSupported();
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), "Swoole")) {
                echo "Startup rejected missing Swoole\n";
                exit(0);
            }
        }
        exit(1);
    '
    run composer validate-packages
    run php tools/consumer-smoke.php
    run composer check
    run php tests/http-e2e.php
    run php tests/tls-e2e.php
    run php examples/hyperf/bin/hyperf.php dpop:doctor
    run composer audit --locked
    run php tools/verify-docs.php
    gateway
else
    resolutions=(default)
    if [[ $mode == candidate ]]; then
        if [[ ${2:-both} == both ]]; then
            resolutions+=(lowest)
        else
            resolutions=("$2")
        fi
    fi
    shift
    if [[ $mode == candidate && $# == 1 ]]; then
        shift
    fi
    for resolution in "${resolutions[@]}"; do
        run php tools/prepare-packages.php
        consumer=$(run php tools/verify-package-exports.php "$mode" "$resolution" "$@")
        echo "Verified $mode consumer with $resolution dependencies at $consumer"
        (
            export DPOP_WORKSPACE="$consumer"
            export DPOP_TEST_REPLAY_DRIVER=mysql DPOP_MTLS_ENABLED=false
            run composer check-platform-reqs --no-dev
            run php tests/http-e2e.php
            run php tests/tls-e2e.php
            run php examples/hyperf/bin/hyperf.php dpop:doctor
            gateway
        )
    done
fi
