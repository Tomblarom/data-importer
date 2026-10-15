#!/usr/bin/env sh
# Smoke-test a BKB derivative importer image.
#
# Usage: scripts/check_image.sh <image>
#
# Boots the image with throwaway runtime config (fake APP_KEY, unreachable
# Firefly/bot loopback URLs, random admin password), then verifies:
#   - /health answers OK
#   - public /api/import-flows lists key "bkb" with its title
#   - unauthorized /bkb/frame redirects to /bkb/login (hash configured),
#     and answers 403 when no admin hash is configured
#   - the configured admin login page renders
#   - php -l passes for every PHP file in the overlaid trees inside the
#     container (a superset of all new/changed files; blade views are
#     covered by the login-page render check since php -l cannot parse them)
#   - php artisan route:list --path=bkb shows the BKB routes
#
# Cleans up its own containers and volumes on exit. Never prints secrets.
# Full browser setup against a real bot service is tested separately.
set -u

IMAGE="${1:-}"
if [ -z "$IMAGE" ]; then
    echo "usage: $0 <image>" >&2
    exit 2
fi

C1="bkb-smoke-nohash-$$"
C2="bkb-smoke-$$"
V_RECEIPTS="bkb-smoke-receipts-$$"
V_JOBS="bkb-smoke-jobs-$$"

cleanup() {
    docker rm -f "$C1" >/dev/null 2>&1 || true
    docker rm -f "$C2" >/dev/null 2>&1 || true
    docker volume rm "$V_RECEIPTS" >/dev/null 2>&1 || true
    docker volume rm "$V_JOBS" >/dev/null 2>&1 || true
}
trap cleanup EXIT

die() {
    echo "check_image: FAIL: $1" >&2
    exit 1
}
pass() {
    echo "check_image: ok: $1"
}

# Free loopback port, probed at bind time to avoid clashes.
free_port() {
    python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])'
}

wait_healthy() {
    container="$1"
    port="$2"
    i=0
    while [ "$i" -lt 60 ]; do
        if [ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/health" 2>/dev/null)" = "200" ]; then
            body="$(curl -s "http://127.0.0.1:${port}/health" 2>/dev/null)"
            if [ "$body" = "OK" ]; then
                return 0
            fi
        fi
        if [ -n "$(docker ps -q -f "name=^${container}\$")" ]; then
            :
        else
            docker logs "$container" 2>&1 | tail -20 >&2
            die "container $container exited while waiting for /health"
        fi
        i=$((i + 1))
        sleep 2
    done
    docker logs "$container" 2>&1 | tail -20 >&2
    die "/health never answered OK on port $port"
}

APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
FAKE_TOKEN="fake32chars0123456789abcdef01"

docker volume create "$V_RECEIPTS" >/dev/null || die "cannot create volume $V_RECEIPTS"
docker volume create "$V_JOBS" >/dev/null || die "cannot create volume $V_JOBS"

# --- Boot 1: no admin hash -> /bkb/frame must fail closed with 403. ---
P1="$(free_port)"
docker run -d --name "$C1" \
    -p "127.0.0.1:${P1}:8080" \
    -e "APP_KEY=${APP_KEY}" \
    -e FIREFLY_III_URL=http://127.0.0.1:9 \
    -e FIREFLY_III_ACCESS_TOKEN=dummy \
    -e BKB_BOT_URL=http://127.0.0.1:9 \
    -e "BKB_API_TOKEN=${FAKE_TOKEN}" \
    -v "${V_RECEIPTS}:/var/www/html/storage/import-receipts" \
    -v "${V_JOBS}:/var/www/html/storage/import-jobs" \
    "$IMAGE" >/dev/null || die "cannot boot $IMAGE without admin hash"
wait_healthy "$C1" "$P1"
pass "/health answers OK (no-hash boot)"

code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${P1}/bkb/frame")"
[ "$code" = "403" ] || die "/bkb/frame without admin hash: want 403, got $code"
pass "unauthorized /bkb/frame is forbidden when hash absent"

# Mint the admin hash inside the running container; the password travels
# over stdin (docker exec -i) and neither value is ever printed.
ADMIN_PW="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
ADMIN_HASH="$(printf '%s' "$ADMIN_PW" | docker exec -i "$C1" php -r '$pw = trim(stream_get_contents(STDIN)); echo password_hash($pw, PASSWORD_DEFAULT), PHP_EOL;')"
[ -n "$ADMIN_HASH" ] || die "could not generate BKB admin hash"
case "$ADMIN_HASH" in
    \$2y\$*|\$2a\$*|\$argon2*) pass "admin hash generated inside container" ;;
    *) die "admin hash has unexpected format" ;;
esac

docker rm -f "$C1" >/dev/null || die "cannot stop no-hash container"

# --- Boot 2: hash configured -> redirect + login page + in-container checks. ---
P2="$(free_port)"
docker run -d --name "$C2" \
    -p "127.0.0.1:${P2}:8080" \
    -e "APP_KEY=${APP_KEY}" \
    -e FIREFLY_III_URL=http://127.0.0.1:9 \
    -e FIREFLY_III_ACCESS_TOKEN=dummy \
    -e BKB_BOT_URL=http://127.0.0.1:9 \
    -e "BKB_API_TOKEN=${FAKE_TOKEN}" \
    -e "BKB_ADMIN_PASSWORD_HASH=${ADMIN_HASH}" \
    -v "${V_RECEIPTS}:/var/www/html/storage/import-receipts" \
    -v "${V_JOBS}:/var/www/html/storage/import-jobs" \
    "$IMAGE" >/dev/null || die "cannot boot $IMAGE with admin hash"
wait_healthy "$C2" "$P2"
pass "/health answers OK (hash boot)"

flows="$(curl -s "http://127.0.0.1:${P2}/api/import-flows")"
flat="$(printf '%s' "$flows" | tr -d ' \n')"
case "$flat" in
    *'"key":"bkb"'*) pass "public /api/import-flows includes key bkb" ;;
    *) die "public /api/import-flows misses key bkb: $flows" ;;
esac
case "$flat" in
    *'"title":"BKBDigitalBanking"'*) pass "public /api/import-flows includes BKB title" ;;
    *) die "public /api/import-flows misses BKB title: $flows" ;;
esac

read_out="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://127.0.0.1:${P2}/bkb/frame")"
code="${read_out%% *}"
target="${read_out#* }"
[ "$code" = "302" ] || die "unauthorized /bkb/frame: want 302, got $code"
case "$target" in
    */bkb/login) pass "unauthorized /bkb/frame redirects to /bkb/login" ;;
    *) die "unauthorized /bkb/frame redirects to unexpected target: $target" ;;
esac

login_page="$(curl -s -w '\n%{http_code}' "http://127.0.0.1:${P2}/bkb/login")"
login_code="$(printf '%s' "$login_page" | tail -1)"
login_body="$(printf '%s' "$login_page" | sed '$d')"
[ "$login_code" = "200" ] || die "configured /bkb/login: want 200, got $login_code"
case "$login_body" in
    *BKB*) pass "configured admin login page renders" ;;
    *) die "configured /bkb/login page misses BKB marker" ;;
esac

lint_out="$(docker exec "$C2" sh -c 'find /var/www/html/app /var/www/html/config /var/www/html/routes -name "*.php" -exec php -l {} + 2>&1')"
if [ -n "$(printf '%s\n' "$lint_out" | grep -iv 'no syntax errors detected' | grep -v '^$' || true)" ]; then
    printf '%s\n' "$lint_out" | grep -iv 'no syntax errors detected' | grep -v '^$' >&2
    die "php -l reported errors inside container"
fi
if [ -z "$lint_out" ]; then
    die "php -l produced no output inside container"
fi
pass "php -l clean for overlaid app/config/routes inside container"

routes="$(docker exec "$C2" php /var/www/html/artisan route:list --path=bkb 2>&1)" || die "route:list failed: $routes"
case "$routes" in
    *bkb.login*|*"bkb/frame"*) pass "php artisan route:list --path=bkb shows BKB routes" ;;
    *) die "route:list --path=bkb shows no BKB routes: $routes" ;;
esac

echo "check_image: PASS: $IMAGE"
