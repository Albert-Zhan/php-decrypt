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

#ifndef PHP_DECRYPT_H
#define PHP_DECRYPT_H

extern zend_module_entry decrypt_module_entry;
#define phpext_decrypt_ptr &decrypt_module_entry

#define PHP_DECRYPT_VERSION "0.1.1" /* Replace with version number for your extension */

#ifdef PHP_WIN32
#   define PHP_DECRYPT_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#   define PHP_DECRYPT_API __attribute__ ((visibility("default")))
#else
#   define PHP_DECRYPT_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

zend_op_array *(*old_compile_file)(zend_file_handle *,int);

zend_op_array *decrypt_compile_file(zend_file_handle *, int);

PHP_MINIT_FUNCTION(decrypt);
PHP_MSHUTDOWN_FUNCTION(decrypt);
PHP_MINFO_FUNCTION(decrypt);

ZEND_BEGIN_MODULE_GLOBALS(decrypt)
    char *global_switch;
    char *global_new;
ZEND_END_MODULE_GLOBALS(decrypt)

ZEND_EXTERN_MODULE_GLOBALS(decrypt)
#define DCG(v) ZEND_MODULE_GLOBALS_ACCESSOR(decrypt, v)

#if defined(ZTS) && defined(COMPILE_DL_DECRYPT)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif	/* PHP_DECRYPT_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
