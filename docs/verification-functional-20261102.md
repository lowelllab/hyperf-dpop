# DPoP 整体功能验证记录 · 2026-11-02

当前快照：未发布0.1开发版、设计0.4。[首轮记录](verification-20261102.md)保留历史结果，不作为本轮代码未复测的证明。本轮按用户授权直接审核、维护和验证，发现及修复详情见[整体审查报告](../spec/feature_20261102_F001_hyperf-dpop-extension/review-functional-20261102.md)。

## 1. 环境与结果

运行于本项目独立Linux Docker实验环境：PHP8.5.12、Swoole6.2.4、Hyperf Framework3.2.0、JOSE4.2.4、Redis扩展6.3.0、MySQL8.4、Redis7.4系列；Composer2.11.1、PHPUnit13.4.5。可选发布工具hyperf/devtool3.2.0本轮已实际使用。没有复用原业务项目代码、真实账号或服务器配置。

| 层级 | 实际结果 | 证据/边界 |
| --- | --- | --- |
| 原有基线 | 77测试/126断言通过 | review2-baseline.log；新增覆盖仍发现问题，不以旧绿灯替代复审 |
| 最终单元/集成/真实存储 | 108测试/169断言通过 | 新增31项；review2-final-check.log |
| 静态与语法 | PHPStan级别6无错误，79个PHP文件语法通过 | 数组元素类型完整性为现有明确例外；未静态分析vendor |
| 跨进程唯一性 | MySQL与Redis各12进程，均1接受/11拒绝 | composer check内tests/concurrency.php；共享同一权威服务 |
| 事务与故障 | 宿主回滚不释放proof；实际断开本测试MySQL连接安全拒绝；提交后注入确认/清理失败仍拒绝再登记 | 注入不能等同物理主库切换或灾难恢复 |
| 时间与schema | 两驱动exp过期等待及未来nbf拒绝；iat精确边界；5类危险DDL和非严格SQL拒绝写入 | AuditRegressionTest.php |
| 协程取消 | 不执行业务、释放认证预算 | tests/coroutine.php |
| 真实HTTP | 27场景通过；32个并发请求实际使用2个Worker、主体隔离 | review2-http.log、http-e2e-result.json |
| 真实直连TLS | 12场景通过；证书/主机名均校验，坏CA/主机名拒绝；编码路径保留；12路同proof仅1接受 | review2-tls-result.json；专属临时CA，无系统信任库修改 |
| 生产CLI | 15场景通过；缓存冷/热、危险配置/缺resolver、自定义依赖可探测性、DDL及配置发布/保护/force | review2-cli-result.json；安全账本写入0，不在真实宿主发布 |
| 跨语言互操作 | Node原生P-256/P1363签名及thumbprint由PHP校验通过 | InteropTest.php及固定合成向量；不宣称所有浏览器SDK兼容 |
| 独立消费安装 | 仅要求扩展的临时Composer项目、新PHP进程正常加载，不借根dev依赖 | review2-consumer.log；path开发包需显式允许dev稳定性 |
| Composer元信息 | 根及双包strict校验通过 | review2-validate.log；无Git版本时的根版本推断提示不代表已发布1.0 |
| 依赖公告 | advisories、abandoned均空，退出0 | review2-audit.log；仅覆盖查询时已知公告 |
| 文档与诊断 | 15份Markdown编码/链接/围栏检查通过，只读doctor成功 | review2-docs.log、review2-doctor.log；规则编号001至060连续 |

本轮最后32请求样本：耗时0.519秒，P50 43.08ms、P95 504.53ms，包含主动协程等待。仅为本地并发烟测观测，不是生产容量/SLO验收。

## 2. 可重跑命令

已安装依赖的实验目录中按顺序执行：

```bash
docker compose up -d --wait mysql redis
docker compose run --rm test php tools/runtime.php
docker compose run --rm test composer validate-packages
docker compose run --rm test composer check
docker compose run --rm test php tests/http-e2e.php
docker compose run --rm test php tests/tls-e2e.php
docker compose run --rm test php tools/consumer-smoke.php
docker compose run --rm test php examples/hyperf/bin/hyperf.php dpop:doctor
docker compose run --rm test composer audit --locked --format=json
docker compose run --rm test php tools/verify-docs.php
docker compose down
```

全新目录先按README构建镜像并composer install。`composer check`含15场景CLI测试，不必再重复执行package-cli。该测试比较安全账本数量，不与其他认证写入测试并行。MySQL为tmpfs，环境关闭后数据丢失；测试自行创建隔离表，包安装和doctor不执行DDL。

## 3. 快照与交付边界

当前composer.lock SHA256：`29e07769254bb446daabda21c24425dac1b92532c22bcf16d5f062b67848977e`。48个包文件和锁文件共49项摘要保存在忽略的`build/source-manifest.json`，用于本地变更定位，不是发行签名。最终文档检查与环境收尾证据见[实施记录](../spec/feature_20261102_F001_hyperf-dpop-extension/implementation-progress.md)LOG-009。独立实验MySQL/Redis及网络已关闭移除，镜像和Composer缓存保留。

审查未留待修复的已确认功能问题。仍未完成：正式代理拓扑及原始重复认证头处理验收、真实数据库/Redis故障切换和备份恢复、目标恶意流量/NAT/连接池容量测试、独立安全审计、远端CI、正式vendor与稳定发行。已提供本地TLS和故障注入证据，不能扩展为上述生产条件已通过。

未提交、推送或发布包；没有操作旧项目测试服务器。所有私钥和临时凭据只用于隔离fixture，不进入组件发行内容。
