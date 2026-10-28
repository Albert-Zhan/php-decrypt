# FPM、Intel Mac 与 macOS ZTS 验证

日期：2026-10-28。测试按 Linux FPM → macOS arm64 FPM → Intel Mac → macOS arm64 ZTS 的依赖顺序执行。原生 CI 记录：[完整运行矩阵](https://github.com/Albert-Zhan/php-decrypt/actions/runs/40659838439)。

## 验证矩阵

| 环境 | PHP / 模式 | 编译、PHPT、完整示例 | 额外验证 |
|---|---|---|---|
| Ubuntu 24.04 x86_64 | 7.4.33 NTS | 12 个 PHPT 全通过 | 真实 FPM 连续请求、异常恢复和并发通过 |
| Ubuntu 24.04 x86_64 | 8.3.36 NTS | 12 个 PHPT 全通过 | 同上 |
| macOS 15.7.9 arm64 | 7.4.33 NTS | 11 通过、1 APFS 跳过、0 失败 | 同上 |
| macOS 15.7.9 arm64 | 8.3.36 NTS | 11 通过、1 APFS 跳过、0 失败 | 同上 |
| macOS 15.7.9 Intel x86_64 | 7.4.33 NTS | 11 通过、1 APFS 跳过、0 失败 | 同上 |
| macOS 15.7.9 Intel x86_64 | 8.3.36 NTS | 11 通过、1 APFS 跳过、0 失败 | 同上 |
| macOS 15.7.9 arm64 | 7.4.33 ZTS | 11 通过、1 APFS 跳过、0 失败 | ZTS CLI 构建和运行 |
| macOS 15.7.9 arm64 | 8.3.36 ZTS | 11 通过、1 APFS 跳过、0 失败 | 4 个原生线程同时捕获，校验和、去重及索引通过 |

APFS 跳过的仅是文件系统无法创建的非 UTF-8 文件名。所有平台继续运行中文路径、二进制源码、嵌套 eval、原子发布、版本恢复和完整性检查。完整示例均执行捕获、逐字节核对、导出两个明文文件和再次运行。

另在本地 Linux 源码构建的 PHP 7.4.33 / 8.3.6 FPM 上执行了同样的集成检查，并在 Linux PHP 8.3.6 ZTS + parallel 1.2.15 上验证了真实线程捕获。

## FPM 检查方法

`tools/test-fpm.py` 使用 Python 3 标准库启动临时独立 FPM 池，通过真实 FastCGI 协议发送请求，不需要 Nginx 或第三方 Python 包。池仅监听随机的 `127.0.0.1` 端口，测试结束后关闭。配置关闭 OPcache，以确保每次请求经过编译钩子。

每个 FPM 组合发送 29 次单 worker 请求及 24 次并发请求，检查：

1. 连续 21 次正常请求返回相同 worker PID，输出正确，相同源码不重复追加版本记录。
2. 未捕获 PHP 异常返回 500，后续正常请求仍由相同 PID 正确处理。
3. `exit()` 正常结束当前请求，后续请求继续捕获。
4. 同一源码跨请求 A→B→A，保留三次变化并复用内容对象。
5. 用目录占用捕获目标制造写入失败，恢复目标后同一 worker 再次正确发布。
6. 4 个 worker 接收 24 次并发请求，确认实际使用多个 PID，JSONL 可完整解析，镜像与所有历史对象的长度和 SHA-256 正确。

复现（需已构建匹配的模块和 FPM）：

```bash
python3 tools/test-fpm.py \
  --fpm /absolute/path/to/php-fpm \
  --extension /absolute/path/to/decrypt.so
```

成功时清理临时目录，失败时打印并保留诊断日志；`--keep` 可保留成功运行的诊断目录。工具使用所选 FPM 的正常 INI 配置加载共享依赖，并显式关闭 OPcache。它不更改已有 FPM 服务配置。

## ZTS 检查方法

macOS 的两个 ZTS 作业首先确认 `PHP_ZTS` 为真，再以该构建自己的 phpize、php-config 和 CLI 编译、运行 PHPT 与示例。

PHP 8.3 的额外检查加载 `parallel`，以 `parallel\Runtime` 创建 4 个实际原生线程。每个线程重复加载公共脚本和自身脚本，累计 160 次 include 与对应 eval。检查线程返回值和 PID、10 条去重版本记录（5 份文件与 5 份 eval 来源）、2 个内容对象及全部字节校验和。

`parallel` 仅是线程验证工具的依赖，不是 decrypt 本身的运行依赖。PHP 7.4 ZTS 本轮仅验证 CLI，没有使用旧版 parallel 验证真实线程。

复现 PHP 8.3 线程检查时，需预先创建新的物理路径目录，并使用加载了 parallel 的 ZTS PHP：

```bash
mkdir -p /absolute/new-test-root/project /absolute/new-test-root/captures
/path/to/zts/php -d extension=/path/to/decrypt.so -d decrypt.switch=enable \
  -d decrypt.project_root=/absolute/new-test-root/project \
  -d decrypt.save_path=/absolute/new-test-root/captures -d opcache.enable_cli=0 \
  tools/test-zts.php /absolute/new-test-root
```

## 验证过程中修正的测试工具问题

- Ubuntu PHP 7.4 的 JSON 可以是共享模块。固定 `-n` 会令模块加载失败；新增 `tools/php-runtime.sh`，在隔离 CLI 中显式载入所选 PHP 的共享 hash/json 依赖。构建测试和完整示例共用这段逻辑。
- Homebrew PHP 8.3 ZTS 的生成配置可包含过时的 `PHP_EXECUTABLE` 路径，造成 make test 打印错误但返回成功。测试脚本现显式传入所选 CLI，并要求日志中至少存在一项通过的 PHPT。
- 正式测试前检查 decrypt 实际已加载，防止缺失依赖导致所有测试静默跳过。

本轮没有改动扩展 C 核心、INI 默认值、编译钩子、捕获格式或存储算法。

## 范围限制

这些结果覆盖指定 PHP 补丁版本、macOS 15.7.9 与本地文件系统。尚未验证 macOS Intel ZTS、PHP 7.4 的真实线程、其他 macOS/PHP 版本、OPcache/Xdebug/商业加密扩展组合、网络文件系统、强杀或超时中断、断电恢复及长时间生产负载。FPM 测试直接使用 FastCGI，未验证具体 Web 服务器的配置。
