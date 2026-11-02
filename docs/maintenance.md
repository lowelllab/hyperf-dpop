# 开发与发布维护

维护入口：[实施进度与规则记录](../spec/feature_20261102_F001_hyperf-dpop-extension/implementation-progress.md)。修改前阅读有效规则和未完成任务，收尾同步设计、进度、验证及遗留问题。

## 版本与依赖

当前双包为lowelllab/dpop-core及lowelllab/hyperf-dpop，在Composer path工作区按0.1.x-dev解析，尚未发布。PHP命名空间保持Acme\Dpop及Acme\HyperfDpop。使用[发布指南](publishing.md)准备两个独立包根目录，不将开发工作区提交为扩展包。

首版许可MIT；第三方依赖保持各自许可。更新依赖时重新执行协议边界、存储竞争、协程取消和HTTP端到端，不以composer约束满足替代兼容性结果。

## 自动化检查

在工作区执行：

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

全新临时数据库先通过`php tools/setup-example.php`创建隔离测试schema；HTTP端到端脚本会自行执行这一初始化。以上只用于此独立实验环境，不是生产迁移命令。

测试证据写入build目录，忽略vendor/build/runtime，不将合成私钥或完整凭据加入发布包。清理本实验服务用`docker compose down`；MySQL为tmpfs，关闭后测试数据丢失，下一轮需重新初始化。不会操作其他Compose项目。

## 检查边界

PHPStan级别6，仅不要求所有数组列出元素类型，不忽略协议逻辑错误。CI同时执行语法、单元/集成、MySQL/Redis跨进程、取消、生产CLI发布与缓存、Hyperf多Worker HTTP及直连TLS测试。

`composer check`内包含`tests/package-cli.php`，会在容器/tmp独立应用目录发布模板并检查生产缓存冷/热启动，不覆盖工作区示例配置。TLS测试仅将临时CA交给本次curl连接，不修改系统信任库。Node互操作向量由`node tools/generate-interop.mjs`生成，只有合成公钥、证明和测试token进入fixture；私钥不导出。常规CI直接消费固定向量，不依赖Node运行时。

CLI只读检查会比较安全账本数量；不要与写入同一测试数据库的其他测试脚本并行执行。HTTP/TLS脚本各自启动和关闭临时Hyperf服务，独立Compose服务最后使用`docker compose down`清理。

HTTP并发脚本报告的32请求延迟仅为本地烟测观测；不等于峰值容量、NAT公平性、攻击流量预算或生产SLO验收。后续依据目标硬件和负载补充容量测试，结果加入实施记录。

## 稳定版发行前

- 落实维护责任、漏洞渠道和发行渠道；Composer vendor已确定为lowelllab，PHP命名空间保持现有Acme。
- 完成独立安全审查及目标部署验收，明确已支持的运行时矩阵。
- 以真实TLS/代理、实际拓扑和故障切换验证配置契约。
- 审查两包导出内容、第三方许可、兼容变更和升级说明。
- 外部发布、Git提交或推送依照届时用户授权执行。2026-11-02已按用户提供的仓库完成私有仓库首次提交与推送；尚未发布Composer包、版本标签或Release。
