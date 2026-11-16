# Hyperf DPoP

为 Hyperf 应用提供基于 RFC 9449 的 DPoP（Demonstrating Proof of Possession）服务端认证扩展。通过校验客户端持钥证明，将访问令牌与客户端公钥绑定，降低令牌泄露后被直接冒用的风险。

## 核心功能

- **证明验证**：支持 ES256 签名，校验 HTTP 方法、目标 URI、公钥、时间窗口及访问令牌摘要 `ath`。
- **令牌密钥绑定**：支持 JWT 和不透明令牌，通过可替换的解析接口接入现有认证体系，校验令牌与证明公钥的绑定关系。
- **Nonce 挑战**：提供必需的 nonce 校验、挑战响应及密钥轮换，按安全域、用途、origin 和公钥指纹隔离。
- **原子防重放**：默认使用 MySQL 独立事务和唯一约束登记证明，也支持 Redis 原子防重放驱动；业务回滚不释放已登记的证明。
- **签发与刷新**：提供令牌签发、刷新时的证明验证辅助接口，刷新时校验原有密钥绑定。
- **Hyperf 中间件**：通过路由中间件保护资源，使用请求属性传递认证结果，隔离不同请求的身份信息。
- **认证资源控制**：提供 Redis 共享准入预算、来源限流和进程内验签并发限制，并在异常或协程取消时释放资源。
- **配置与诊断**：通过 `config/autoload/dpop.php` 管理协议策略、共享存储、准入预算和网关参数。提供启动检查、只读 `dpop:doctor` 和 HAProxy 配置生成命令。
- **mTLS 准入**：可选要求 HAProxy 校验客户端证书，Hyperf 在签发、刷新及受保护资源入口校验网关凭据后继续执行 DPoP。详见[能力和配置清单](docs/configuration.md)。
- **可扩展接口**：支持替换令牌绑定解析、公开 URI 解析、重放存储、准入预算、错误响应及认证指标采集。

## 组件结构

| 组件 | 功能 |
| --- | --- |
| `lowelllab/dpop-core` | 独立于框架的协议核心，负责证明验证、nonce、时间策略和防重放协调 |
| `lowelllab/hyperf-dpop` | Hyperf 适配层，提供依赖注入、中间件、存储驱动、配置及诊断命令 |

业务项目通过 Hyperf 扩展接入，协议核心作为依赖自动引入。

## 运行环境

- 适配包 0.3.0 支持 PHP 8.2.35 至 8.5.x、Hyperf 3.2 和 Linux Swoole 6.2.5。版本矩阵及验收流程见 [PHP 兼容说明](docs/compatibility.md)。
- OpenSSL 扩展；默认 MySQL 防重放驱动需要 PDO MySQL 扩展。
- 默认存储组合为 MySQL 防重放账本与 Redis 共享准入预算。

## 安装

```bash
composer require "lowelllab/hyperf-dpop:^0.3.0" "hyperf/redis:~3.2.0"
```

Composer 从默认 Packagist 安装适配包 v0.3.0，并自动引入协议核心 v0.2.0。`hyperf/redis` 为默认的共享认证预算所需，适配包会引入诊断和网关命令所需的 Hyperf 命令组件。 [使用示例](docs/usage-examples.md) · [发布与验收记录](docs/release-0.3.0.md)

在 `config/autoload/dpop.php` 配置公开地址、存储和网关。mTLS 默认关闭，可按部署需要启用。用 `dpop:gateway-config --output haproxy-dpop.next.cfg` 生成候选配置，再执行 `haproxy -c -f haproxy-dpop.next.cfg`。 [网关接入](docs/gateway.md)

## 使用边界

扩展负责 HTTP DPoP 证明验证。账号认证、令牌签发、会话撤销和业务权限由宿主应用实现，通信仍需使用 HTTPS。DPoP 证明不保护请求 body 和 query 的完整性。

当前支持 ES256。证书签发、轮换、吊销列表维护和宿主业务身份由部署方管理。mTLS 负责连接准入，令牌仍绑定 DPoP 公钥。

客户端 HTTP/2 需通过检查重复认证头的网关转发为 HTTP/1.1；Swoole 原生 HTTP/2 无法检查原始头，受保护接口返回 503。接入方式见[网关说明](docs/gateway.md)。

## 使用文档

- [使用示例](docs/usage-examples.md)：配置、令牌绑定、签发与刷新、路由保护及客户端请求。
- 生成测试证书和 CRL，开启 mTLS，验证 HAProxy 配置、证书准入和 DPoP 请求。 [mTLS 本地验收](docs/usage-examples.md#9-mtls-本地验收)
- [接入指南](docs/integration.md)
- [错误契约](docs/errors.md)
- [安全与部署说明](docs/security.md)

## 许可证

[MIT](LICENSE)
