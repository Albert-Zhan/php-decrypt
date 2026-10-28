#!/usr/bin/env bash
# PHP_BIN=/path/php DECRYPT_EXTENSION=/path/decrypt.so examples/run-demo.sh /tmp/capture-demo
set -euo pipefail
examples_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
php_bin=${PHP_BIN:-php}
extension=${DECRYPT_EXTENSION:-"$examples_dir/../modules/decrypt.so"}
if [[ $# != 1 ]]; then echo 'Usage: examples/run-demo.sh NEW_OUTPUT_DIRECTORY' >&2; exit 2; fi
if [[ -e "$1" ]]; then echo 'Use a new output directory.' >&2; exit 2; fi
mkdir -p "$1"
output=$(cd "$1" && pwd)
mkdir -m 700 "$output/captures"
"$php_bin" -n -d "extension=$extension" -d decrypt.switch=enable \
    -d "decrypt.project_root=$examples_dir/demo/project" \
    -d "decrypt.save_path=$output/captures" -f "$examples_dir/demo/project/index.php"
"$php_bin" -n "$examples_dir/../tools/verify-captures.php" "$output/captures"
"$php_bin" -n "$examples_dir/demo/verify-and-restore.php" "$output/captures" "$output/restored"
"$php_bin" -n "$output/restored/index.php"
printf 'Captures: %s\nRestored demo: %s\n' "$output/captures" "$output/restored"
