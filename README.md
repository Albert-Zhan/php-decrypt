PHP Decrypt
=======
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

**PHP Decrypt 是一个在 PHP 文件编译入口捕获源码的扩展。它保存传入 `zend_compile_file` 的内容，能否捕获解密后的源码取决于加密扩展的实现和加载顺序。**

当前没有拦截 `zend_compile_string`，因此不会直接捕获 `eval()` 动态生成的源码，也不支持还原 opcode 或混淆代码。

## 特点

- 与 OPcache、Xdebug 等扩展一起使用时需要验证加载顺序；OPcache 缓存命中时可能跳过捕获
- 兼容 Linux、OSX、Windows 等系统
- 兼容 Apache、Nginx + php-fpm、命令行等运行模式

## 安装

### 环境要求

- Linux,OSX,Windows
- PHP7.0.0以上(暂不支持php5版本)

本次修复已在 Linux PHP 8.3 NTS 环境编译并通过回归测试；PHP 7、其他 PHP 8 版本、Windows 和 ZTS 环境仍需分别验证。

> ⚠ 安装前请把当前PHP环境的php-config添加到环境变量中。

### 在Linux,OSX上编译

```shell
git clone https://github.com/Albert-Zhan/php-decrypt.git
cd php-decrypt
phpize
./configure --enable-decrypt
make
sudo make install
```

### 在Windows上编译

可以自行参考本篇文章：https://learnku.com/articles/22323

### TODO

1.目前所有解密代码只能保存在一个文件中以文件名区分  

2.暂时还不能解密一些goto加密和混淆加密

## 使用

> 上面我们已经编译好PHPDecrypt扩展了，PHPDecrypt安装好默认是关闭自动解密功能的，需要我们在php.ini文件中来配置使用它。

### 1.捕获文件编译入口的源码

在你的php.ini文件中加入以下配置项:

```shell
extension=你的扩展路径/decrypt.so
[decrypt]
;用来配置解密功能开关,默认为disable
decrypt.switch=enable
;解密后的php源码存放目录
decrypt.save_path=/www/decrypt
```

### 2.扩展方式的加密源码

由于PHP扩展加载顺序是栈加载的，需要在加密扩展前先加载解密扩展，php.ini配置项如下:

```shell
extension=你的扩展路径/decrypt.so
[decrypt]
;用来配置解密功能开关,默认为disable
decrypt.switch=enable
;解密后的php源码存放目录
decrypt.save_path=/www/decrypt
;假设这是你的加密扩展,你需要在它之前先加载解密扩展
;extension=你的加密扩展路径/encryption.so
```
保存 php.ini 文件并重启 php-fpm，然后访问待分析的源码文件，捕获内容会追加到 `/www/decrypt/decrypt.code`，每条记录包含文件名、换行、原始源码字节和换行。

`decrypt.save_path` 必须是已存在且 PHP 进程可写的本地目录，并受 `open_basedir` 限制。建议将目录设在 Web 根目录以外，限制目录访问权限；捕获文件可能包含源码中的凭据。目录未配置或文件无法写入时会产生警告，PHP 文件仍会继续编译执行。多个进程同时写入同一文件时，记录可能交错。

如果不需要使用解密功能的话可以把php.ini文件中的decrypt.switch=enable设置为decrypt.switch=disable，重启php-fpm。

## 回归测试

编译后执行 `make test TESTS=tests`。测试覆盖默认关闭、显式关闭、多次 include、源码字节完整性、空文件、错误输出路径和 `open_basedir`。

## License

Apache License Version 2.0 see http://www.apache.org/licenses/LICENSE-2.0.html
