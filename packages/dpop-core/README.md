# DPoP Core

`0.1.0` 正式版，Composer包名`lowelllab/dpop-core`。PHP ^8.5，使用web-token/jwt-library执行ES256验签；PHP命名空间为`Acme\Dpop`。

提供严格JSON/base64url、公钥指纹、RFC3986 URI规范化、必需nonce、时间策略和认证协调。只支持HTTP DPoP的ES256配置档，不包含账号认证或业务授权。

入口为`Acme\Dpop\Authenticator`：

- `issuance(proof, method, uri, refreshJkt?)`验证token入口；refresh时传入可信原绑定。
- `resource(proof, method, uri, access, TokenBinding)`验证受保护资源。
- 验证通过后通过必需ReplayStoreInterface原子登记，业务失败不能释放登记。

注入不可变SecurityPolicy、ProofVerifier、NonceManagerInterface、共享ReplayStoreInterface、PSR-20 ClockInterface及环境安全域。没有生产用内存/Noop重放实现。

信任边界：令牌的有效状态和绑定必须来自宿主已验证的JWT/会话/introspection。私钥不进入服务端。body/query不受proof签名保护，HTTPS和业务权限仍然必需。

错误只携带协议类别及必要挑战，不保留完整proof/token作为异常原因。正式使用前阅读工作区接入、安全和维护文档；当前未完成独立安全审计。

完整文档：[使用示例](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/usage-examples.md)、[接入指南](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/integration.md)、[安全说明](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/security.md)。

私密漏洞报告与版本维护见[安全政策](SECURITY.md)。
