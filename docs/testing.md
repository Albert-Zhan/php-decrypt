# Linux 验证记录

验证日期：2026-10-28。环境：Ubuntu 24.04、Linux x86_64、GCC 13.3.0、本地文件系统。运行时均从 php.net 的发行源码编译，启用 CLI、hash、json，未加载 OPcache 或第三方加密扩展。

## 最终验证矩阵

| PHP / 模式 | 扩展构建流程 | PHPT | Base64 + eval 示例 |
|---|---|---|---|
| 7.4.33 NTS | phpize → configure → make，通过 | 11/11 通过，0 跳过 | 捕获、校验、恢复、再次执行通过 |
| 8.3.6 NTS | phpize → configure → make，通过 | 11/11 通过，0 跳过 | 捕获、校验、恢复、再次执行通过 |

| 8.3.6 ZTS | phpize → configure → make，通过 | 11/11 通过，0 跳过 | 捕获、校验、恢复、再次执行通过 |

扩展编译使用 `-g -O2 -Wall -Wextra -Werror -Wno-unused-parameter`。PHP 7.0 和 8.0 曾进行补充构建排查，但最终修改后的完整矩阵尚未完成，因此不列为本版已验证版本。Windows、macOS、FPM、ZTS 多线程并行请求、网络文件系统及商业加密扩展组合未验证。ZTS 结果仅指以 ZTS 模式构建的 CLI 运行。

## 测试内容

11 个 PHPT 包含 37 项场景检查：

- `capture_disabled_basic.phpt`、`capture_binary_basic.phpt`、`capture_history_variation.phpt`、`capture_empty_recovery.phpt`、`capture_options_variation.phpt`、`capture_paths_variation.phpt`：默认和显式关闭、原始字节与 NUL、Unicode/非 UTF-8 路径、重复 include、跨进程跳过重复发布、同内容跨路径去重、A→B→A 版本变化、空文件、缺失镜像恢复、history/index 开关、严格同步、include_path 解析、废弃输出与临时文件清理。
- `eval.phpt`：Base64 与嵌套 eval、带括号的来源路径、保留包装源码、重复片段去重、eval 开关、应用 JSON 错误状态保持。
- `failures.phpt`：配置错误、缺失输出目录、open_basedir、项目边界、输入和输出符号链接、发布失败保留原镜像、截断索引尾行、索引写入失败后的重试。
- `concurrency.phpt`：8 个进程捕获同一路径、不同路径共享内容对象与 JSONL 并发追加、读取者在连续替换时只观察到完整旧内容或新内容。
- `recovery.phpt`：完整性校验、相同长度的数据损坏检测、从对象恢复镜像、拒绝损坏历史对象。

- `helpers_configuration.phpt`：子进程继承测试框架的 INI 与扩展加载参数，避免固定 `-n` 丢失共享依赖；保留原有 36 项检查，新增这一项回归检查。

这些测试未模拟断电，也不能证明所有文件系统上的持久性或跨文件事务一致性。

## 完整示例结果

上述三个构建均输出：

```text
Hello from captured PHP
OK: 4 records, 4 unique objects, 4 published paths, 0 repaired
Verified and restored: index.php
Verified and restored: lib/message.php
Hello from captured PHP
```

4 条记录对应 2 个包装文件和 2 个明文 eval 体。恢复程序逐字节核对已知明文，补回示例特定的 PHP 起始标签，生成可执行的 `restored/index.php` 和 `restored/lib/message.php`。

## 微基准

命令：`DECRYPT_EXTENSION=/path/decrypt.so /path/php -n tools/benchmark.php 200 20480`。

200 个不同内容的文件，每个 20,480 字节，顺序 include 一遍；历史和索引开启，严格同步关闭。计时不包含 PHP 进程启动，三轮中位数。首次指输出目录为空，未变更指另一个 PHP 进程使用已有捕获目录。以下为功能提交 `6998087` 的容器测量结果，受缓存、调度和磁盘影响；本轮结构整理未重新测量性能：

| PHP | 关闭捕获 | 首次捕获 | 未变更内容再次捕获 |
|---|---:|---:|---:|
| 7.4.33 NTS | 8.984 ms | 68.535 ms | 39.552 ms |
| 8.3.6 | 8.766 ms | 67.950 ms | 39.333 ms |

未变更路径跳过源码及版本索引写入，但仍计算输入内容哈希、获取路径锁、读取状态及检查输出类型和大小，因此开销仍明显。不要将此结果视为生产请求耗时、网络磁盘或严格同步模式的性能保证；建议按需开启捕获。

## 复现

准备同一版本的 PHP、phpize 和 php-config；需要 make、C 编译器、autoconf。每次使用一个新的空构建目录：

```bash
PHP_BIN=/path/php PHPIZE=/path/phpize PHP_CONFIG=/path/php-config \
tools/test-linux.sh /tmp/decrypt-test-build

PHP_BIN=/path/php DECRYPT_EXTENSION=/tmp/decrypt-test-build/modules/decrypt.so \
examples/run-demo.sh /tmp/decrypt-demo-output
```

## 主分支整理范围

本轮删除未引用的 lib.h、未使用导出宏和模板残留，修正源文件权限；限制内部钩子可见性，补齐头文件依赖；拆分测试并继承测试框架子进程参数；为 capture.c 加入配置头文件。未修改捕获、去重、索引格式、目录映射、默认 INI 或请求生命周期逻辑。示例的路径、原始字节、内容哈希和对象映射与功能提交一致。
