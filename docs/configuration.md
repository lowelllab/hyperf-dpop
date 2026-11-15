# 包能力与配置

以下配置适用于 Hyperf 适配包 v0.2.1。宿主发布 [dpop.php 模板](../packages/hyperf-dpop/publish/dpop.php)后，将配置集中在 `config/autoload/dpop.php`。升级时可将新增键合并到已有文件中，原有平铺键继续生效。

| 能力 | 配置或接入点 |
| --- | --- |
| ES256 持钥证明、nonce 及时间窗 | `domain`、`nonce_key`、`previous_nonce_key`、`nonce_ttl`、`past_seconds`、`future_seconds`、`clock_skew`、`proof_max_bytes`。启用 DPoP 的入口始终验证 nonce。 |
| 公开请求地址 | `origin`、`internal_prefix`、`external_prefix`。默认解析器根据固定 HTTPS origin 和前缀构造待验证的 URI。 |
| 原子防重放 | `replay_driver` 选择 `mysql`（默认）或 `redis`。`replay_ttl` 必须大于 `past_seconds + future_seconds + 2 × clock_skew`。MySQL 使用 `mysql_dsn`、`mysql_user`、`mysql_password`、`mysql_table`、`mysql_connect_timeout`（默认 3 秒）。 |
| 共享预算与本地并发 | `global_limit`、`source_limit`、`budget_window`、`max_concurrency`、`trusted_proxies`。预算和 Redis 重放共用 `namespace` 与 `redis_pool`（默认 `default`）。连接、凭据和连接池超时由 Hyperf 的 `config/autoload/redis.php` 管理。 |
| 网关与 mTLS | `gateway_bind`、`gateway_cert`、`gateway_backend`、`mtls_enabled`、`mtls_client_ca`、`mtls_client_crl`、`mtls_trusted_proxies`。 |
| 资源保护、令牌签发与刷新 | 宿主将 `DpopMiddleware` 挂到受保护路由，在 token 入口调用 `TokenEndpoint`，通过 DI 接入已验证的 JWT 或会话绑定。 |
| 可替换组件与诊断 | 绑定解析、公开 URI 解析、重放存储、准入预算、错误响应及指标均可在宿主 DI 中替换。`dpop:doctor` 检查配置和依赖，`dpop:gateway-config` 输出 HAProxy 配置。 |

## 开启 mTLS

在现有 `dpop.php` 中设置以下字段。地址和路径按 HAProxy 所在的环境填写。

```php
'gateway_bind' => '0.0.0.0:443',
'gateway_cert' => '/etc/haproxy/tls/api.pem',
'gateway_backend' => '127.0.0.1:9501',
'mtls_enabled' => true,
'mtls_client_ca' => '/etc/haproxy/tls/client-ca.pem',
'mtls_client_crl' => '',
'mtls_trusted_proxies' => ['127.0.0.1'],
```

`mtls_enabled` 默认为 `false`。模板也接受 `DPOP_MTLS_ENABLED=true` 或 `false`，以及 `DPOP_MTLS_CLIENT_CA`、`DPOP_MTLS_CLIENT_CRL`、`DPOP_GATEWAY_BIND`、`DPOP_GATEWAY_CERT` 和 `DPOP_BACKEND_ADDR`。开启时必须在生成配置时提供客户端 CA 文件，并在应用配置中列出直接连接 Hyperf 的网关 IP。`mtls_client_crl` 可选，用于加载 PEM 格式的吊销列表。

已安装 `hyperf/command` 的宿主可生成候选配置，再用 HAProxy 检查。从适配包 0.2.1 起，`--output` 可直接将配置写入文件，宿主启动日志继续走原有输出。路径可相对当前目录，目标目录须已存在。成功时整文件替换，替换时保留权限，新文件遵循调用者的 `umask`。配置生成失败时保留原文件。

```bash
php bin/hyperf.php dpop:gateway-config --output haproxy-dpop.next.cfg && \
  haproxy -c -f haproxy-dpop.next.cfg
```

两条命令均成功后，按宿主的部署流程启用候选配置并重启应用 Worker。省略 `--output` 时命令继续打印配置，宿主的 stdout 日志也可能出现在同一输出流中。

此开关覆盖生成配置中的整个 HTTPS 监听入口，含 OPTIONS 和健康检查。HAProxy 在 TLS 握手时验证客户端证书，随后清除入站的 `X-DPoP-MTLS-Verified`，并仅为已验证的连接写入 `1`。Hyperf 在资源中间件及签发、刷新辅助器中校验直连网关 IP 和该标记。受信网关应独占到后端的访问，跨主机部署应保护代理到应用的链路。如果 CDN 或 Ingress 提前终止客户端 TLS，需要由该入口实际完成客户端证书验证并建立等价的受信转发契约。 [HAProxy 客户端证书认证](https://www.haproxy.com/documentation/haproxy-configuration-tutorials/security/authentication/client-certificate-authentication/) · [网关接入](gateway.md)

此模式要求客户端同时满足网关证书准入及现有 DPoP 校验。证书签发、更新、吊销和 CA 维护由部署方负责。访问令牌和刷新绑定仍使用 DPoP 的 `jkt`。
