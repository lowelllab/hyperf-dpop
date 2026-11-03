# 安全政策

维护入口：GitHub账号与仓库所有者 **lowelllab**。当前正式安全修复基线为双包0.1.0；报告问题请附所用包版本和运行时。历史beta可用于复现，业务接入优先使用正式版。

## 私密报告

三个仓库已启用GitHub私密漏洞报告，请使用对应入口：

- [协议核心 dpop-core](https://github.com/lowelllab/dpop-core/security/advisories/new)
- [Hyperf适配 hyperf-dpop](https://github.com/lowelllab/hyperf-dpop-package/security/advisories/new)
- [开发主仓与跨包问题](https://github.com/lowelllab/hyperf-dpop/security/advisories/new)

提供受影响版本、PHP/Hyperf/Swoole及网关版本、脱敏部署路径、最小合成复现、实际/预期行为及影响。不要在公开Issue粘贴真实token、proof、nonce、私钥、用户数据或生产连接凭据。普通非安全缺陷可通过对应仓库Issues报告。

维护者确认影响后在受支持分支修复，以新版本标签及安全公告分发；不覆盖已发布标签。未公开前通过私密报告讨论复现与修复，避免提前公开可利用细节。

## 运行与验证边界

已测PHP8.5.12、Hyperf Framework3.2.0/DI3.2.2及Swoole6.2.4；适配包要求PHP ^8.5及Hyperf ~3.2.0。入口必须检查重复认证头、拒绝认证Trailer声明、剥离请求尾部，以HTTP/1.1转发并隔离后端。原生Swoole HTTP2受保护入口503；原生HTTP1异常尾部报文可能在PHP前等待。

正式版本标签表示可从默认稳定Composer渠道安装；不表示独立安全审计、目标生产拓扑、容量/SLO或HA/状态丢失/备份恢复已验收。宿主仍负责可信令牌绑定、JWT/会话与撤销、账户授权和刷新锁/CAS；DPoP不替代HTTPS或body/query完整性。
