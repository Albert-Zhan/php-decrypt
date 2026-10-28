# PHP 7 与 php-beast 集成验证

验证日期：2026-10-28。结论：在下述环境中，**先加载 decrypt，再加载 beast**，可以捕获 php-beast 解密后交给编译器的普通 PHP 文件，不需要源码中存在 `eval()`。

## 环境与范围

| 项目 | 实际版本 / 配置 |
|---|---|
| 系统 | Ubuntu 24.04，Linux x86_64，GCC 13.3.0，本地文件系统 |
| PHP | 7.4.33 NTS，CLI 与 FPM |
| php-decrypt | `v0.2.0`，发布提交 `0db6959ea25808cebc1c04a8aad3127ad4e89ff1` 的 C 核心 |
| php-beast | 上游 [liexusong/php-beast](https://github.com/liexusong/php-beast)，提交 [`4549b2a642a9b04408b4bfbb3af64131c7f1f2dd`](https://github.com/liexusong/php-beast/commit/4549b2a642a9b04408b4bfbb3af64131c7f1f2dd)，模块版本 2.7 |
| beast 构建 | 未修改上游源码，默认 tmpfile 文件处理器，未开启 beast debug 模式 |
| 其他扩展 | hash、json；隔离 INI，未加载 OPcache 或 Xdebug |

没有修改 php-decrypt 的捕获钩子、存储算法、默认配置或 php-beast 的密钥及文件头。未验证其他 PHP 7 小版本、PHP 8、ZTS、macOS、定制 beast、过期限制、机器绑定及其他文件处理器。

## 测试结果

测试使用两个普通 PHP 文件：入口 `index.php` 两次加载 `lib/message.php`，库文件返回字符串。两个文件均由 `beast_encode_file()` 加密，脚本中不使用 eval 或 Base64 PHP 包装。

| beast 算法 | ASCII 内容 | 含中文内容 | CLI / FPM |
|---|---|---|---|
| DES | 通过 | 通过 | 均通过 |
| AES | 通过 | 通过 | 均通过 |
| BASE64 | 通过 | 上游加密输出损坏 | ASCII 通过；中文不列为兼容性通过 |

每组通过的场景均检查：

1. 文件确实已变成 beast 格式，不加载 beast 时不能得到正确业务结果。
2. 仅加载 beast 时业务结果正确，再加入 decrypt 后结果仍正确。
3. 两个 `current/` 文件与 PHP `php_strip_whitespace()` 得到的加密前有效代码逐字节一致。
4. 索引恰好有两条 `kind=file` 记录，**没有 eval 记录**；镜像、历史对象及 SHA-256 全部通过检查。
5. 两个独立 CLI 进程重复执行后，内容去重且不重复追加索引。
6. 不加载 beast 和 decrypt，直接执行 `captures/current/index.php`，结果与原项目一致。
7. FPM 单 worker 连续 8 次请求复用同一 PID；四 worker 接收 16 次并发请求，实际观察到四个 PID。每组共 24 次请求，五组共 120 次，包含 beast 缓存命中后的捕获。
8. 加密后的输入文件未被修改；两种扩展加载顺序都作为对照执行。

业务结果中的字符串先转为 Base64 再做 JSON 输出，仅用于比较二进制值、避免无效 UTF-8 令 `json_encode()` 失败，不是被测脚本的加密包装方式。

## 必须注意加载顺序

本次验证有效的顺序：

```ini
extension=/absolute/path/to/decrypt.so
extension=/absolute/path/to/beast.so

[decrypt]
decrypt.switch=enable
decrypt.project_root=/absolute/path/to/encrypted-project
decrypt.save_path=/absolute/path/to/private-captures
```

两个目录必须已存在；输出目录需使用物理绝对路径、由 PHP 运行用户管理，并满足 [README 的路径要求](../README.md#配置)。CLI 命令对应为：

```bash
/path/to/php7 -d extension=/absolute/path/to/decrypt.so \
    -d extension=/absolute/path/to/beast.so \
    -d decrypt.switch=enable \
    -d decrypt.project_root=/absolute/path/to/encrypted-project \
    -d decrypt.save_path=/absolute/path/to/private-captures \
    -d opcache.enable_cli=0 \
    /absolute/path/to/encrypted-project/index.php
```

临时使用 `-d extension=...` 时，须确保 INI 尚未加载这两个模块。若使用多个扫描配置文件，必须保证 decrypt 的加载声明先被读取；CLI 可用 `php --ini` 检查文件顺序。FPM 需要单独核对配置并重新加载。

原因是后加载的 beast 保存了 decrypt 的编译钩子。运行时 beast 先解密并替换文件句柄，再调用 decrypt，最后进入 PHP 编译器。

反向加载（beast 在前，decrypt 在后）在本次所有五组对照中均失败：decrypt 先读取并缓存了密文，`current/index.php` 与加密文件相同，随后脚本输出密文字节，库文件没有执行。**进程可能仍返回 0，不能仅凭退出码判断解密成功。** 该顺序不应使用；本轮未改动钩子以兼容反向加载。

## 注释和格式为什么不同

beast 的 `encrypt_file()` 在加密前调用 `filter_code_comments()`，通过 PHP 的 `zend_strip()` 移除注释、压缩空白。因此 decrypt 能保存的是 beast 实际解密得到的代码，不能恢复加密前已经被删除的注释和排版。

本次以 `php_strip_whitespace()` 的结果作为预期字节，捕获文件不添加标签、不修改正文；将 `current/` 文件与最初带注释的源文件直接比较并不适合作为完整性标准。

## BASE64 中文问题

未加载 decrypt、仅使用 beast 的中文样例已经返回错误字符串。进一步检查加密文件中 20 字节默认文件头之后的 BASE64 载荷，发现存在 `0xff` 等非法 Base64 字节，不能正确还原加密前的代码。

上游 `base64_algo_handler.c` 的编码器使用 `char *current`，直接右移字符并索引编码表；在本次 x86_64 GCC 默认的 signed-char 环境中，UTF-8 高位字节会导致负索引。这与仅含 ASCII 的样例正常相符。本轮没有修改或修复上游编码器，也不把这一场景记为 decrypt 通过。

测试工具会单独报告 `UPSTREAM_BASE64_UNICODE_FAILURE` 并保留样例。该项不阻止其他算法的独立检查，但必须查看报告中的逐项状态，不能把工具退出 0 理解成所有算法均兼容。

## 复现

准备同一套 PHP 7 NTS 的 CLI、FPM、phpize、php-config，以及 C 编译器、make、autoconf、Bash 和 Python 3。先在独立目录构建上述固定版本 beast：

```bash
git clone https://github.com/liexusong/php-beast.git
cd php-beast
git checkout 4549b2a642a9b04408b4bfbb3af64131c7f1f2dd
/path/to/php7/bin/phpize
./configure --enable-beast --with-php-config=/path/to/php7/bin/php-config
make -j2
```

无需 `make install`，直接使用 `modules/beast.so`。随后回到 php-decrypt 仓库，用所选 PHP 构建 decrypt：

```bash
PHP_BIN=/path/to/php7/bin/php \
PHPIZE=/path/to/php7/bin/phpize \
PHP_CONFIG=/path/to/php7/bin/php-config \
tools/test-linux.sh /tmp/decrypt-beast-build
```

运行集成检查，`--output` 必须指定一个新目录，以下模块路径替换为实际绝对路径：

```bash
python3 tools/test-beast.py \
    --php /path/to/php7/bin/php \
    --beast /absolute/path/to/php-beast/modules/beast.so \
    --decrypt /tmp/decrypt-beast-build/modules/decrypt.so \
    --fpm /path/to/php7/sbin/php-fpm \
    --output /tmp/decrypt-beast-result
```

不传 `--fpm` 时仅运行 CLI 检查。工具会复用现有的 hash/json 共享依赖检测，临时 FPM 只监听随机本机端口，结束后关闭，不改动已有服务。源码、加密文件、捕获结果、反向加载结果、FPM 日志及 `report.json` 都保留在输出目录内；JSON 报告记录运行版本和两个模块的 SHA-256。

每个算法及字符集各有一个子目录，例如 `AES-unicode/`：

| 路径 | 内容 |
|---|---|
| `plain/` | 带注释的两个原始 PHP 文件 |
| `encrypted/` | beast 加密后的两个文件 |
| `captures/current/` | 可直接再次运行的有效明文项目 |
| `captures/objects/`、`captures/index.jsonl` | 内容对象与两条文件版本记录 |
| `wrong-order/` | 反向加载时捕获的密文入口 |
| `fpm-1/`、`fpm-4/` | 临时池配置及日志 |

不要将原项目或真实业务数据传入这个测试工具；它会自行生成并执行固定测试样例。更多通用限制见 [README](../README.md#使用限制) 与[存储与恢复](storage.md)。
