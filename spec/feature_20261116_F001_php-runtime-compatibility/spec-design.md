# PHP 8.2.35 至 8.5.x 正式兼容

## 目标

核心包和 Hyperf 适配包声明 `>=8.2.35 <8.6`。两个包共用源码和测试。各分支首次正式发行的验收下界为 PHP 8.2.35、8.3.0、8.4.1、8.5.0。PHP 发布团队跳过了 8.4.0 正式发布。生产部署使用各分支的安全补丁。 [PHP 发布公告](https://news-web.php.net/php.announce/446)

核心包使用 OpenSSL，可在无 Hyperf、Swoole、Redis 的环境安装。适配层正式验证 Hyperf 3.2 与 Linux Swoole 6.2，拟将 6.2.5 设为最低版本。默认 MySQL 重放与 Redis 准入组合、Redis 重放及 mTLS 模式共用现有认证契约。

## 设计

- 主仓生成一份面向 PHP 8.2.35 的 Composer 锁。PHPUnit 使用独立、固定版本及摘要的 PHAR。PHP 8.2 使用 11.5、8.3 使用 12.5、8.4 和 8.5 使用 13.4。PHPStan 检查完整 PHP 范围。
- 适配包声明 Swoole 和 Hyperf 命令组件。标准安装显式带上默认共享预算需要的 `hyperf/redis`。PDO MySQL 由选定驱动校验。启动校验运行时能力，连通性由现有只读诊断检查。
- 当前安全补丁与区间下界均以独立容器运行。每个 PHP 镜像独立构建 Swoole 和 Redis 扩展。测试工作区、Composer 缓存和服务状态按运行隔离。
- 从独立应用目录消费本地稳定候选标签或者 Packagist 指定版本。安装生产依赖，使用该安装中的配置、DDL、命令及实际认证请求。预发布分别验证默认稳定解析与最低稳定依赖。发布后复核 Packagist 提交、文件和功能。
- 候选版本为核心 0.2.0、适配 0.3.0，保留既有 Composer 包名及 PHP 命名空间。维护顺序先核心后适配。当前工作交付本地实现及发布验证入口，远端发布由发布流程独立执行。

## 实施前复审

1. 既有四版本实测使用 Swoole 6.2.4 与同一锁。Swoole 6.2.5 修改了 HTTP 尾部、边界及 HTTP/2 行为。候选必须重新跑真实协议、取消和存储验证。原生 HTTP/2 仍无原始请求数据，网关负责凭据检查、剥离尾部及向隔离后端转发 HTTP/1.1。
2. Composer 锁与 `config.platform` 属于宿主，独立消费者按真实 PHP 求解。最低锁用于源码回归，每个消费者生成自己的锁。PHPUnit 13.4.5 的最低 PHP 是 8.4.1，与 8.4 分支首次正式发行相同。
3. 现有消费验证仅实例化类，且示例配置和测试 DDL 从开发目录读取。真实消费必须从已安装包定位模板。验证产物使用独立目录，默认与最低解析各自安装，认证测试使用真实的安装结果。
4. 现有网关 E2E 示例默认使用 MySQL 重放。同一 PHP 补丁上要覆盖 MySQL/Redis 与 mTLS 开关的交叉组合。会话数据仍使用宿主示例 MySQL，与重放驱动职责分离。
5. 支持状态以实测证据为准。版本下界、网关模式、远端 CI、独立稳定标签、目标生产入口分别记录是否通过。历史发布记录保留已验证的原版本。

## 依赖下界验证

Hyperf 的最低解析可得到 `swow/psr7-plus 1.0.0`。版本 1.0.0 和 1.1.0 在 Swoole 宿主中缺少 `Swow\Object\StringableInterface`。1.1.1 去掉了对原生接口的依赖，但其 `getParsedBody()` 返回类型与 Hyperf 3.2 的实现仍不兼容。1.1.2 提供一致的可空返回类型，作为适配包的最低版本。Symfony Console 6.0.0 的弃用输出影响生成配置的命令输出，适配包将命令基线设为 PHP 8.2 可运行的 Symfony Console 7.4。 [PSR-7 接口修订](https://github.com/swow/psr7-plus/blob/7acc4924be907d2ff64edee5a2bd116620e56364/src/Message/ServerRequestPlusInterface.php) · [Symfony 7.4](https://symfony.com/releases/7.4)

最低解析中的 PHP-Parser 5.6.0 在 PHP 8.5 的 Hyperf 启动扫描中产生弃用输出。旧网关用例把标准输出重定向为配置，HAProxy 因此拒绝加载。适配包声明含 PHP 8.5 修复的 `nikic/php-parser ^5.6.2`，网关用例同时改为调用生产已提供的 `--output` 原子写入。 [PHP-Parser 5.6.1](https://github.com/nikic/PHP-Parser/releases/tag/v5.6.1) · [PHP-Parser 5.6.2](https://github.com/nikic/PHP-Parser/releases/tag/v5.6.2)
