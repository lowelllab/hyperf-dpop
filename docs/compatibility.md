# PHP 兼容与验证

兼容候选为 `lowelllab/dpop-core 0.2.0` 和 `lowelllab/hyperf-dpop 0.3.0`。两包声明 PHP `>=8.2.35 <8.6`，以下七个 PHP 版本已通过本地验收。当前公开版见 [发布记录](release-0.2.1.md)，详细结果见 [实施计划](../spec/feature_20261116_F001_php-runtime-compatibility/spec-plan.md#本地验证记录--2026-11-16)。

| PHP 分支 | 安全补丁验证点 | 最低正式版本验证点 |
| --- | --- | --- |
| 8.2 | 8.2.35 | 8.2.35 |
| 8.3 | 8.3.36 | 8.3.0 |
| 8.4 | 8.4.27 | 8.4.1 |
| 8.5 | 8.5.12 | 8.5.0 |

PHP 发布团队跳过了 8.4.0。生产使用各分支当前安全补丁。PHP 8.2 官方安全支持截至 2026 年 12 月 31 日，新部署优先使用 PHP 8.5。 [PHP 发布公告](https://news-web.php.net/php.announce/446) · [官方支持周期](https://www.php.net/supported-versions.php)

## 运行环境和安装

核心包使用 OpenSSL，无需 Hyperf。适配包要求 Hyperf 3.2、Linux Swoole 6.2.5 起的 6.2 系列，并随包安装诊断与网关命令。0.3.0 发布后，标准宿主执行以下命令：

```bash
composer require "lowelllab/hyperf-dpop:^0.3.0" "hyperf/redis:~3.2.0"
composer check-platform-reqs --no-dev
```

内置共享预算依赖 `hyperf/redis`。默认 MySQL 重放驱动要求 `pdo_mysql`，Redis 重放可按需启用。安装后按 [配置指南](configuration.md) 绑定宿主 resolver 和安全状态，按 [网关指南](gateway.md) 部署 HTTP/1.1 后端转发和网络隔离，再执行 `dpop:doctor`。

适配包要求 `swow/psr7-plus ^1.1.2`、`nikic/php-parser ^5.6.2` 和 Symfony Console 7.4。PSR-7 接口的 `getParsedBody()` 返回类型与 Hyperf 3.2 一致。PHP-Parser 和 Console 下限覆盖此次验收发现的 PHP 8.5 弃用问题。网关配置通过 `dpop:gateway-config --output` 写文件，启动日志使用相应的控制台流。 [PHP-Parser 变更记录](https://github.com/nikic/PHP-Parser/releases/tag/v5.6.2)

## 可复现验证

`docker/runtimes.json` 固定 PHP 镜像以及独立 PHPUnit PHAR。默认是 PHP 8.5.12。选择其他行时导出 `DPOP_EXPECTED_PHP`、`DPOP_PHP_IMAGE`、`DPOP_PHPUNIT_VERSION` 和 `DPOP_PHPUNIT_SHA256`，将 `DPOP_TEST_IMAGE` 设为对应的 `hyperf-dpop-lab:php<version>`。

```bash
docker compose build test
bash tools/verify-compatibility.sh workspace
bash tools/verify-compatibility.sh candidate
```

工作区 Composer 锁按 PHP 8.2.35 求解，PHPUnit 用各版本自带的 PHAR。独立消费者用真实 PHP 和空白应用锁解析稳定候选标签，分别覆盖默认稳定和最低稳定依赖。MySQL/Redis 重放、mTLS 两种状态、网关 HTTP/1.1 与 HTTP/2、依赖中断和恢复均由隔离测试验证。发布后使用 [双包发布指南](publishing.md) 中的公开安装流程。
