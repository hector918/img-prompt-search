#!/usr/bin/env bash
# Start (or restart) the wp-img-prompt-search service.
# Config lives outside the code dir at ~/wp-img-prompt-search/.env so that
# updating the code never overwrites your settings.
set -euo pipefail

ENV_DIR="${ENV_DIR:-$HOME/wp-img-prompt-search}"
ENV_FILE="$ENV_DIR/.env"

# --- Seed a template .env on first run, then ask the user to fill it in ---
if [ ! -f "$ENV_FILE" ]; then
  echo "No config found at: $ENV_FILE"
  echo "Creating a template for you to fill in..."
  mkdir -p "$ENV_DIR"
  cat > "$ENV_FILE" <<'ENV'
# ===== Embedder (llama.cpp, OpenAI-compatible) =====
EMBEDDER_BASE=http://CHANGE_ME_EMBED_HOST:8081
EMBEDDER_MODEL=
EMBEDDER_KEY=
EMBED_DIM=1024

# ===== Reranker (llama.cpp) =====
RERANKER_BASE=http://CHANGE_ME_RERANK_HOST:8082
RERANKER_MODEL=
RERANKER_KEY=
RERANK_CANDIDATES=100

# ===== PostgreSQL (pgvector) =====
PG_DSN=postgresql://CHANGE_ME_USER:CHANGE_ME_PASSWORD@CHANGE_ME_HOST:5432/CHANGE_ME_DB

# ===== Auth: empty = open (intranet). Set a value to require
#       Authorization: Bearer <API_KEY> on all endpoints except /health =====
API_KEY=

# ===== HTTP =====
HTTP_TIMEOUT=60

# Endpoint paths (override if your llama.cpp uses /rerank instead of /v1/rerank)
EMBED_PATH=/v1/embeddings
RERANK_PATH=/v1/rerank
ENV
  echo
  echo "Template written to: $ENV_FILE"
  echo "Please edit it (replace every CHANGE_ME_*), then run this script again:"
  echo "    nano $ENV_FILE"
  echo "    ./run.sh"
  exit 1
fi

# --- Sanity: warn if placeholders are still present ---
if grep -q "CHANGE_ME_" "$ENV_FILE"; then
  echo "WARNING: $ENV_FILE still contains CHANGE_ME_ placeholders."
  echo "Edit it before the service can work correctly."
  echo
fi

echo "Using config: $ENV_FILE"
docker compose --env-file "$ENV_FILE" up -d --build
echo "Started: wp-img-prompt-search (port 8090)"
