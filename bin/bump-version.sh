#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
VERSION=$(node -p "require('${ROOT_DIR}/package.json').version")

PHP_FILE="$ROOT_DIR/party-plan-builder/party-plan-builder.php"
README="$ROOT_DIR/README.md"

# Update plugin header version
sed -i -E "s/(\* Version: )[0-9]+\.[0-9]+\.[0-9]+/\1$VERSION/" "$PHP_FILE"
# Update enqueued script version
sed -i -E "s/(wp_register_script\('ppb-script', false, \['jquery'\], ')[0-9]+\.[0-9]+\.[0-9]+('\))/\1$VERSION\2/" "$PHP_FILE"

# Update README references
sed -i -E "s/(party-plan-builder v)[0-9]+\.[0-9]+\.[0-9]+/\1$VERSION/" "$README"
sed -i -E "s/(party-plan-builder-)[0-9]+\.[0-9]+\.[0-9]+(\.zip)/\1$VERSION\2/" "$README"
