PHP_ARG_ENABLE(decrypt, whether to enable decrypt support,
[  --enable-decrypt           Enable decrypt support], no)

if test "$PHP_DECRYPT" != "no"; then
  PHP_NEW_EXTENSION(decrypt, decrypt.c capture.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_ADD_EXTENSION_DEP(decrypt, hash)
  PHP_ADD_EXTENSION_DEP(decrypt, json)
fi
