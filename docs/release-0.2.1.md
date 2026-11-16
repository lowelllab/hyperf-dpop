# Hyperf DPoP 0.2.1 发布与验收

2026-11-15（Asia/Shanghai）发布适配包 `lowelllab/hyperf-dpop` v0.2.1，提交 `d98d7e8f8a5bfd04e6f0b71c194333d1a54e6916`。GitHub 和 Packagist 后续下架了旧版适配包，当时的正式版为 v0.2.1。协议核心沿用 v0.1.0。 [GitHub Release](https://github.com/lowelllab/hyperf-dpop-package/releases/tag/v0.2.1) · [Packagist](https://packagist.org/packages/lowelllab/hyperf-dpop#v0.2.1)

新增 `dpop:gateway-config --output`，直接写入配置文件。生成成功时整体替换并保留已有文件权限，失败时保留已有文件。 [配置指南](configuration.md)

## 独立 Packagist 复测

独立 Hyperf 3.2 宿主以默认 Packagist 和默认稳定性从空缓存安装，使用新的 `composer.lock` 和 `vendor`。88 个生产依赖完成安装，适配包 36 个文件和核心包 18 个文件均通过发布标签内容校验。Composer 严格校验及依赖公告审计通过。

| 场景 | 结果 |
| --- | --- |
| mTLS 关闭 | 保留宿主默认日志配置，启动 DEBUG 日志仍写入控制台，生成文件通过 `haproxy -c`。 |
| mTLS 开启 | 使用客户端 CA 与 CRL，同样保留默认日志配置，生成文件通过 `haproxy -c`。 |
| mTLS 开启但缺少客户端 CA | 命令返回 1，既有有效配置摘要一致。 |

实测 PHP 8.5.12、Swoole 6.2.4 和 HAProxy 3.2.26。开发主仓的回归覆盖 159 个 PHPUnit 测试、302 次断言和 29 项 CLI 检查。 [开发主仓 CI](https://github.com/lowelllab/hyperf-dpop/actions/runs/43667663572)
