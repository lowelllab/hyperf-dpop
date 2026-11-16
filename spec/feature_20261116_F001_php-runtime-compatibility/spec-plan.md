# PHP 8.2.35 至 8.5.x 正式兼容实施计划

依据 [兼容设计](spec-design.md)。使用 Composer、Docker Compose、PHPUnit 和 GitHub Actions。

### Task 1 运行时与依赖

**涉及文件**

- `composer.json`、`composer.lock`、`phpstan.neon`
- `packages/*/composer.json`、`packages/hyperf-dpop/src/BootstrapValidator.php`、`packages/hyperf-dpop/src/ConfigProvider.php`
- `docker/Dockerfile`、`docker/runtimes.json`、`compose.yaml`、`tools/runtime.php`

**执行步骤:**

- [x] 统一源码下限、候选版本及适配运行依赖，提取分版本测试工具。
- [x] 配置 PHP/Swoole 镜像、准备各版本测试环境并重算基线锁。

**检查步骤:**

- [x] `docker compose run --rm --no-deps test composer validate-packages` 与 `composer check-platform-reqs --lock` 在 PHP 8.2.35 镜像成功。

### Task 2 全链路验证与消费

**涉及文件**

- `tools/consumer-smoke.php`、`tools/verify-package-exports.php`、`tools/verify-compatibility.sh`
- `examples/hyperf/config/autoload/dpop.php`、`tests/Support/Environment.php`、`tests/package-cli.php`、`compose.gateway.yaml`
- `.github/workflows/ci.yml`

**执行步骤:**

- [x] 将工作区现有回归和独立已安装包的宿主验收接入全版本运行入口。
- [x] 网关运行两个重放驱动与 mTLS 两种状态。CI 区分完整候选及已发布包消费。

**检查步骤:**

- [x] `bash -n tools/verify-compatibility.sh` 和 `docker compose -f compose.yaml -f compose.gateway.yaml config -q` 成功。
- [x] 工作区和独立候选均使用隔离服务通过。候选最低档使用 `bash tools/verify-compatibility.sh candidate lowest` 完成定稿复验。

### Task 3 文档与发布包同步

**执行步骤:**

- [x] 更新当前支持声明、安装、维护与发布说明。将发布源同步到工作区旁的两个发布仓。

**检查步骤:**

- [x] PHP 8.2.35 镜像运行 `php tools/verify-docs.php` 成功，核心 18 个及适配 37 个文件的 SHA-256 与本地发布仓一致。

### Task 4 全版本验收

**End-to-end verification:**

- [x] 使用 `docker/runtimes.json` 中的四个安全补丁和三个区间下界执行兼容验证。运行时失败检查 Task 1，独立宿主或网关失败检查 Task 2。

## 本地验证记录 · 2026-11-16

PHP 8.2.35、8.3.0、8.3.36、8.4.1、8.4.27、8.5.0、8.5.12 均使用 Linux Swoole 6.2.5 和 Redis 扩展 6.3.0。各工作区通过 159 项 PHPUnit 测试、302 次断言、PHPStan、29 项生产 CLI 用例、MySQL/Redis 并发、协程取消、HTTP 和 TLS。

七个默认稳定依赖组合通过独立宿主全链路验证。其已测版本为 swow/psr7-plus 1.1.2、Symfony Console 7.4.23、PHP-Parser 5.9.0，均满足定稿依赖下限。定稿后的最低稳定依赖在七个 PHP 版本重新创建候选标签和空白消费者并全部通过。每个独立宿主验证两种重放存储、mTLS 开关、HTTP/1.1 与 HTTP/2。每个重放驱动还验证断网时返回 503、恢复后同一 proof 只接受一次。核心包另外通过无 Swoole 和 Redis 扩展的独立安装及 49 项协议测试。

示例宿主为 MySQL 连接和 Redis 读取设置了有限超时。首次边界回归的 PHPStan 默认 128 MB 内存不足，改为 512 MB 后重跑通过。依赖下限和网关输出修正见 [兼容设计](spec-design.md#依赖下界验证)。本地日志在 `build/compatibility`。

协议核心 0.2.0 和适配包 0.3.0 在远端候选 CI 通过后按序发行。四个 PHP 安全补丁点均通过默认 Packagist 安装及回归。 [发布与验收记录](../../docs/release-0.3.0.md)
