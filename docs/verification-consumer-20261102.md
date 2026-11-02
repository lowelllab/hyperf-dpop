# 独立 Hyperf 消费项目深度测试 · 2026-11-02

结论：当前扩展在官方 Hyperf 骨架的独立消费项目中完成四组真实 HTTPS 接入检查，每组 85 项通过，另有两项原始重复认证头的部署限制。没有修改扩展 PHP 源码。生产部署仍须处理重复头、依赖超时及既有发行验收事项，不能将本轮结果解读为独立安全认证。

## 1. 项目与环境

- 使用 `composer create-project hyperf/hyperf-skeleton /consumer '~3.2.0' --no-install --no-scripts --no-interaction` 创建官方 3.2.0 骨架，保存在同级目录 `hyperf-dpop-consumer`。
- 独立 composer.json / composer.lock / vendor；仅显式要求扩展，核心通过传递依赖引入。两个 path 包使用 `symlink=false` 安装，48 个文件与扩展源目录 SHA256 全部一致。
- 不加载本工作区 vendor、`DpopTest` 或 `DpopExample`。宿主自行提供会话表、JWT 验证、租户绑定解析、刷新行锁、路由和异常处理。
- PHP 8.5.12、Swoole 6.2.4、Hyperf Framework 3.2.0、DI 3.2.2、Engine 2.15.0、JOSE 4.2.4。消费者独立解析依赖，没有复用工作区锁文件。
- 独立 Compose 项目 `hyperf-dpop-consumer-test`，MySQL 8.4 / Redis 7.4，不映射宿主端口。HTTPS 监听容器 loopback 9543，临时 CA 与主机名均由 curl 校验，不修改系统信任。
- 包源快照来自提交 `7fddc37`；完整结果、包文件摘要及两个锁文件摘要见[结构化证据](consumer-acceptance-20261102.json)。

## 2. 实际结果

| 组别 | 结果 | 范围与边界 |
| --- | --- | --- |
| MySQL 防重放，开发依赖安装 | 85 项通过，2 项部署限制 | 完整 HTTPS 主流程、负例、宿主集成与并发 |
| Redis 防重放，开发依赖安装 | 85 项通过，2 项部署限制 | 同一组场景，未扩展为持久恢复保证 |
| MySQL，无开发依赖，生产缓存冷启动 | 85 项通过，2 项部署限制 | 移除 55 个开发依赖，保留原开发缓存快照后从空缓存启动 |
| MySQL，无开发依赖，生产缓存热启动 | 85 项通过，2 项部署限制 | 使用上一组生成的缓存重启 |
| 独立宿主 CLI | 8 项通过 | doctor 冷/热缓存、危险配置/缺 resolver 拒绝、DDL 发布与配置保护；认证账本写入 0 |
| 来源 / 全局准入预算 | 8 + 7 项通过 | 跨 Worker 共享、跨认证类别共享、无信任 XFF 不绕过、Retry-After 在窗口内、OPTIONS 不受耗尽预算影响 |
| 真实依赖中断与恢复 | 11 个不同断言通过 | Redis 实际停止、MySQL 脱离网络，503；恢复后失败 proof 可重试、成功后再次重放被拒绝 |
| 安装与静态检查 | 通过 | Composer strict 校验、48 文件摘要、39 个宿主 PHP 文件语法、汇总 PowerShell 脚本语法 |
| 依赖公告审计 | 通过 | 消费项目 `composer audit --locked --format=json` 退出 0，advisories / abandoned 均空；只覆盖查询时已知公告 |

四组共 340 次通过断言，是同一套场景在四种运行条件下重复执行，不能表述为 340 个不同场景。原工作区 108 项 PHPUnit 等历史结果没有在本轮重新执行；扩展源码未变，本轮验证的是全新宿主接入。

每组均覆盖：原生 OpenSSL 客户端 ES256 签名及公钥指纹、opaque/JWT、nonce 挑战及用途隔离、方法/URI/编码/时间/ath、签名篡改、重复 JSON、JWK 限制、租户绑定、同钥刷新、旧令牌失效、撤销、宿主回滚/异常/403、指标回调异常隔离。

每组 64 个请求实际经过两个 Worker，主体与租户结果正确；24 路完全相同 proof 为 1 接受 / 23 拒绝；8 路同 refresh、不同 proof 的竞争为 1 成功 / 7 invalid_grant。刷新原子性由此测试宿主的行锁实现，不能据此宣称扩展代替宿主完成刷新事务。

## 3. 两项部署发现

### 原始重复头会在 Swoole 入口丢失

实测同时发送两个 Authorization：有效值在前、无效值在后返回 401；顺序反转返回 200。DPoP 头先无效、后有效也返回 200。运行时只向应用暴露最后一个值，扩展不能恢复已丢失的重复信息。这两项在报告中标记 `LIMITATION`，没有算作通过。

这具体复现了既有 RULE-048 的边界：正式网关必须在折叠前拒绝原始重复 Authorization / DPoP，并用实际拓扑验收。本轮没有搭建或声称验证了这样的生产网关。

### Redis 连接和读取必须配置有限超时

最初消费者 Redis 配置只设连接池参数，未设顶层 `timeout` / `read_timeout`；Hyperf Redis 默认值均为 0.0。实际停止 Redis 后，HTTP 客户端 8 秒超时，没有得到预期 503。恢复后再次提交同 proof 返回重放错误，说明客户端超时不能证明原请求已经停止。

在消费者补充 `timeout=0.5`、`read_timeout=0.5` 后重新执行：Redis 停止约 5517ms 返回 503；恢复后同 proof 首次重试成功、再次请求被拒绝。MySQL 断网约 3012ms 返回 503，恢复后同样行为。Redis 总耗时包含 DNS / 重连等因素，不能把单次套接字超时当成全链路时限。

这是宿主接入配置与超时文档缺口，未改动扩展验签、绑定或防重放源码。已补充接入说明；HTTP 客户端断开、显式协程取消和业务幂等仍须分别处理。真实主从切换、状态丢失及备份恢复仍未验收。

## 4. 复跑与交付

在同级 `hyperf-dpop-consumer` 目录执行 `./acceptance/retest.ps1`。各底层命令本轮已实际执行，汇总脚本另做语法检查，没有额外宣称整段脚本已从头复跑。脚本结束关闭自身 Compose 服务；MySQL 使用 tmpfs，重新测试须执行初始化。

项目的 `acceptance/` 保留独立客户端、场景脚本、CLI、限流、故障客户端和复跑入口。原始脱敏结果在消费者 `evidence/`；结构化汇总随本报告保存。忽略目录 `build/consumer-acceptance-project.zip` 为本地项目源码及消费者锁文件归档，排除 vendor、runtime、.env 和原始 evidence，不含随机私钥或真实账号凭据。

本轮没有公开 GitHub 仓库、提交 Packagist、创建版本标签或发布 Composer 包。正式包名、双包发布结构、生产网关、容量、故障切换/恢复、独立审计及远端 CI 结论继续独立跟踪。
