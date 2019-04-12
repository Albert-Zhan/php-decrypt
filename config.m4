dnl $Id$
dnl config.m4 for extension decrypt

dnl Comments in this file start with the string 'dnl'.
dnl Remove where necessary. This file will not work
dnl without editing.

dnl If your extension references something external, use with:

dnl PHP_ARG_WITH(decrypt, for decrypt support,
dnl Make sure that the comment is aligned:
dnl [  --with-decrypt             Include decrypt support])

dnl Otherwise use enable:

PHP_ARG_ENABLE(decrypt, whether to enable decrypt support,
Make sure that the comment is aligned:
[  --enable-decrypt           Enable decrypt support])

if test "$PHP_DECRYPT" != "no"; then
  dnl Write more examples of tests here...

  dnl # --with-decrypt -> check with-path
  dnl SEARCH_PATH="/usr/local /usr"     # you might want to change this
  dnl SEARCH_FOR="/include/decrypt.h"  # you most likely want to change this
  dnl if test -r $PHP_DECRYPT/$SEARCH_FOR; then # path given as parameter
  dnl   DECRYPT_DIR=$PHP_DECRYPT
  dnl else # search default path list
  dnl   AC_MSG_CHECKING([for decrypt files in default path])
  dnl   for i in $SEARCH_PATH ; do
  dnl     if test -r $i/$SEARCH_FOR; then
  dnl       DECRYPT_DIR=$i
  dnl       AC_MSG_RESULT(found in $i)
  dnl     fi
  dnl   done
  dnl fi
  dnl
  dnl if test -z "$DECRYPT_DIR"; then
  dnl   AC_MSG_RESULT([not found])
  dnl   AC_MSG_ERROR([Please reinstall the decrypt distribution])
  dnl fi

  dnl # --with-decrypt -> add include path
  dnl PHP_ADD_INCLUDE($DECRYPT_DIR/include)

  dnl # --with-decrypt -> check for lib and symbol presence
  dnl LIBNAME=decrypt # you may want to change this
  dnl LIBSYMBOL=decrypt # you most likely want to change this

  dnl PHP_CHECK_LIBRARY($LIBNAME,$LIBSYMBOL,
  dnl [
  dnl   PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $DECRYPT_DIR/$PHP_LIBDIR, DECRYPT_SHARED_LIBADD)
  dnl   AC_DEFINE(HAVE_DECRYPTLIB,1,[ ])
  dnl ],[
  dnl   AC_MSG_ERROR([wrong decrypt lib version or lib not found])
  dnl ],[
  dnl   -L$DECRYPT_DIR/$PHP_LIBDIR -lm
  dnl ])
  dnl
  dnl PHP_SUBST(DECRYPT_SHARED_LIBADD)

  PHP_NEW_EXTENSION(decrypt,decrypt.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
fi
