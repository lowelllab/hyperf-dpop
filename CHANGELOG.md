# Changelog

## 0.3.0 / Core 0.2.0 · 2026-11-16

- 声明 PHP 8.2.35 至 8.5.x，按各分支安全补丁和最低正式版本验证。
- Hyperf 适配声明 Swoole 6.2.5 起的 6.2 系列及 Hyperf CLI 依赖。
- 新增隔离的稳定候选及 Packagist 消费验证，覆盖 MySQL/Redis 重放和 mTLS。 [PHP 兼容说明](docs/compatibility.md)

## 0.2.1 · 2026-11-15

- `dpop:gateway-config --output` 直接写入 HAProxy 配置文件，适用于保留默认启动日志的 Hyperf 宿主。
- 文件成功生成后整体替换，保留目标文件权限；生成失败时保留原文件。

## 0.2.0 · 2026-11-15

- Hyperf 适配包新增默认关闭的 mTLS 开关。开启后，HAProxy 验证客户端证书，Hyperf 核验受信网关的验证标记，再执行现有 DPoP 流程。
- 从 `config/autoload/dpop.php` 生成 HAProxy 配置，旧配置键继续生效。新增 Redis 池选择和 MySQL 连接超时，详见[包能力与配置](docs/configuration.md)。
- 协议核心沿用 0.1.0。

## 0.1.0 · 2026-11-03

- 首次正式双包发行，协议、适配PHP实现与发布模板沿用已验收的0.1.0-beta.1，无业务行为变更。
- 适配依赖核心改为`^0.1.0`，默认稳定Composer渠道仅要求安装适配包即可自动引入核心。
- 两包补充SECURITY.md及Composer支持/私密漏洞报告入口；三个GitHub仓库均已启用私密报告。
- 独立本地beta验收证据保留；正式包另验证默认稳定性下的干净公开安装与独立宿主。
- 网关/后端隔离要求和原生Swoole限制保留；独立安全审计、生产拓扑、容量及HA恢复仍未验收。

## 0.1.0-beta.1 · 2026-11-03

首次双包测试版；Git 标签以两个独立发布仓库为准。生产部署验收和独立安全审计尚未完成。

- 修复标准Hyperf请求代理跳过原始头检查；读取不改变协程请求上下文。
- 拒绝认证尾部字段及原始/PSR凭据不一致；网关拒绝认证Trailer声明并剥离HTTP/1及HTTP/2请求尾部字段。

- 修复Swoole HTTP/1重复Authorization被覆盖后的漏检，补查原始头；无法检查唯一性的原生HTTP/2受保护请求返回503。
- 增加显式发布的HAProxy入口模板，客户端HTTP/1和HTTP/2重复认证头统一拒绝后，以HTTP/1.1转发至后端。

- 规范URI/方法比较、原子防重放、时钟和错误契约。
- 增加通用DPoP核心与Hyperf适配双包，包含配置、接口、中间件、nonce和预算。
- 增加MySQL权威及Redis原子重放驱动、只读doctor与显式DDL。
- 增加合成数据库会话/JWT示例和独立PHP8.5/Swoole/Redis/MySQL验证环境。
- 增加自动化协议、集成、跨进程竞争、协程取消与真实Hyperf HTTP验证。
- 整体复审加固schema精确校验、存储时间复核、JWK元数据类型及MySQL提交不确定时的异常清理。
- 增加公开URI解析接口、只读依赖探测接口，修正自定义依赖健康误报和限流重试时间。
- 修正示例JWT依赖故障分类，增加Node签名互操作、生产CLI发布/缓存与真实TLS验证。
