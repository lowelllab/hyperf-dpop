# lowelllab 双包发布准备验证

2026-11-02，按用户选择确定 Composer 包名 `lowelllab/dpop-core` 和 `lowelllab/hyperf-dpop`，继续采用双包。当前完成本地发布准备，没有创建公开发布仓库、提交 Packagist 或创建版本标签。实际发布步骤见[双包发布指南](publishing.md)。

## 改动范围

- 更新工作区和两个包的名称、相互依赖、path 版本映射、配置发布命令及使用文档。
- Composer 重新生成锁文件，移除旧 acme 包并安装 lowelllab 包，第三方依赖版本保持不变；PHP 命名空间及包内 PHP 源码保持不变。
- 两包增加 `dev-main` 到 `0.1.x-dev` 的分支别名；只安装适配包时，Composer 能解析核心的 `^0.1@dev` 开发依赖。
- 增加本地包根目录导出与独立 VCS 安装验证工具，CI 纳入同类步骤，测试镜像补充 Git。导出清单采用源码/元信息/许可/模板白名单，不携带工作区配置或凭据。

## 验证结果

| 检查 | 实际结果 |
| --- | --- |
| 工作区和双包 Composer strict | 通过 |
| 既有单元/集成/真实存储 | 108 测试、169 断言通过 |
| 静态/语法 | PHPStan 级别 6 无错误，81 个 PHP 文件语法通过 |
| 两驱动跨进程竞争与取消 | 各 12 进程 1 接受/11 拒绝；取消不执行业务并释放预算 |
| 生产 CLI | 15 场景通过，新包名的配置和 DDL 发布可用，认证账本写入 0 |
| 真实 HTTP / TLS | 27 / 12 场景通过，主体隔离与相同 proof 竞争仍满足原条件 |
| 独立 Hyperf 消费项目迁移 | Composer 更名、no-dev 安装后 85 项通过；两个原始重复头部署限制仍单列 |
| 干净临时消费项目 | 只要求新适配包，自动引入新核心包，旧包和根 dev 自动加载没有混入 |
| 双发布根目录 VCS 安装 | 核心 17 文件、适配 31 文件与源码摘要一致；从两个本地 main 分支实际安装，别名、传递依赖、原 PHP 类和 ConfigProvider 均通过 |
| 依赖公告 | 审计退出 0，advisories / abandoned 为空，仅覆盖查询时已知公告 |

独立消费项目启动时遇到 MySQL 临时初始化阶段的 TCP 连接拒绝，在测试 setup 中增加有期限的 SQL 就绪探测后通过；未将此启动时序问题计为包缺陷。

## 发布产物

`build/packagist-latest.json` 指向本次本地导出批次，批次内 `manifest.json` 保存文件摘要，`verification.json` / `verification.log` 保存两仓 VCS 安装结果。该批次的两个 ZIP 包根目录直接包含对应 composer.json，排除验证时生成的 `.git` 及消费项目。

本地汇总证据为 `build/packaging-verification-20261102.json`，包含第三方依赖版本比较、当前锁文件摘要、两个 ZIP 摘要和回归计数。此前 consumer、functional 报告保留原 acme 命名和原摘要，作为对应时间的历史快照，不静默改写。

当前 GitHub 工作区根名称是 `lowelllab/hyperf-dpop-workspace`，不能继续将其 URL 提交成上述任一个包。正式提交需要两个独立发布仓库的根 composer.json 分别声明对应名称，并先提交核心、再提交适配包。新包名准备通过不表示已经完成公共发布或稳定发行验收。
