#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PORT="${LPG_SMOKE_PORT:-55472}"
TIMEOUT_SECONDS="${LPG_SMOKE_TIMEOUT_SECONDS:-120}"
KEEP_APP="${LPG_SMOKE_KEEP_APP:-0}"
APP_DIR="${LPG_SMOKE_APP_DIR:-$(mktemp -d /tmp/lpg-smoke-XXXXXX)}"

LOG_FILE="${APP_DIR}/lpg-smoke.log"
FAKEBIN_DIR="${APP_DIR}/fakebin"
FAKEBIN_MARKER="${APP_DIR}/fakebin.called"

LPG_PID=""

cleanup() {
  if [[ -n "${LPG_PID}" ]] && kill -0 "${LPG_PID}" >/dev/null 2>&1; then
    kill -INT "${LPG_PID}" >/dev/null 2>&1 || true
    wait "${LPG_PID}" || true
  fi

  if [[ "${KEEP_APP}" == "1" ]]; then
    echo "Keeping app dir: ${APP_DIR}"
  else
    rm -rf "${APP_DIR}"
  fi
}
trap cleanup EXIT INT TERM

echo "Repo root: ${REPO_ROOT}"
echo "Temp app dir: ${APP_DIR}"
echo "Port: ${PORT}"

composer create-project laravel/laravel "${APP_DIR}" --no-interaction

cd "${APP_DIR}"

composer config repositories.lpg path "${REPO_ROOT}"
composer require --dev allfuel/lpg:@dev --no-interaction

mkdir -p "${FAKEBIN_DIR}"
cat > "${FAKEBIN_DIR}/pg_ctl" <<'EOF'
#!/usr/bin/env bash
echo "pg_ctl_called" >> "${MARKER:?missing MARKER}"
exit 0
EOF
cat > "${FAKEBIN_DIR}/initdb" <<'EOF'
#!/usr/bin/env bash
echo "initdb_called" >> "${MARKER:?missing MARKER}"
exit 0
EOF
chmod +x "${FAKEBIN_DIR}/pg_ctl" "${FAKEBIN_DIR}/initdb"

echo "Starting php artisan lpg..."
(
  MARKER="${FAKEBIN_MARKER}" PATH="${FAKEBIN_DIR}:${PATH}" php artisan lpg --port="${PORT}"
) > "${LOG_FILE}" 2>&1 &
LPG_PID="$!"

DEADLINE=$((SECONDS + TIMEOUT_SECONDS))
READY=0
while (( SECONDS < DEADLINE )); do
  if ! kill -0 "${LPG_PID}" >/dev/null 2>&1; then
    echo "lpg process exited early. Log tail:"
    tail -n 120 "${LOG_FILE}" || true
    exit 1
  fi

  if [[ -x "storage/pg/bin/pg_isready" ]] && storage/pg/bin/pg_isready -h 127.0.0.1 -p "${PORT}" >/dev/null 2>&1; then
    READY=1
    break
  fi

  sleep 1
done

if [[ "${READY}" != "1" ]]; then
  echo "Timed out waiting for Postgres readiness. Log tail:"
  tail -n 120 "${LOG_FILE}" || true
  exit 1
fi

[[ -x "storage/pg/bin/pg_ctl" ]]
[[ -x "storage/pg/bin/initdb" ]]
[[ -f "storage/pg/.embedded-version" ]]
[[ -f "storage/pgdata/PG_VERSION" ]]

kill -INT "${LPG_PID}" >/dev/null 2>&1 || true
wait "${LPG_PID}" || true
LPG_PID=""

if [[ -f "${FAKEBIN_MARKER}" ]]; then
  echo "FAIL: PATH pg_ctl/initdb were used unexpectedly."
  cat "${FAKEBIN_MARKER}"
  exit 1
fi

echo "PASS: embedded download/start/stop smoke test succeeded."
if [[ "${KEEP_APP}" == "1" ]]; then
  echo "Log file: ${LOG_FILE}"
else
  echo "Set LPG_SMOKE_KEEP_APP=1 to keep app files and logs."
fi
