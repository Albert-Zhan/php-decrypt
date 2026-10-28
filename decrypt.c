/**
  +----------------------------------------------------------------------+
  | PHP Version 7                                                        |
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

/* $Id$ */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "main/fopen_wrappers.h"
#include "ext/standard/info.h"
#include "php_decrypt.h"

#include "lib.h"

ZEND_DECLARE_MODULE_GLOBALS(decrypt)

static zend_op_array *(*old_compile_file)(zend_file_handle *, int) = NULL;

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("decrypt.switch","disable",PHP_INI_SYSTEM, OnUpdateString, global_switch, zend_decrypt_globals, decrypt_globals)
    STD_PHP_INI_ENTRY("decrypt.save_path","",PHP_INI_SYSTEM, OnUpdateString, global_new, zend_decrypt_globals, decrypt_globals)
PHP_INI_END()

zend_op_array *decrypt_compile_file(zend_file_handle *file_handle, int type)
{
    char *buf;
    size_t size;
    char *output_path;
    const char *filename;
    FILE *fp;
    int write_failed;

    if (DCG(global_new) == NULL || DCG(global_new)[0] == '\0') {
        php_error_docref(NULL, E_WARNING, "decrypt.save_path is not configured");
        return old_compile_file(file_handle, type);
    }

    if(zend_stream_fixup(file_handle,&buf,&size)==SUCCESS)
    {
        /* INI strings belong to Zend; never append to them in place. */
        spprintf(&output_path, 0, "%s%sdecrypt.code", DCG(global_new), DS);
        if (php_check_open_basedir(output_path)) {
            efree(output_path);
            return old_compile_file(file_handle, type);
        }
        fp = VCWD_FOPEN(output_path, "ab");
        if (fp == NULL) {
            php_error_docref(NULL, E_WARNING, "Unable to open capture file %s", output_path);
            efree(output_path);
            return old_compile_file(file_handle, type);
        }
#if PHP_VERSION_ID >= 80100
        filename = file_handle->filename ? ZSTR_VAL(file_handle->filename) : "[unknown]";
#else
        filename = file_handle->filename ? file_handle->filename : "[unknown]";
#endif
        write_failed = fprintf(fp, "%s%s", filename, BR) < 0;
        if (size > 0 && fwrite(buf, 1, size, fp) != size) {
            write_failed = 1;
        }
        if (fwrite(BR, 1, sizeof(BR) - 1, fp) != sizeof(BR) - 1) {
            write_failed = 1;
        }
        if (fclose(fp) != 0) {
            write_failed = 1;
        }
        if (write_failed) {
            php_error_docref(NULL, E_WARNING, "Unable to write capture file %s", output_path);
        }
        efree(output_path);
    }
    return old_compile_file(file_handle,type);
}

/* {{{ PHP_MINIT_FUNCTION
 */
static PHP_GINIT_FUNCTION(decrypt)
{
#if defined(COMPILE_DL_DECRYPT) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    decrypt_globals->global_switch = NULL;
    decrypt_globals->global_new = NULL;
}

PHP_MINIT_FUNCTION(decrypt)
{
    REGISTER_INI_ENTRIES();

    if(DCG(global_switch) != NULL && !strcmp(DCG(global_switch),"enable"))
    {
        old_compile_file = zend_compile_file;
        zend_compile_file = decrypt_compile_file;
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
    old_compile_file = NULL;
    UNREGISTER_INI_ENTRIES();

    return SUCCESS;
}
/* }}} */

/* Remove if there's nothing to do at request start */
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

/* Remove if there's nothing to do at request end */
/* {{{ PHP_RSHUTDOWN_FUNCTION
 */
PHP_RSHUTDOWN_FUNCTION(decrypt)
{
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
zend_module_entry decrypt_module_entry = {
    STANDARD_MODULE_HEADER,
    "decrypt",
    decrypt_functions,
    PHP_MINIT(decrypt),
    PHP_MSHUTDOWN(decrypt),
    PHP_RINIT(decrypt),
    NULL,	/* Replace with NULL if there's nothing to do at request end */
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

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
