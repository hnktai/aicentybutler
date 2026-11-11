#!/usr/bin/env bash
set -euo pipefail

if test "$#" -ne 2; then
  echo "usage: $0 <product-version> <output-directory>" >&2
  exit 2
fi

version="$1"
output_dir="$2"
commit_sha="$(git rev-parse HEAD)"
short_sha="${commit_sha:0:12}"
build_revision="${version}-${short_sha}"
image_reference="local/aicenty-butler:${build_revision}"

if test -n "$(git status --porcelain)"; then
  echo "refusing to build from a dirty worktree" >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi

schema_version="$(sed -n 's/^const RequiredSchemaVersion uint = \([0-9][0-9]*\)$/\1/p' internal/upgrade/version.go)"
protocol_version="$(sed -n 's/^const ProtocolVersion = \([0-9][0-9]*\)$/\1/p' pkg/protocol/frames.go)"
if test -z "$schema_version" || test -z "$protocol_version"; then
  echo "failed to resolve schema or protocol version" >&2
  exit 1
fi

mkdir -p "$output_dir"
docker build \
  --build-arg ENABLE_EMBEDUI=true \
  --build-arg ENABLE_PYTHON=true \
  --build-arg VERSION="$version" \
  --build-arg COMMIT_SHA="$commit_sha" \
  --label org.opencontainers.image.version="$version" \
  --label org.opencontainers.image.revision="$commit_sha" \
  --tag "$image_reference" .

container_id="$(docker create "$image_reference")"
trap 'docker rm -f "$container_id" >/dev/null 2>&1 || true' EXIT
docker cp "$container_id:/app/goclaw" "$output_dir/goclaw"
chmod 755 "$output_dir/goclaw"

image_id="$(docker image inspect "$image_reference" --format '{{.Id}}')"
binary_sha256="$(sha256sum "$output_dir/goclaw" | awk '{print $1}')"
build_time="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

cat > "$output_dir/manifest.json" <<EOF
{
  "product_version": "$version",
  "build_revision": "$build_revision",
  "commit_sha": "$commit_sha",
  "schema_version": $schema_version,
  "protocol_version": $protocol_version,
  "build_time_utc": "$build_time",
  "image_reference": "$image_reference",
  "image_id": "$image_id",
  "binary_sha256": "$binary_sha256"
}
EOF

printf 'candidate image: %s\nmanifest: %s\n' "$image_reference" "$output_dir/manifest.json"
