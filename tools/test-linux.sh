#!/usr/bin/env bash
# Usage: PHP_BIN=/path/php PHPIZE=/path/phpize PHP_CONFIG=/path/php-config tools/test-linux.sh /tmp/build-dir
set -euo pipefail
repo_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
php_bin=${PHP_BIN:-php}
phpize_bin=${PHPIZE:-phpize}
php_config_bin=${PHP_CONFIG:-php-config}
build_dir=${1:-$(mktemp -d /tmp/php-decrypt-test.XXXXXX)}
mkdir -p "$build_dir"
build_dir=$(cd "$build_dir" && pwd)
if [[ "$build_dir" == "$repo_dir" || -e "$build_dir/configure" ]]; then
    echo 'Use an empty build directory outside the repository.' >&2
    exit 1
fi
cp "$repo_dir"/*.c "$repo_dir"/*.h "$repo_dir"/config.m4 "$build_dir/"
cp -R "$repo_dir/tests" "$build_dir/tests"
cp -R "$repo_dir/tools" "$build_dir/tools"
cp -R "$repo_dir/examples" "$build_dir/examples"
cd "$build_dir"
"$phpize_bin"
./configure --enable-decrypt --with-php-config="$php_config_bin"
make -j"${JOBS:-2}" CFLAGS='-g -O2 -Wall -Wextra -Werror -Wno-unused-parameter'
export TEST_PHP_EXECUTABLE="$php_bin"
export DECRYPT_EXTENSION="$build_dir/modules/decrypt.so"
export NO_INTERACTION=1 REPORT_EXIT_STATUS=1
make test TESTS=tests
printf 'Extension: %s\n' "$DECRYPT_EXTENSION"
