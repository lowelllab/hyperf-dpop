# 本地开发版验证记录 · 2026-11-02

范围：未发布0.1开发版；设计0.3。只有本文件所列实际环境获得本轮验证，不声称所有满足Composer约束的组合均支持。

| 项目 | 实际结果 |
| --- | --- |
| 运行时 | Linux Docker，PHP8.5.12、Swoole6.2.4、Hyperf Framework3.2.0、Redis扩展6.3.0、JOSE4.2.4 |
| 包安装 | Composer2.11.1工作区安装、根及双包strict元信息校验通过 |
| 核心/集成/真实存储 | PHPUnit13.4.5：77项测试，126个断言，通过 |
| 静态分析 | PHPStan级别6无错误；数组元素类型完整性另列例外 |
| 原子重放 | MySQL和Redis各12进程竞争：1个接受，11个拒绝 |
| 事务隔离 | 业务回滚不释放proof；拒绝借入事务不回滚宿主事务；缺唯一约束的schema被拒绝 |
| 取消处理 | Swoole取消认证不执行业务，认证槽释放 |
| 真实HTTP | 两个Hyperf Worker，27个场景通过；32并发请求主体隔离 |
| 独立消费 | 临时Composer项目只要求扩展，新PHP进程加载正常，不依赖工作区dev自动加载 |
| 依赖审计 | composer audit --locked：advisories为空，abandoned为空；仅为查询时已知公告 |
| 最终文档与诊断 | 13份Markdown链接/编码/围栏及68个PHP语法检查通过，只读doctor成功 |

实际命令：

```bash
docker compose run --rm test php tools/runtime.php
docker compose run --rm test composer validate-packages
docker compose run --rm test composer check
docker compose run --rm test php tests/http-e2e.php
docker compose run --rm test php tools/consumer-smoke.php
docker compose run --rm test composer audit --locked --format=json
```

证据位于本工作区忽略目录：`build/check.log`、`build/http-e2e-result.json`、`build/http-e2e-run.log`、`build/composer-validate.log`、`build/composer-audit.json`、`build/consumer-install.log`。此文档保存不含凭据的结论摘要，测试日志和临时私钥不加入发行包。

最后一次32请求样本：耗时0.612秒，P50为45.19ms，P95为596.82ms，实际经过两个Worker。这是含故意协程等待的本地烟测，不代表生产吞吐或SLO认证。

没有执行：正式TLS/反向代理拓扑验收、真实Redis故障切换/数据库备份恢复演练、大规模恶意流量/目标NAT容量验收、独立安全审计、远端CI和公开发行。依赖失效分支使用受控测试替身，不能据此称为实际灾难恢复验证。

本轮未修改、提交或部署原后端/前端项目。独立实验MySQL使用tmpfs，不作为业务数据库保存；关闭实验后重跑测试会重新初始化。
