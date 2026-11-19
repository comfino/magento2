#!/bin/bash
set -euo pipefail

# Stamps BUILD_TS (current Unix timestamp in UTC) and VERSION into source files,
# then commits the result. Called from tag-beta.sh and tag-release.sh before
# the annotated tag is created, so the stamped state ends up in the snapshot.
#
# Usage: bin/stamp-version.sh <version-tag>
#   e.g. bin/stamp-version.sh 4.0.0-beta3
#        bin/stamp-version.sh 4.0.0

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

TAG="${1:-}"
[[ -z "$TAG" ]] && { echo "stamp-version: missing version argument" >&2; exit 1; }

DATA_PHP="$REPO_ROOT/Helper/Data.php"
VERSION_PHP="$REPO_ROOT/Model/Version.php"

TS=$(date -u +%s)

sed -i "s/^\(    public const BUILD_TS = \)[0-9]\+;/\1${TS};/" "$DATA_PHP"
sed -i "s/^\(    public const VERSION = '\)[^']*';/\1${TAG}';/" "$VERSION_PHP"

grep -q "BUILD_TS = ${TS};"   "$DATA_PHP"   || { echo "stamp-version: BUILD_TS patch failed" >&2;  exit 1; }
grep -q "VERSION = '${TAG}';" "$VERSION_PHP" || { echo "stamp-version: VERSION patch failed" >&2; exit 1; }

git -C "$REPO_ROOT" add Helper/Data.php Model/Version.php
git -C "$REPO_ROOT" commit -m "Bump version to ${TAG} [BUILD_TS=${TS}]"

echo "Stamped: VERSION='${TAG}'  BUILD_TS=${TS}"
