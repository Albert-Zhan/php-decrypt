# 发布源码版本

[Source release 工作流](../.github/workflows/release.yml)在推送版本标签时自动发布 GitHub Release。日常主分支提交不会发布新版本；修改发布配置时只运行只读检查。

## 发布内容

- 版本标题为 `PHP Decrypt vX.Y.Z`。
- GitHub 自动提供标签对应的源码 ZIP 和 TAR.GZ 下载。
- 优先采用 `docs/releases/<标签>.md` 作为发布说明；没有该文件时，由 GitHub 生成说明。
- `vX.Y.Z-alpha.N`、`vX.Y.Z-beta.N`、`vX.Y.Z-rc.N` 会标记为 Pre-release，不设为 Latest。
- 正式版会设为 Latest。已有正式 Release 会跳过，已有草稿需要人工检查，不自动覆盖。

这个流程发布源码，不生成预编译 `.so`，不发布 PECL 包，也不会自动创建或推进版本标签。

## 发布步骤

以下以未来的 `v0.2.1` 为例：

1. 将 `php_decrypt.h` 中的 `PHP_DECRYPT_VERSION` 更新为 `0.2.1`。
2. 建议编写 `docs/releases/v0.2.1.md`，说明功能变化、修复、升级要求及验证范围。
3. 将修改提交到 `master`，确认常规 CI 通过，再在该提交上打标签并推送：

```bash
git switch master
git pull --ff-only origin master
git tag -a v0.2.1 -m "PHP Decrypt v0.2.1"
git push origin v0.2.1
```

推送标签会触发以下流程：

1. 校验标签与扩展版本号完全一致、标签指向当前检出的提交，且该提交已进入 `origin/master`。
2. 对标签所指的确定提交重新运行现有 Linux/macOS、Intel、FPM 和 ZTS 验证矩阵。
3. 所有验证通过后创建 Release。失败时不会执行发布步骤，可在 Actions 查看原因并重跑。

预发布示例：扩展版本写为 `0.3.0-rc.1`，标签写为 `v0.3.0-rc.1`。版本各数字段不能带多余的前导零。

发布说明属于该版本源码，需在打标签前提交。说明文件存在但为空会阻止发布。发布后不要移动或覆盖标签；有修复时更新版本号并创建新标签。

## 手动检查

进入 GitHub Actions 的 **Source release → Run workflow**，选择待检查的分支运行。手动运行只检查版本格式和发布说明，并显示对应提交，不会创建标签或 Release，也不代替完整兼容性测试。

本地也可以检查当前工作区的发布配置：

```bash
python3 -B tests/test_release.py
python3 -B tools/release.py
```

本地增加 `--tag v0.2.1` 会执行实际发布前的标签和主分支归属校验，需要先获取远端主分支与对应标签。工具本身不会写入 GitHub。

## 权限与失败处理

工作流使用 GitHub 自动提供的 `GITHUB_TOKEN`，不需要添加个人访问令牌。校验和测试仅有读取权限，只有发布作业申请 `contents: write`。

如果测试通过但发布被仓库策略拒绝，检查 Actions 的令牌权限和标签规则。已有草稿会导致工作流停止，需先检查该草稿内容，再选择手动发布或删除草稿后重跑；流程不会替换现有草稿。

从 GitHub Actions 中推送标签时，默认 `GITHUB_TOKEN` 通常不会再次触发新的工作流。日常发布请使用自己的 Git 客户端推送标签，或使用能触发工作流的授权方式。直接在网页创建并发布 Release 不属于这条标签推送流程。
