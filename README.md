PHP Decrypt
===========
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

PHP Decrypt 在 PHP 文件编译和字符串编译入口捕获源码。0.2 起以项目目录视图、可选的内容历史库和 JSONL 索引替代 `decrypt.code`。

它保存的是编译入口实际收到的字节。能否得到明文取决于包装脚本或加密扩展的实现、执行路径以及扩展加载顺序；不保证还原 opcode、混淆逻辑或未执行的文件。`eval()` 捕获保存求值前的代码字符串，不自动把多个动态片段拼成原文件。

## 平台范围

项目支持 **Linux** 和 **macOS**。已在 macOS 15.7.9 的 Apple Silicon（arm64）及 Intel（x86_64）上验证 PHP 7.4.33、8.3.36 的 CLI 和 FPM；另验证了 Apple Silicon 的对应 ZTS CLI，并对 PHP 8.3 ZTS 执行了真实线程捕获。具体版本和限制见 [运行环境验证](docs/runtime-testing.md)。Windows 宿主机用户使用 WSL2 或 Linux 容器时，请在其中使用 Linux 版 PHP 及对应工具链，按下述 Linux 流程编译和运行。

原生 Windows 不属于项目支持范围。[config.w32](config.w32) 和现有 Windows 条件编译代码仅作为开发者自行适配、编译的参考保留，不代表 Windows 构建可用或经过验证。

## 编译

需要 PHP CLI 和对应的开发工具、C 编译器、make、autoconf。PHP 必须包含 `hash` 和 `json` 扩展。各平台的具体结果见 [测试记录](docs/testing.md)及 [FPM / ZTS 验证](docs/runtime-testing.md)。第三方加密扩展、OPcache/Xdebug 组合和生产长期负载仍需单独验证。

```bash
git clone https://github.com/Albert-Zhan/php-decrypt.git
cd php-decrypt
phpize
./configure --enable-decrypt --with-php-config="$(command -v php-config)"
make -j2
make test TESTS=tests
sudo make install
```

多版本 PHP 要使用同一版本的 `php`、`phpize` 和 `php-config`。每个 PHP API 版本需分别编译模块，不能混用 `.so`。

### macOS 构建

使用已安装的 Xcode Command Line Tools 和 Homebrew。以下以 [Homebrew PHP 8.3](https://formulae.brew.sh/formula/php@8.3) 为例，不依赖固定的 Intel/Apple Silicon 安装目录：

```bash
brew install php@8.3 autoconf pkgconf
PHP_PREFIX="$(brew --prefix php@8.3)"
"$PHP_PREFIX/bin/phpize"
./configure --enable-decrypt --with-php-config="$PHP_PREFIX/bin/php-config"
make -j2
TEST_PHP_EXECUTABLE="$PHP_PREFIX/bin/php" make test TESTS=tests
```

macOS 的 `/tmp`、`/var` 等路径可能包含系统符号链接。输出目录需要使用物理路径，以保留扩展现有的符号链接保护规则。例如：

```bash
mkdir -p /tmp/php-captures-shop
cd /tmp/php-captures-shop && pwd -P
```

将打印的路径（例如 `/private/tmp/php-captures-shop`）用于 `decrypt.save_path`。示例和测试脚本会使用物理路径。APFS 只接受有效 UTF-8 文件名，对应的非 UTF-8 文件名测试会按文件系统能力跳过；中文文件名和二进制源码仍有测试覆盖。严格同步采用 `fsync()`，不声称已验证断电持久性。

## 配置

先创建一个独立、私有的输出目录。示例路径需要替换成实际路径；目录所有者应为运行 PHP 的用户，建议权限 `0700`，放在 Web 根目录以外。一个输出目录只对应一个项目。

```ini
extension=/absolute/path/to/decrypt.so

[decrypt]
decrypt.switch=enable
decrypt.project_root=/www/shop
decrypt.save_path=/data/php-captures/shop
decrypt.history=1
decrypt.index=1
decrypt.capture_eval=1
decrypt.sync=0
```

可复制 [decrypt.ini.example](decrypt.ini.example)。所有配置为 `PHP_INI_SYSTEM`，不能在脚本里用 `ini_set()` 修改；CLI 可通过 `-d` 指定，FPM 修改后需重启。

| 配置 | 默认值 | 含义 |
|---|---|---|
| `decrypt.switch` | `disable` | 设为 `enable` 才安装捕获钩子 |
| `decrypt.project_root` | 空 | 必填，已存在的项目绝对目录；外部源码不捕获 |
| `decrypt.save_path` | 空 | 必填，已存在且可写的输出绝对目录 |
| `decrypt.history` | `1` | 保存按 SHA-256 去重的原始内容对象 |
| `decrypt.index` | `1` | 记录版本变化；关闭后不生成可供历史恢复的索引 |
| `decrypt.capture_eval` | `1` | 捕获来源位于项目内的 `eval()` / 字符串编译内容 |
| `decrypt.sync` | `0` | Linux/macOS 下同步文件及目录更新，提高持久性并增加延迟 |

建议输出目录放在项目之外。允许放在项目内部的独立子目录，但该子树会被排除；输出目录不能等于或包含项目根目录。输出路径受 `open_basedir` 限制，拒绝已有的符号链接。输出目录及其父目录应由可信用户控制，不应允许其他进程恶意改动目录结构。

已有 `decrypt.switch`、`decrypt.save_path` 可保留，但升级时必须新增 `decrypt.project_root`。旧 `decrypt.code` 不再写入，也不会被自动转换或删除。

## 输出

以下路径相对于 `decrypt.save_path`；哈希名称在示例中缩写，实际为完整 SHA-256。

| 路径示例 | 内容 |
|---|---|
| `current/index.php` | 入口文件最近捕获到的原始字节 |
| `current/app/Controller/User.php` | 按项目相对路径映射的源码 |
| `eval/<来源哈希>/<内容哈希>.code` | 独立的字符串编译内容，保留原字节，不添加 PHP 标签 |
| `objects/ab/<完整内容哈希>.code` | 历史内容对象；相同内容共用一个文件 |
| `index.jsonl` | JSONL 版本记录 |
| `locks/<路径哈希>.lock` | 并发锁和上次成功发布的状态，运行中不要删除 |

`current` 只含实际经过捕获入口的项目文件，不复制图片、CSS、未执行源码等资源。它不是一次性完整项目快照；多个文件可能来自不同请求或时间。对于 Base64 + eval 包装，`current` 保留包装文件，求值时的明文在 `eval` 中。

索引字段：

| 字段 | 含义 |
|---|---|
| `schema` | 格式版本，目前为 `1` |
| `kind` | `file` 或 `eval` |
| `source_path` | 文件路径或字符串编译器提供的来源标签 |
| `relative_path` | 当前捕获文件相对于输出根目录的路径，包含 `current/` 或 `eval/` |
| `sha256` / `bytes` | 内容校验和 / 原始字节数 |
| `captured_at` / `pid` | Unix 秒级时间戳 / 捕获进程号 |
| `object` | 历史对象相对路径，未开启历史时为 `null` |
| `source_path_b64` / `relative_path_b64` | 路径原始字节的 Base64；非 UTF-8 路径以此字段为准 |

普通文件内容从 A 变成 B 再变回 A，会记录三次版本变化，但只保存两个内容对象。相同路径、内容和配置再次出现时不重复替换文件或追加索引。不同路径相同内容仍各有自己的项目视图和索引记录，但共用历史对象。`eval` 按来源和内容保存不同片段，即使 `history=0`，不同片段也会分别保留在 `eval`。

## 完整性和故障恢复

源码写入同目录下的唯一临时文件，完整写入并关闭后才重命名发布。按路径加锁保护版本更新，索引单独加锁追加；普通捕获不会扫描整个索引。检测到中断的最后一条 JSONL 记录时，在下次追加前截掉不完整尾行。

发布顺序为历史对象、当前文件、索引、锁文件状态。中途失败不提交成功状态，后续捕获会重试。源码输出失败会警告，通常继续编译原脚本；应用的错误处理器仍可能把警告转换成异常。

这不是跨文件事务：进程退出可能留下临时文件、无索引对象或未索引的当前版本；索引记录也可能因重试重复。`sync=0` 不提供断电持久性保证。`sync=1` 检查文件和目录同步结果，仍需底层文件系统和存储设备正确实现同步。

为减少重复捕获开销，正常路径信任扩展独占管理的输出和状态，只检查存在性、文件类型和大小，不反复读取每份历史对象计算校验和。手工修改或相同长度的数据损坏应使用完整性检查工具识别：

```bash
php -n tools/verify-captures.php /data/php-captures/shop
```

停用捕获后，可从完整历史对象恢复索引中最后记录的项目文件和 eval 片段：

```bash
php -n tools/verify-captures.php /data/php-captures/shop --repair-current
```

检查工具会验证索引路径和对象校验和，拒绝用损坏对象恢复。它不会修复已损坏的历史对象、猜测未索引内容或修复整个索引；索引缺失且无历史对象时无法重建。原项目删除文件不会触发输出清理，已有捕获会保留。

## 完整示例

仓库带有两个文件组成的 Base64 + eval 示例及明文对照。运行命令会执行包装项目、捕获文件和明文、校验所有对象、逐字节对照两个 eval 片段、恢复示例项目，然后执行恢复的入口：

```bash
PHP_BIN="$(command -v php)" \
DECRYPT_EXTENSION="$PWD/modules/decrypt.so" \
examples/run-demo.sh /tmp/php-decrypt-demo
```

成功时会看到两次 `Hello from captured PHP`，以及两个文件的校验和恢复结果。生成的 `restored/index.php` 和 `restored/lib/message.php` 与 `examples/demo/plain` 一致。

这是针对已知“一文件一个完整 eval 体”的示例导出逻辑；真实脚本可能动态生成多个片段、依赖外部输入或存在其他分支，不能据此保证自动恢复任意项目。实际捕获需要运行目标代码；建议在可控的隔离环境执行。

## OPcache 和扩展组合

OPcache 命中时文件编译钩子可能被跳过，因此不能假设每次请求都捕获全部文件。CLI 示例使用 `-n` 隔离配置，并在所选 PHP 将 hash/json 构建为共享模块时显式载入这两个依赖。使用第三方加密扩展时需逐一验证它是否将明文交给这些编译入口及加载顺序；本版不宣称通用解密能力。

## 测试和性能

Linux/macOS 下可用独立构建目录执行完整测试（脚本沿用原名称）：

```bash
PHP_BIN=/path/to/php PHPIZE=/path/to/phpize PHP_CONFIG=/path/to/php-config \
tools/test-linux.sh /tmp/php-decrypt-build
```

本地微基准比较关闭捕获、首次捕获和未变更内容重复捕获，输出三轮测量的中位数，不包含 PHP 进程启动时间：

```bash
DECRYPT_EXTENSION="$PWD/modules/decrypt.so" php -n tools/benchmark.php 200 20480
```

它会实际运行生成的无业务逻辑 PHP 文件，不代表 FPM 业务负载、慢磁盘、网络文件系统或严格同步模式的性能。更多验证细节见 [测试记录](docs/testing.md)。


### FPM 和原生线程验证

`tools/test-fpm.py` 可启动临时 FPM 池，验证同一 worker 连续请求、异常与退出后的恢复、版本变化和多 worker 并发。PHP 8.3 ZTS 还可通过 `tools/test-zts.php` 配合 parallel 运行真实线程捕获。运行方法、完整矩阵及验证限制见 [运行环境验证](docs/runtime-testing.md)。

## License

Apache License Version 2.0. See [LICENSE](LICENSE).
