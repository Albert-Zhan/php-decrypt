# Shared dependency loading for isolated CLI verification (source from Bash).
php_decrypt_isolated_args() {
    local runtime_bin=$1 dependency extension_dir module_file
    PHP_DECRYPT_ISOLATED_ARGS=(-n)
    extension_dir=$("$runtime_bin" -r 'echo ini_get("extension_dir");')
    for dependency in hash json; do
        if "$runtime_bin" -n -r "exit(extension_loaded('$dependency') ? 0 : 1);"; then
            continue
        fi
        module_file="$extension_dir/$dependency.so"
        if [[ ! -f "$module_file" ]]; then
            echo "Required shared dependency is missing: $module_file" >&2
            return 1
        fi
        PHP_DECRYPT_ISOLATED_ARGS+=(-d "extension=$module_file")
    done
    "$runtime_bin" "${PHP_DECRYPT_ISOLATED_ARGS[@]}" -r \
        'if (!extension_loaded("hash") || !extension_loaded("json")) exit(1);'
}
