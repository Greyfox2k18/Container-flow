#!/usr/bin/env bash
# Browser test for the Report Builder editor (dev machines only — never on the server).
# Starts PHP's built-in server with a fake UserSpice environment + SQLite,
# drives the editor in headless Chromium with Playwright, then stops the server.
#   bash usersc/plugins/report_builder/tests/e2e/run_e2e.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../../../.." && pwd)"
export RB_E2E_DIR="${RB_E2E_DIR:-$(mktemp -d)}"
export RB_E2E_PORT="${RB_E2E_PORT:-8765}"
mkdir -p "$RB_E2E_DIR"
php -S "127.0.0.1:$RB_E2E_PORT" -t "$ROOT" "$HERE/router.php" > "$RB_E2E_DIR/server.log" 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 1
node "$HERE/editor.e2e.js"
echo "Screenshots: $RB_E2E_DIR"
