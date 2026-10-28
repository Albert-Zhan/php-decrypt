# PHP Decrypt

[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

PHP Decrypt 是一个 PHP 扩展，在文件编译和字符串编译入口捕获源码，用于分析运行时交给 PHP 编译器的代码，包括 `eval()` 接收到的代码字符串。

- 按项目相对路径保存最近捕获的文件，保留源码原始字节。
- 独立保存 `eval()` 代码片段，不添加 PHP 标签或拼接片段。
- 通过 SHA-256 对历史内容去重，以 JSONL 索引记录版本变化。
- 提供并发写入保护、完整性检查工具和可运行的捕获示例。

能否捕获到明文取决于目标代码、加密扩展的实现和加载顺序。本扩展不保证还原 opcode、消除混淆或恢复未执行的代码。

## 环境要求

- Linux 或 macOS，PHP CLI 及对应的 `phpize`、`php-config` 开发工具。
- PHP 的 `hash`、`json` 扩展。
- C 编译器、make、autoconf；macOS 另需 Xcode Command Line Tools。

当前主要验证范围如下，完整补丁版本、测试结果和限制见[运行环境验证](docs/runtime-testing.md)。

| 平台 | PHP | 已验证的运行方式 |
|---|---|---|
| Linux x86_64 | 7.4 / 8.3 | NTS CLI、FPM；另有 Linux 8.3 ZTS CLI 和真实线程验证 |
| macOS Apple Silicon（arm64） | 7.4 / 8.3 | NTS CLI、FPM、ZTS CLI；8.3 另含真实线程验证 |
| macOS Intel（x86_64） | 7.4 / 8.3 | NTS CLI、FPM |

普通 CLI 和 FPM 场景建议使用 NTS。编译扩展时，PHP 版本、架构和 NTS/ZTS 构建必须与目标运行时匹配，不能混用 `.so`。

原生 Windows 不属于支持范围；Windows 用户可在 WSL2 或 Linux 容器内按 Linux 流程使用。[config.w32](config.w32) 和 Windows 条件编译代码保留给自行适配的开发者，不代表已验证 Windows 构建。

## 安装

### 从源码编译

先安装目标 PHP 对应的开发工具和上述构建依赖，再执行：

```bash
git clone https://github.com/Albert-Zhan/php-decrypt.git
cd php-decrypt
phpize
./configure --enable-decrypt --with-php-config="$(command -v php-config)"
make -j2
sudo make install
```

构建产物为 `modules/decrypt.so`；`make install` 将其安装到所选 PHP 的扩展目录。安装目录属于当前用户时，可省略 `sudo`。测试方法见[测试与开发](#测试与开发)。

### macOS 准备

安装 Xcode Command Line Tools 后，可用 Homebrew 准备工具链。以下以 PHP 8.3 为例，适用于 Apple Silicon 和 Intel：

```bash
brew install php@8.3 autoconf pkgconf
export PATH="$(brew --prefix php@8.3)/bin:$PATH"
```

随后执行上面的源码编译命令。多版本环境中，确保 `php`、`phpize`、`php-config` 来自同一套 PHP 安装。

## 快速使用

以下命令从仓库根目录执行，临时加载刚构建的模块，无需先修改 `php.ini`。将 `/path/to/project`、`/path/to/captures` 和入口 `index.php` 替换为实际路径；输出目录应位于项目和 Web 根目录之外，并由运行 PHP 的用户独占管理。

```bash
PROJECT_ROOT="$(cd "/path/to/project" && pwd -P)"
mkdir -p "/path/to/captures"
chmod 700 "/path/to/captures"
CAPTURE_ROOT="$(cd "/path/to/captures" && pwd -P)"

php -d "extension=$PWD/modules/decrypt.so" \
    -d decrypt.switch=enable \
    -d "decrypt.project_root=$PROJECT_ROOT" \
    -d "decrypt.save_path=$CAPTURE_ROOT" \
    -d opcache.enable_cli=0 \
    "$PROJECT_ROOT/index.php"
```

如果已在 INI 中加载扩展，请省略命令中的 `-d "extension=..."`，避免重复加载。扩展仅捕获实际经过编译入口、且来源位于项目内的代码。要捕获其他执行分支，需要运行对应入口和输入；这会执行目标程序的业务逻辑，请在可控环境中操作。

macOS 的 `/tmp`、`/var` 可能经过系统符号链接。上面的 `pwd -P` 会取得物理路径，例如 `/private/tmp/...`，以满足输出路径的符号链接保护规则。

## 配置

长期使用或接入 FPM 时，可参考 [decrypt.ini.example](decrypt.ini.example)，将以下内容加入目标 PHP 的 `php.ini` 或扫描配置目录：

```ini
extension=decrypt.so

[decrypt]
decrypt.switch=enable
decrypt.project_root=/www/shop
decrypt.save_path=/data/php-captures/shop
```

`extension=decrypt.so` 适用于已执行 `make install` 的情况，也可填写模块绝对路径。两个目录必须提前创建，输出目录须对 PHP 运行用户可写；建议权限为 `0700`，每个项目使用独立输出目录。

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `decrypt.switch` | `disable` | 设为 `enable` 才启用捕获钩子 |
| `decrypt.project_root` | 空 | 启用捕获时必填，已存在的项目绝对目录 |
| `decrypt.save_path` | 空 | 启用捕获时必填，已存在且可写的输出绝对目录 |
| `decrypt.history` | `1` | 保存按内容去重的历史对象 |
| `decrypt.index` | `1` | 追加 JSONL 版本记录，供检查和恢复工具使用 |
| `decrypt.capture_eval` | `1` | 捕获来源位于项目内的字符串编译内容，包括 `eval()` |
| `decrypt.sync` | `0` | 同步文件和目录更新，提高持久性并增加写入延迟 |

所有配置均为 `PHP_INI_SYSTEM`，不能用 `ini_set()` 修改。CLI 可使用 `-d`；修改 FPM 配置后需重启或重新加载 FPM。CLI 与 FPM 可能使用不同配置文件，`php --ini` 和 `php --ri decrypt` 仅检查当前 CLI。

输出路径受 `open_basedir` 限制，拒绝已有符号链接。输出目录不能等于项目根目录，也不能是项目根目录的上级目录；若置于项目内部，该输出子树会被排除。输出目录及其父目录应由可信用户控制。

从旧版本升级时，需新增 `decrypt.project_root`。0.2 起不再写入 `decrypt.code`，旧文件不会自动转换或删除。

## 捕获结果

以下路径均相对于 `decrypt.save_path`，哈希值使用完整 SHA-256：

| 路径 | 内容 |
|---|---|
| `current/index.php` | 入口文件最近捕获到的原始字节 |
| `current/app/Controller/User.php` | 按项目相对目录结构保存的文件 |
| `eval/<来源哈希>/<内容哈希>.code` | 独立代码片段，保留原字节，不补 PHP 标签 |
| `objects/<内容哈希前两位>/<内容哈希>.code` | 开启 `history` 时保存的去重历史对象 |
| `index.jsonl` | 开启 `index` 时追加的版本记录 |
| `locks/<路径哈希>.lock` | 并发锁和发布状态，捕获运行期间不要删除 |

`current/` 保留文件编译入口收到的内容。对于 Base64 + eval 包装，包装文件在 `current/`，求值前的代码字符串在 `eval/`。原项目删除文件后，已有捕获仍会保留。

同一普通文件从 A 变成 B 再变回 A，默认记录三次版本变化，但只保存两个历史内容对象。不同路径的相同内容也共用历史对象。

捕获结果只包含实际经过捕获入口的代码，不包含图片、CSS 或未执行源码，也不构成同一时刻的完整项目快照。索引字段、写入流程及完整性检查见[存储与恢复](docs/storage.md)。

## 完整示例

仓库提供由两个文件组成的 Base64 + eval 示例及明文对照。从仓库根目录执行，输出路径必须尚不存在：

```bash
PHP_BIN="$(command -v php)" \
DECRYPT_EXTENSION="$PWD/modules/decrypt.so" \
examples/run-demo.sh /tmp/php-decrypt-demo
```

脚本会运行包装项目、捕获文件与 eval 体、校验原始字节和历史对象，再导出并执行恢复后的示例。成功时会看到两次 `Hello from captured PHP`；`restored/index.php` 和 `restored/lib/message.php` 与 [examples/demo/plain](examples/demo/plain) 一致。

示例的导出逻辑针对已知的“一文件一个完整 eval 体”，不能直接用于自动恢复任意项目。脚本会隔离 PHP 配置，并在需要时显式加载共享的 `hash`、`json` 依赖。

## 使用限制

- **OPcache：** 缓存命中可能跳过文件编译钩子，不能假设每次请求都重新捕获。快速使用命令和集成测试关闭了对应的 OPcache 功能。
- **扩展组合：** 第三方加密扩展能否把明文交给这些入口、以及与 OPcache/Xdebug 的兼容性，需要逐一验证实现和加载顺序。
- **写入与恢复：** 源码通过同目录临时文件完整写入后再重命名发布，但多个输出文件之间没有事务保证。异常中断及同步选项的边界见[存储与恢复](docs/storage.md#写入与故障恢复)。

## 测试与开发

Linux 和 macOS 均可用以下脚本在独立目录构建并运行 PHPT；构建目录应使用仓库之外的新空目录，PHP 工具路径需替换为实际值：

```bash
PHP_BIN=/path/to/php PHPIZE=/path/to/phpize PHP_CONFIG=/path/to/php-config \
tools/test-linux.sh /tmp/php-decrypt-build
```

脚本会处理共享 `hash`、`json` 依赖，确认扩展实际加载，并检查测试确实执行。`test-linux.sh` 的名称沿用早期版本，同样适用于 macOS。

| 文档或工具 | 用途 |
|---|---|
| [基础测试与性能记录](docs/testing.md) | PHPT 场景、示例结果及本地微基准 |
| [运行环境验证](docs/runtime-testing.md) | Linux/macOS、Intel、FPM 和 ZTS 的验证矩阵及复现方法 |
| [tools/test-fpm.py](tools/test-fpm.py) | 真实 FPM 连续请求、异常恢复和多 worker 并发检查 |
| [tools/test-zts.php](tools/test-zts.php) | 配合 `parallel` 进行原生线程捕获检查 |
| [tools/verify-captures.php](tools/verify-captures.php) | 校验索引引用的对象与文件，按索引恢复镜像 |

`parallel` 仅用于线程测试，不是本扩展的运行依赖。性能数据为本地微基准，不代表业务请求、网络文件系统或长期生产负载。

## 许可证

Apache License 2.0，详见 [LICENSE](LICENSE)。
