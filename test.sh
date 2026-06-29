#!/usr/bin/env bash
# Smoke + functional tests for wp-img-prompt-search.
# Reads API_KEY and host/port from the env file, then exercises every endpoint
# and cleans up the test rows afterwards.
#
# Usage:
#   ./test.sh                 # uses ~/wp-img-prompt-search/.env and http://localhost:8090
#   BASE=http://host:8090 ./test.sh
set -o pipefail

ENV_FILE="${ENV_FILE:-$HOME/wp-img-prompt-search/.env}"
BASE="${BASE:-http://localhost:8090}"

# Pull API_KEY from env file if present (don't fail if missing)
API_KEY=""
if [ -f "$ENV_FILE" ]; then
  API_KEY="$(grep -E '^API_KEY=' "$ENV_FILE" | head -1 | cut -d= -f2- || true)"
fi
AUTH=()
if [ -n "$API_KEY" ]; then
  AUTH=(-H "Authorization: Bearer $API_KEY")
fi

PASS=0
FAIL=0

# req METHOD PATH JSON  -> prints status + body, returns body in $BODY
req() {
  local method="$1" path="$2" data="${3:-}"
  local args=(-s -w $'\n%{http_code}' -X "$method" "$BASE$path" "${AUTH[@]}")
  if [ -n "$data" ]; then
    args+=(-H "Content-Type: application/json" -d "$data")
  fi
  local out; out="$(curl "${args[@]}")"
  CODE="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}

check() {
  local label="$1" expect="$2"
  if [ "$CODE" = "$expect" ]; then
    echo "  [PASS] $label (HTTP $CODE)"
    PASS=$((PASS+1))
  else
    echo "  [FAIL] $label (HTTP $CODE, expected $expect)"
    echo "         body: $BODY"
    FAIL=$((FAIL+1))
  fi
}

echo "=== wp-img-prompt-search test ==="
echo "Target: $BASE"
[ -n "$API_KEY" ] && echo "Auth: Bearer (key loaded)" || echo "Auth: none (open)"
echo

echo "[1] Health"
req GET /health
check "GET /health" 200
echo "    -> $BODY"

echo "[2] Index single (id=999001)"
req POST /index '{"id":999001,"caption":"a red sports car on a mountain road","prompt":"cinematic photo of a red sports car, sunset, winding mountain road","tags":["car","sunset","test"]}'
check "POST /index" 200

echo "[3] Index batch (id=999002,999003)"
req POST /index/batch '{"items":[{"id":999002,"caption":"a calm beach at dawn","prompt":"serene beach, soft morning light, gentle waves","tags":["beach","sunrise","test"]},{"id":999003,"caption":"a portrait of a person smiling","prompt":"studio portrait, soft lighting, smiling person","tags":["portrait","people","test"]}]}'
check "POST /index/batch" 200

echo "[4] Search (semantic) q='sunset car'"
req POST /search '{"query":"sunset car","limit":5}'
check "POST /search" 200
echo "    -> $BODY"

echo "[5] Search with tags (+test +beach)"
req POST /search '{"query":"morning sea","limit":5,"tags":["+test","+beach"]}'
check "POST /search tags include" 200
echo "    -> ids: $(echo "$BODY" | grep -o '"ids":\[[^]]*\]')"

echo "[6] Search with tag exclude (-people)"
req POST /search '{"query":"a photo","limit":5,"tags":["+test","-people"]}'
check "POST /search tags exclude" 200
echo "    -> ids: $(echo "$BODY" | grep -o '"ids":\[[^]]*\]')"

echo "[7] Search with time window (after now -> expect empty / recent only)"
req POST /search '{"query":"car","limit":5,"after":"2099-01-01"}'
check "POST /search time filter" 200
echo "    -> ids: $(echo "$BODY" | grep -o '"ids":\[[^]]*\]')"

echo "[8] Cleanup (delete test rows)"
req POST /delete '{"ids":[999001,999002,999003]}'
check "POST /delete" 200
echo "    -> $BODY"

if [ -n "$API_KEY" ]; then
  echo "[9] Auth rejection (no token -> expect 401)"
  out="$(curl -s -w $'\n%{http_code}' -X POST "$BASE/search" -H "Content-Type: application/json" -d '{"query":"x"}')"
  code="${out##*$'\n'}"
  if [ "$code" = "401" ]; then echo "  [PASS] unauthorized blocked (HTTP 401)"; PASS=$((PASS+1));
  else echo "  [FAIL] expected 401, got $code"; FAIL=$((FAIL+1)); fi
fi

echo
echo "=== Result: $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ]
