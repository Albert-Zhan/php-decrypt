# Linux 验证记录

验证日期：2026-10-28。环境：Ubuntu 24.04、Linux x86_64、GCC 13.3.0、本地文件系统。运行时均从 php.net 的发行源码编译，NTS，启用 CLI、hash、json，未加载 OPcache 或第三方加密扩展。

## 最终验证矩阵

| PHP | 扩展构建流程 | PHPT | Base64 + eval 示例 |
|---|---|---|---|
| 7.4.33 | phpize → configure → make，通过 | 5/5 通过，0 跳过 | 捕获、校验、恢复、再次执行通过 |
| 8.3.6 | phpize → configure → make，通过 | 5/5 通过，0 跳过 | 捕获、校验、恢复、再次执行通过 |

扩展编译使用 `-g -O2 -Wall -Wextra -Werror -Wno-unused-parameter`。PHP 7.0 和 8.0 曾进行补充构建排查，但最终修改后的完整矩阵尚未完成，因此不列为本版已验证版本。Windows、macOS、ZTS、FPM、网络文件系统及商业加密扩展组合未验证。

## 测试内容

5 个 PHPT 包含 36 项场景检查：

- `capture.phpt`：默认和显式关闭、原始字节与 NUL、Unicode/非 UTF-8 路径、重复 include、跨进程跳过重复发布、同内容跨路径去重、A→B→A 版本变化、空文件、缺失镜像恢复、history/index 开关、严格同步、include_path 解析、废弃输出与临时文件清理。
- `eval.phpt`：Base64 与嵌套 eval、带括号的来源路径、保留包装源码、重复片段去重、eval 开关、应用 JSON 错误状态保持。
- `failures.phpt`：配置错误、缺失输出目录、open_basedir、项目边界、输入和输出符号链接、发布失败保留原镜像、截断索引尾行、索引写入失败后的重试。
- `concurrency.phpt`：8 个进程捕获同一路径、不同路径共享内容对象与 JSONL 并发追加、读取者在连续替换时只观察到完整旧内容或新内容。
- `recovery.phpt`：完整性校验、相同长度的数据损坏检测、从对象恢复镜像、拒绝损坏历史对象。

这些测试未模拟断电，也不能证明所有文件系统上的持久性或跨文件事务一致性。

## 完整示例结果

两个 PHP 版本均输出：

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

200 个不同内容的文件，每个 20,480 字节，顺序 include 一遍；历史和索引开启，严格同步关闭。计时不包含 PHP 进程启动，三轮中位数。首次指输出目录为空，未变更指另一个 PHP 进程使用已有捕获目录。以下为当前容器一次运行的结果，受缓存、调度和磁盘影响：

| PHP | 关闭捕获 | 首次捕获 | 未变更内容再次捕获 |
|---|---:|---:|---:|
| 7.4.33 | 8.984 ms | 68.535 ms | 39.552 ms |
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
