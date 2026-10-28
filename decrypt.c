/**
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2017 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Albert Zhan  www.5lazy.cn   github.com/2654709623/php-decrypt               |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "main/fopen_wrappers.h"
#include "ext/standard/info.h"
#include "php_decrypt.h"

#include "capture.h"

ZEND_DECLARE_MODULE_GLOBALS(decrypt)

static zend_op_array *(*old_compile_file)(zend_file_handle *, int) = NULL;

#if PHP_VERSION_ID >= 80200
static zend_op_array *(*old_compile_string)(zend_string *, const char *, zend_compile_position) = NULL;
#elif PHP_VERSION_ID >= 80000
static zend_op_array *(*old_compile_string)(zend_string *, const char *) = NULL;
#else
static zend_op_array *(*old_compile_string)(zval *, char *) = NULL;
#endif

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("decrypt.switch", "disable", PHP_INI_SYSTEM, OnUpdateString, global_switch, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_ENTRY("decrypt.save_path", "", PHP_INI_SYSTEM, OnUpdateString, save_path, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_ENTRY("decrypt.project_root", "", PHP_INI_SYSTEM, OnUpdateString, project_root, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_BOOLEAN("decrypt.history", "1", PHP_INI_SYSTEM, OnUpdateBool, history, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_BOOLEAN("decrypt.index", "1", PHP_INI_SYSTEM, OnUpdateBool, index, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_BOOLEAN("decrypt.sync", "0", PHP_INI_SYSTEM, OnUpdateBool, sync, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_BOOLEAN("decrypt.capture_eval", "1", PHP_INI_SYSTEM, OnUpdateBool, capture_eval, zend_decrypt_globals, decrypt_globals)
PHP_INI_END()

static zend_op_array *decrypt_compile_file(zend_file_handle *file_handle, int type)
{
    char *buf;
    size_t size;
    const char *filename;
    if (decrypt_capture_init() == SUCCESS && zend_stream_fixup(file_handle, &buf, &size) == SUCCESS) {
        if (file_handle->opened_path) {
            filename = ZSTR_VAL(file_handle->opened_path);
        } else {
#if PHP_VERSION_ID >= 80100
            filename = file_handle->filename ? ZSTR_VAL(file_handle->filename) : NULL;
#else
            filename = file_handle->filename;
#endif
        }
        decrypt_capture_source(filename, buf, size, 0);
    }
    return old_compile_file(file_handle, type);
}

#if PHP_VERSION_ID >= 80200
static zend_op_array *decrypt_compile_string(zend_string *source, const char *filename, zend_compile_position position)
{
    decrypt_capture_source(filename, ZSTR_VAL(source), ZSTR_LEN(source), 1);
    return old_compile_string(source, filename, position);
}
#elif PHP_VERSION_ID >= 80000
static zend_op_array *decrypt_compile_string(zend_string *source, const char *filename)
{
    decrypt_capture_source(filename, ZSTR_VAL(source), ZSTR_LEN(source), 1);
    return old_compile_string(source, filename);
}
#else
static zend_op_array *decrypt_compile_string(zval *source, char *filename)
{
    if (Z_TYPE_P(source) == IS_STRING) {
        decrypt_capture_source(filename, Z_STRVAL_P(source), Z_STRLEN_P(source), 1);
    }
    return old_compile_string(source, filename);
}
#endif

/* {{{ PHP_MINIT_FUNCTION
 */
static PHP_GINIT_FUNCTION(decrypt)
{
#if defined(COMPILE_DL_DECRYPT) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    decrypt_globals->global_switch = NULL;
    decrypt_globals->save_path = NULL;
    decrypt_globals->project_root = NULL;
    decrypt_globals->input_root = NULL;
    decrypt_globals->output_root = NULL;
    decrypt_globals->busy = decrypt_globals->ready = decrypt_globals->config_failed = 0;
}

PHP_MINIT_FUNCTION(decrypt)
{
    REGISTER_INI_ENTRIES();

    if(DCG(global_switch) != NULL && !strcmp(DCG(global_switch),"enable"))
    {
        old_compile_file = zend_compile_file;
        zend_compile_file = decrypt_compile_file;
        if (DCG(capture_eval)) {
            old_compile_string = zend_compile_string;
            zend_compile_string = decrypt_compile_string;
        }
    }
    return SUCCESS;
}
/* }}} */

/* {{{ PHP_MSHUTDOWN_FUNCTION
 */
PHP_MSHUTDOWN_FUNCTION(decrypt)
{
    if(old_compile_file != NULL && zend_compile_file == decrypt_compile_file)
    {
        zend_compile_file = old_compile_file;
    }
    if (old_compile_string != NULL && zend_compile_string == decrypt_compile_string) {
        zend_compile_string = old_compile_string;
    }
    old_compile_file = NULL;
    old_compile_string = NULL;
    UNREGISTER_INI_ENTRIES();

    return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
 */
PHP_RINIT_FUNCTION(decrypt)
{
#if defined(COMPILE_DL_DECRYPT) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    return SUCCESS;
}
/* }}} */

/* {{{ PHP_RSHUTDOWN_FUNCTION
 */
PHP_RSHUTDOWN_FUNCTION(decrypt)
{
    decrypt_capture_shutdown();
    return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
 */
PHP_MINFO_FUNCTION(decrypt)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "Decrypt Support", "enabled");
    php_info_print_table_row(2, "Version", PHP_DECRYPT_VERSION);
    php_info_print_table_row(2, "Author", "Albert Zhan");
    php_info_print_table_row(2, "Email", "albertzhan666@gmail.com");
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ decrypt_functions[]
 *
 * Every user visible function must have an entry in decrypt_functions[].
 */
const zend_function_entry decrypt_functions[] = {
    PHP_FE_END	/* Must be the last line in decrypt_functions[] */
};
/* }}} */

/* {{{ decrypt_module_entry
 */
static const zend_module_dep decrypt_deps[] = {
    ZEND_MOD_REQUIRED("hash")
    ZEND_MOD_REQUIRED("json")
    ZEND_MOD_END
};

zend_module_entry decrypt_module_entry = {
    STANDARD_MODULE_HEADER_EX, NULL, decrypt_deps,
    "decrypt",
    decrypt_functions,
    PHP_MINIT(decrypt),
    PHP_MSHUTDOWN(decrypt),
    PHP_RINIT(decrypt),
    PHP_RSHUTDOWN(decrypt),
    PHP_MINFO(decrypt),
    PHP_DECRYPT_VERSION,
    PHP_MODULE_GLOBALS(decrypt),
    PHP_GINIT(decrypt),
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};
/* }}} */

#ifdef COMPILE_DL_DECRYPT
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(decrypt)
#endif
