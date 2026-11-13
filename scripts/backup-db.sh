#!/usr/bin/env bash
set -euo pipefail

umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target_dir="/opt/backups/aicenty-butler-scheduled"
container_dump="/tmp/aicenty-butler-${stamp}.dump"

install -d -m 700 "$target_dir"
trap 'docker exec aicenty-butler-postgres rm -f "$container_dump" >/dev/null 2>&1 || true' EXIT

docker exec aicenty-butler-postgres pg_dump \
  -U aicenty_user -d aicenty_butler_db -Fc -f "$container_dump"
docker exec aicenty-butler-postgres pg_restore --list "$container_dump" >/dev/null
docker cp "aicenty-butler-postgres:${container_dump}" "$target_dir/${stamp}.dump"
chmod 600 "$target_dir/${stamp}.dump"

tar -C /var/lib/docker/volumes/goclaw-aicenty_zalo_sessions_vault/_data \
  -czf "$target_dir/${stamp}-zalo-sessions.tar.gz" .
tar -C /var/lib/docker/volumes/goclaw-aicenty_aicenty_media_vault/_data \
  -czf "$target_dir/${stamp}-media.tar.gz" .
tar -C /var/lib/docker/volumes/goclaw-aicenty_aicenty_runtime_data/_data \
  -czf "$target_dir/${stamp}-runtime-data.tar.gz" .
tar -C /var/lib/docker/volumes/goclaw-aicenty_aicenty_workspace/_data \
  -czf "$target_dir/${stamp}-workspace.tar.gz" .
sha256sum "$target_dir/${stamp}.dump" \
  "$target_dir/${stamp}-zalo-sessions.tar.gz" \
  "$target_dir/${stamp}-media.tar.gz" \
  "$target_dir/${stamp}-runtime-data.tar.gz" \
  "$target_dir/${stamp}-workspace.tar.gz" > "$target_dir/${stamp}.sha256"
chmod 600 "$target_dir/${stamp}.sha256"
sha256sum -c "$target_dir/${stamp}.sha256"

find "$target_dir" -maxdepth 1 -type f -mtime +14 \
  \( -name '*.dump' -o -name '*-zalo-sessions.tar.gz' -o -name '*-media.tar.gz' \
     -o -name '*-runtime-data.tar.gz' -o -name '*-workspace.tar.gz' -o -name '*.sha256' \) \
  -delete

printf 'Butler backup completed: %s\n' "$stamp"
