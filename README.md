# Hyperf DPoP 扩展（开发版）

为 Hyperf 项目提供可通过 Composer 接入的 DPoP（RFC 9449）服务端认证组件。

当前状态：**方案深度审查完成，已实现未发布的0.1开发版**。包含通用核心、Hyperf适配、真实存储驱动、独立示例和自动化验证。代码已首次提交并推送至私有仓库 [lowelllab/hyperf-dpop](https://github.com/lowelllab/hyperf-dpop)，主分支为`main`。正式vendor仍待确定，尚未完成独立安全审计或公共稳定发行。

已追加整体功能复审与修复，当前结论见[整体功能验证记录](docs/verification-functional-20261102.md)及[问题审查报告](spec/feature_20261102_F001_hyperf-dpop-extension/review-functional-20261102.md)。首轮验证保留为历史快照。

## 文档入口

- [完整设计方案](spec/feature_20261102_F001_hyperf-dpop-extension/spec-design.md)：目标、选型、包结构、扩展接口、认证流程、配置、安全约束、实施阶段和验收标准。
- [实施进度与规则记录](spec/feature_20261102_F001_hyperf-dpop-extension/implementation-progress.md)：当前进度、规则登记、任务、决策、验证证据及遗留问题。
- [方案审查与修订](spec/feature_20261102_F001_hyperf-dpop-extension/review-20261102.md)
- [本地验证结果](docs/verification-20261102.md)
- [执行计划](spec/feature_20261102_F001_hyperf-dpop-extension/spec-plan.md)
- [接入指南](docs/integration.md)、[错误契约](docs/errors.md)、[安全保证](docs/security.md)、[维护与发布](docs/maintenance.md)

后续实施或恢复工作前，先阅读实施记录和对应设计章节；形成进展、规则变化或验证结论后同步记录。涉及技术契约调整时，同时更新设计方案并保留变更原因。

## 方案摘要

- 采用同仓库维护的两个独立 Composer 包：通用协议核心与 Hyperf 适配扩展；业务项目安装 Hyperf 扩展时自动引入核心包。
- 已实际运行PHP 8.5.12、Hyperf Framework 3.2.0、Swoole 6.2.4；具体验证证据和限制见实施记录。
- 首版覆盖 HTTP 证明验证、nonce 挑战、令牌密钥绑定、防重放及 Hyperf 中间件集成。
- 同时允许 JWT 和不透明令牌接入，通过可信绑定解析接口集成各项目现有认证体系。
- WebSocket 自定义持钥认证、浏览器和 Electron SDK、mTLS 证书管理均不纳入首版。
- 候选库审查发现完整URI大小写折叠和非原子缓存防重放，最终采用`web-token/jwt-library 4.2.4`执行密码学验签，自有核心协调严格协议规则，不自行实现签名算法。

包名、命名空间及安装命令中的 `acme` 为占位符，正式发布前需确定归属。本文所称“标准扩展”指遵循 RFC 和 Hyperf 组件机制，不代表 Hyperf 官方组件或已通过安全认证。

## 本地验证

仅启动本目录独立实验环境，无宿主公开端口，不连接原项目服务。

```bash
docker compose build test
docker compose up -d --wait mysql redis
docker compose run --rm test composer install --no-interaction
docker compose run --rm test php tools/runtime.php
docker compose run --rm test composer validate-packages
docker compose run --rm test php tools/consumer-smoke.php
docker compose run --rm test composer check
docker compose run --rm test php tests/http-e2e.php
docker compose run --rm test php tests/tls-e2e.php
docker compose run --rm test php examples/hyperf/bin/hyperf.php dpop:doctor
docker compose run --rm test composer audit --locked
docker compose run --rm test php tools/verify-docs.php
```

端到端脚本使用合成用户和临时密钥，包含JWT/数据库会话、nonce、刷新、重放、降级、撤销及多Worker主体隔离。HTTP脚本模拟前置TLS终止；独立TLS脚本使用临时CA、证书和主机名校验测试直连HTTPS及编码路径，不修改系统信任库。示例不是生产登录服务。

`composer check`还覆盖生产缓存冷/热启动、配置及DDL发布、自定义依赖只读探测；Node原生ES256固定向量验证跨语言签名互操作。全部测试详情及未完成的生产代理、容量与独立审计事项见实施记录。

MySQL数据使用tmpfs，`docker compose down`后丢失；下次测试需要重新初始化。构建日志及脱敏端到端结果保存在`build/`，不随组件发布。
