# Hyperf DPoP 0.2.0 发布与验收

2026-11-15（Asia/Shanghai）发布 Hyperf 适配包 0.2.0。协议核心继续使用 0.1.0。适配包新增默认关闭的 mTLS 准入开关、集中配置、HAProxy 配置生成、Redis 连接池选择及 MySQL 连接超时配置。旧配置键继续生效。 [适配包正式发行](https://github.com/lowelllab/hyperf-dpop-package/releases/tag/v0.2.0) · [包能力与启用步骤](configuration.md)

| Composer 包 | 版本 | Git 提交 | Packagist 安装文件数 |
| --- | --- | --- | --- |
| `lowelllab/hyperf-dpop` | `v0.2.0` | `74e79f29fd9cd57e66e9553c72af0c69d27a81ec` | 36 |
| `lowelllab/dpop-core` | `v0.1.0` | `f8fae1215edbeb4b80ec067f93f3a828708effb9` | 18 |

## 公开安装验收

- 新建独立 Hyperf 宿主，以默认稳定性和默认 Packagist 安装。没有预置 lock、vendor、Composer 缓存或 path/VCS 覆盖。宿主要求适配包 `0.2.0`，核心由 Composer 自动引入。66 个生产依赖完成安装，两个包的 54 个分发文件均与标签内容逐字节一致。Composer strict 校验及依赖公告审计通过。 [Packagist 适配包](https://packagist.org/packages/lowelllab/hyperf-dpop#v0.2.0)
- 在 PHP 8.5.12、Swoole 6.2.4、Hyperf Framework 3.2.0 与 HAProxy 3.2.26 上，从已安装包的配置模板加载 `dpop.php`，由 `dpop:gateway-config` 生成配置。两个模式均通过 HAProxy 配置检查和已安装包的 `dpop:doctor`。
- mTLS 关闭时 HTTP/1.1 和 HTTP/2 网关检查通过 180 项。开启时通过 188 项，其中两个协议均拒绝无证书、不受信证书和认证标记 Trailer，并验证有效证书客户端能完成 DPoP nonce、签发、资源访问与刷新。通过证书验证但缺少 DPoP 凭据的资源请求仍被拒绝。
- 两个模式各有一次原生 Swoole HTTP/1 请求尾部等待观察，单独记录。生产入口仍需按网关契约剥离尾部字段并隔离后端。 [网关接入](gateway.md)

开发主仓提交 `900e924a4695106dc0326444ba6d6a4f307cfea8` 的本地 `composer check` 通过，覆盖 159 项 PHPUnit 测试、302 次断言和 24 项 CLI 场景。远端 CI 的 27 个步骤全部通过。 [CI 记录](https://github.com/lowelllab/hyperf-dpop/actions/runs/43662117593)
