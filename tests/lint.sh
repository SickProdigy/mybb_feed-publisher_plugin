#!/usr/bin/env sh
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
find "$repo_dir/Upload" "$repo_dir/tests" -type f -name '*.php' -exec php -l {} \;
git -C "$repo_dir" diff --check
php "$repo_dir/tests/run.php"
