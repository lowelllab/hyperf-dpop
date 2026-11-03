# 0.1.0 正式版发布记录

日期：2026-11-03（Asia/Shanghai）。**双包0.1.0正式版已发布，并完成默认Packagist干净安装与独立Hyperf完整验收。** 用户在本地beta验收后明确要求正式发行；本次按DEC-009将版本分发与目标生产/独立审计分别记录，未伪造未执行验收结果。

## 已发布版本

| 包 | 发布提交 | GitHub Release | Packagist |
| --- | --- | --- | --- |
| lowelllab/dpop-core | f8fae1215edbeb4b80ec067f93f3a828708effb9 | [v0.1.0](https://github.com/lowelllab/dpop-core/releases/tag/v0.1.0)，Latest，非预发行 | 稳定索引/实际安装指向相同提交 |
| lowelllab/hyperf-dpop | 91026a2cea960be4315dbab3dcf63920d6ca3b0f | [v0.1.0](https://github.com/lowelllab/hyperf-dpop-package/releases/tag/v0.1.0)，Latest，非预发行 | 稳定索引/实际安装指向相同提交 |

开发主仓是workspace，不作为扩展安装入口、不打本次扩展标签。beta标签、Release与固定证据原样保留。实施契约仍为0.8，不作为发行号。

```bash
composer require "lowelllab/hyperf-dpop:^0.1"
```

核心由稳定传递依赖自动引入，不需要额外核心根约束、@beta/@dev、minimum-stability调整或自定义仓库。适配要求核心^0.1.0。两包新增SECURITY.md/Composer支持与私密漏洞入口，共18+33文件；43个src/publish功能文件与已验收beta逐字节相同，PHP命名空间保持Acme。

## 本次实际验证

| 范围 | 实际结果 |
| --- | --- |
| 发布前 | 两包strict、白名单、本地main消费及v0.1.0标签默认稳定性下仅适配根需求/核心传递安装通过 |
| 远端CI | [41568187402](https://github.com/lowelllab/hyperf-dpop/actions/runs/41568187402)，代码3a41fbc全部23步骤成功；153测试/281断言、PHPStan6、84语法、CLI17、HTTP27/TLS12、网关180及真实H2尾帧17 |
| 原生观察 | CI另1项原生H1尾部等待观察不计PASS；没有以超时替代明确拒绝 |
| 公开独立消费 | 新建hyperf-dpop-stable-consumer，全新lock/vendor、默认稳定性、无path/VCS或工作区挂载；仅要求适配^0.1，两个包实际版本v0.1.0、88生产包、51文件/集合一致 |
| 真实Hyperf | 完整accept-release.ps1 -FreshVendor执行成功，MySQL/Redis各HTTP1冷与HTTP2热四组各87 PASS/0 FAIL，生产缓存开启且无开发依赖 |
| 并发与业务边界 | 每组64请求跨2 Worker身份隔离；24路同proof仅1成功，8路刷新仅1成功；JWT/opaque/授权/回滚/撤销通过 |
| 只读及可选发布 | 生产诊断6项；独立工具宿主真实devtool模板发布7项，复制/保留/force与认证零写入/零迁移通过 |
| 专项 | 真实H1/H2尾部及原生H2保护29项、共享预算15项、依赖中断恢复11项、文档客户端完整流程通过 |
| 依赖中断 | Redis停止503约5.52秒；MySQL断网503约3.01秒；恢复后原proof仅首次成功、随后重放拒绝 |
| 质量与清理 | 独立宿主43 PHP语法，生产/工具宿主各自strict与依赖公告审计通过；实验容器/网络清空、故障凭据删除，原5业务容器ID未变 |

87项为同一集合在四种模式重复，不称为348个独立场景。实测PHP8.5.12、Swoole6.2.4、Framework3.2.0/DI3.2.2；其他运行时未据此认证。全部输入/逐项结果和源码摘要见[结构化证据](release-0.1.0.json)。

## 升级、维护与保留边界

先按[网关说明](gateway.md)部署重复认证头检查、认证Trailer声明拒绝和实际请求尾部剥离，固定HTTP/1.1后端并隔离访问，再更新应用。beta宿主移除path覆盖、调整两个beta精确根约束后用Composer更新，勿手改lock。模板默认保留宿主已有文件，应人工合并配置。

三个仓库已启用GitHub私密漏洞报告，维护入口lowelllab，链接和处理方式见[SECURITY.md](../SECURITY.md)。正式分发不表示独立安全审计或目标生产验收已完成：真实CDN/Ingress/网络策略、容量/SLO、HA/安全状态丢失/备份恢复仍开放。原生Swoole HTTP2受保护入口503；原生HTTP1异常尾部可能在PHP前等待，必须使用检查网关和后端隔离。

## 本地交付与复现

独立项目：`C:/ProjectArchiving/GegoreGos/hyperf-dpop-stable-consumer`。

```powershell
pwsh -NoProfile -File acceptance/accept-release.ps1 -FreshVendor
```

日志：`build/release-0.1.0/stable-acceptance.log`；源码及独立锁归档`build/hyperf-dpop-0.1.0-consumer-20261103.zip`，78文件，排除vendor/runtime/evidence/.env/私钥，ZIP完整性与逐文件摘要通过；SHA256 `4cb285ea6a4bd6696f45aa569acfef2bf31a9f030afc84c3051c18fa3f014f09`。

既有[beta发行记录](release-0.1.0-beta.1.md)与[独立beta验收](acceptance-beta-20261103.md)保留历史。收尾只更新发行记录/状态，不改变已验证实现，不重复运行无关代码测试。

固定正式发行证据已附于[GitHub Release](https://github.com/lowelllab/hyperf-dpop-package/releases/download/v0.1.0/verification-0.1.0.json)；SHA256 `041e085801d79ea9e3f2c0cdf1c2c951442ae7ada6d2b2446a9b8e2e767f3042`，服务端与本地摘要一致。收尾提交仅文档/证据，使用skip ci；代码3a41fbc的远端CI已实际通过。
