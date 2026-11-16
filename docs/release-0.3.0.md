# Hyperf DPoP 0.3.0 与协议核心 0.2.0

2026-11-16（Asia/Shanghai）按核心包、适配包的顺序发行。两个包均声明 PHP `>=8.2.35 <8.6`。

| Composer 包 | 标签 | 提交 | 文件 |
| --- | --- | --- | ---: |
| `lowelllab/dpop-core` | [v0.2.0](https://github.com/lowelllab/dpop-core/releases/tag/v0.2.0) | `923334dff83ccdcda3f924b76b9df529fbfcc67f` | 18 |
| `lowelllab/hyperf-dpop` | [v0.3.0](https://github.com/lowelllab/hyperf-dpop-package/releases/tag/v0.3.0) | `986421a62cccac75186f3cbd4749f3151265c18b` | 37 |

默认 Packagist 的源提交和分发提交均与标签匹配。适配包依赖核心 `^0.2.0`。 [核心包索引](https://repo.packagist.org/p2/lowelllab/dpop-core.json) · [适配包索引](https://repo.packagist.org/p2/lowelllab/hyperf-dpop.json)

## GitHub Actions 验证

发行前提交 `1fbba6b27ea62b9a04017c9da5e026b641e2c283` 的七个 PHP 测试点通过工作区回归、默认稳定和最低稳定依赖的独立候选安装。 [发行前 CI](https://github.com/lowelllab/hyperf-dpop/actions/runs/43795402903)

发行后从默认 Packagist 在 PHP 8.2.35、8.3.36、8.4.27 和 8.5.12 四个测试点重新解析并安装两个公开标签。各分支核对两个提交与 55 个文件的 SHA-256，并通过 Composer 平台检查、依赖公告审计、核心协议、HTTP/TLS、MySQL 和 Redis 重放、mTLS 开关、网关 HTTP/1.1 与 HTTP/2 和存储中断恢复。 [公开包复验 CI](https://github.com/lowelllab/hyperf-dpop/actions/runs/43796093360)

## 指定 Hyperf 宿主复验

在既有 Hyperf 3.2.0 宿主的隔离副本复用应用和验收脚本。PHP 8.2.35、8.3.36、8.4.27 用空锁解析默认 Packagist 的稳定依赖。PHP 8.5.12 使用宿主原始锁文件升级，生产依赖仅更新协议核心和适配包。所有版本均使用 Swoole 6.2.5，验证了 55 个分发文件、Composer 平台要求及依赖公告。

四个运行时分别用 MySQL 与 Redis 重放驱动运行 mTLS 开关两种模式，16 个场景全部通过。宿主完成 nonce 挑战、令牌签发和访问、刷新轮换、旧凭据撤销、错误绑定和 proof 重放拒绝。每组四路并发刷新仅一路成功。mTLS 拒绝无证书、未知 CA、过期和吊销证书。生成配置通过 `haproxy -c`，宿主默认日志仍输出到控制台。
