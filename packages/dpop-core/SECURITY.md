# 安全政策

维护入口：GitHub账号与仓库所有者 **lowelllab**。本包的安全修复基线为 0.2.0；报告问题请附所用包版本和运行时。历史beta可用于复现，业务接入优先使用正式版。

## 私密报告

三个仓库已启用GitHub私密漏洞报告，请使用对应入口：

- [协议核心 dpop-core](https://github.com/lowelllab/dpop-core/security/advisories/new)
- [Hyperf适配 hyperf-dpop](https://github.com/lowelllab/hyperf-dpop-package/security/advisories/new)
- [开发主仓与跨包问题](https://github.com/lowelllab/hyperf-dpop/security/advisories/new)

提供受影响版本、PHP/Hyperf/Swoole及网关版本、脱敏部署路径、最小合成复现、实际/预期行为及影响。不要在公开Issue粘贴真实token、proof、nonce、私钥、用户数据或生产连接凭据。普通非安全缺陷可通过对应仓库Issues报告。

维护者确认影响后在受支持分支修复，以新版本标签及安全公告分发；不覆盖已发布标签。未公开前通过私密报告讨论复现与修复，避免提前公开可利用细节。

## 运行与验证边界

协议核心支持 PHP 8.2.35 至 8.5.x，依赖 OpenSSL。接入 HTTP 服务时由宿主建立可信请求上下文，并配置满足部署边界的重放保护。 [支持范围](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/compatibility.md)

宿主负责可信令牌绑定、JWT 和会话撤销、账户授权、刷新锁或 CAS，以及 HTTPS 和请求完整性。独立安全审计、容量和高可用性需要在各自的部署环境验收。
