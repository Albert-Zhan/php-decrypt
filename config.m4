PHP_ARG_ENABLE(decrypt, whether to enable decrypt support,
[  --enable-decrypt           Enable decrypt support], no)

if test "$PHP_DECRYPT" != "no"; then
  PHP_NEW_EXTENSION(decrypt, decrypt.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
fi
