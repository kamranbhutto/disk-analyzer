#!/bin/sh
set -eu
# Keep build output on the temporary Linux filesystem.
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-/tmp/disk-analyzer-target}"
cd "$(dirname "$0")"
exec cargo run --release --offline
