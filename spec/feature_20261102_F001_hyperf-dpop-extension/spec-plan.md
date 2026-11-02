# Hyperf DPoP 扩展执行计划

目标：完成独立Composer双包、严格HTTP认证、MySQL/Redis适配、Hyperf示例和可重复验证，交付未发布开发版。

执行状态：本轮6项任务已完成，证据见实施记录VAL-002至VAL-007。此完成状态仅指下列本地开发交付，不代表设计P4全部生产条件、独立审计或P5稳定发行已完成。

设计：[spec-design.md](spec-design.md)；审查：[review-20261102.md](review-20261102.md)；进度：[implementation-progress.md](implementation-progress.md)。

授权：用户已明确要求审查后实施，并允许直接修订；不重复确认。计划不包含外部发布或Git操作。下列docker compose命令均在hyperf-dpop目录执行，使用独立项目名，不操作其他项目服务。

### Task 1：审查与独立运行环境（P0）

**执行步骤:**
- [x] 深度审核候选库及方案，记录发现，修订设计与授权。
- [x] 建立双包Composer工作区、PHP8.5/Swoole工具镜像及独立MySQL/Redis环境。
- [x] 安装锁定依赖并记录实际运行时，完成JOSE选型。

**检查步骤:**
- [x] `docker compose run --rm test php tools/runtime.php`：PHP8.5、Swoole6.2及Hyperf3.2实际可用。
- [x] `docker compose run --rm test composer validate --strict`：根包及两个子包元信息有效。

### Task 2：通用协议核心（P1）

**涉及文件:** packages/dpop-core/src、tests/CoreTest.php、tests/Support。

**执行步骤:**
- [x] 实现严格JSON/base64url、公钥指纹、URI、配置和协议错误。
- [x] 实现JOSE验签、nonce、可信绑定、签发/刷新/资源认证协调及PSR时钟。

**检查步骤:**
- [x] `docker compose run --rm test vendor/bin/phpunit tests/CoreTest.php`：正面、负面、大小写/编码、时钟、nonce、错误密钥、ath及刷新边界通过。

### Task 3：存储与认证预算（P3先行依赖）

**涉及文件:** packages/hyperf-dpop/src/Store、src/Admission、publish、tests/StorageTest.php、tests/concurrency.php。

**执行步骤:**
- [x] 实现专用PDO MySQL原子占位、独立提交及有界清理，提供显式DDL。
- [x] 实现Redis原子重放及共享预算，存储时钟复核与错误处理。

**检查步骤:**
- [x] `docker compose run --rm test vendor/bin/phpunit tests/StorageTest.php`：真实MySQL/Redis原子性、过期、故障和业务回滚隔离通过。
- [x] `docker compose run --rm test php tests/concurrency.php`：两个驱动跨进程并发最多一个接受。

### Task 4：Hyperf集成（P2）

**涉及文件:** packages/hyperf-dpop/src、publish、tests/IntegrationTest.php、examples/hyperf。

**执行步骤:**
- [x] 实现DI工厂、ConfigProvider、严格资源中间件、错误响应、公开URI及代理来源策略。
- [x] 实现签发/刷新辅助集成、只读doctor、配置发布、脱敏事件接口和独立示例。

**检查步骤:**
- [x] `docker compose run --rm test vendor/bin/phpunit tests/IntegrationTest.php`：真实Hyperf请求对象、头部、配置、上下文及业务异常传播通过。
- [x] `docker compose run --rm test php examples/hyperf/bin/hyperf.php dpop:doctor`：安全配置和依赖诊断成功，不产生认证副作用。

### Task 5：端到端与发布准备（P4/P5准备）

**执行步骤:**
- [x] 提供接入、错误、存储保证、代理/CORS及维护文档；补齐许可、CI和版本信息。
- [x] 执行静态分析、依赖审计及真实HTTP多Worker/协程、容量观测，修复发现并记录证据。

**检查步骤:**
- [x] `docker compose run --rm test composer check`：全部目标测试、语法/静态检查通过。
- [x] `docker compose run --rm test composer audit --locked`：报告依赖审计结果，不隐瞒未解决问题。

### Task 6：Hyperf DPoP Acceptance

**Prerequisites:** `docker compose up -d --wait mysql redis`；独立环境仅监听容器内部，测试使用合成用户和临时密钥。

**End-to-end verification:**
- [x] `docker compose run --rm test php tests/http-e2e.php`：启动真实Hyperf多Worker服务，签发nonce挑战、绑定签发、资源访问及刷新成功；错误方法/URI/nonce/ath、重放、降级和撤销失败；跨协程主体隔离。失败回查Task2至4。
- [x] `docker compose run --rm test php tools/consumer-smoke.php`：独立消费项目和新PHP进程安装加载正常，开发稳定性显式允许，不借工作区dev依赖。失败回查Task1、4。
- [x] `docker compose run --rm test php tools/verify-docs.php`：内部文档链接有效，状态与实施证据同步。失败回查Task5。
- [x] 更新阶段、规则、任务与遗留问题；明确仅完成本地开发验证，未发布稳定版及未完成独立审计。
