#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/opt/goclaw-aicenty"

cd "$PROJECT_DIR"

if test -n "$(git status --porcelain)"; then
    echo "refusing to push a dirty production checkout" >&2
    exit 1
fi

branch="$(git branch --show-current)"
if test -z "$branch"; then
    echo "refusing to push a detached HEAD" >&2
    exit 1
fi

git push origin "HEAD:${branch}"
