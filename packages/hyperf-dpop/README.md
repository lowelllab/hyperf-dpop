# Hyperf DPoP

`0.1.0` 正式版，Composer包名`lowelllab/hyperf-dpop`，依赖lowelllab/dpop-core ^0.1.0。PHP ^8.5，Hyperf ~3.2.0；首期验证运行引擎为Swoole6.2，PHP命名空间为`Acme\HyperfDpop`。

## 安装

```bash
composer require "lowelllab/hyperf-dpop:^0.1"
```

默认从Packagist安装正式版并自动引入协议核心，无需path/VCS仓库或开发稳定性设置。目标生产拓扑验收及独立安全审计仍须按部署范围完成。接入前按[网关说明](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/gateway.md)部署入口检查、剥离请求尾部并隔离后端。

ConfigProvider自动发现提供依赖工厂、启动配置检查、可选doctor命令和配置/DDL/网关模板发布。业务项目仍须显式实现TokenBindingResolverInterface并挂载DpopMiddleware到目标路由。

默认MySQL独立事务防重放及Redis共享认证预算，另提供显式Redis重放驱动。生产不得替换成无共享状态的防重放或Noop预算。

资源身份通过PSR请求属性`DpopContext::ATTRIBUTE`传递；TokenEndpoint提供签发/刷新证明辅助器。刷新后宿主必须以锁或CAS核对原绑定再轮换令牌。

安装不会自动修改路由、迁移业务库或创建账号。发布配置后填写公开HTTPS origin、nonce密钥、安全域、共享存储及resolver；再运行`dpop:doctor`检查。

重复认证头同时检查PSR多值及Swoole HTTP/1原始头。Swoole原生HTTP/2无法读取原始头，受保护接口返回503；客户端HTTP/2应由检查重复头的入口网关终止，向Hyperf转发HTTP/1.1。`vendor:publish lowelllab/hyperf-dpop --id=gateway`可复制HAProxy模板，已有文件默认保留；证书、后端地址及访问隔离由宿主配置。详见[网关接入](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/gateway.md)。

WebSocket、自定义设备管理、终端SDK及mTLS不在此版范围。JWT验证、会话撤销和业务授权由宿主实现。正式使用前阅读工作区完整文档；当前尚未完成独立安全审计。私密漏洞报告见[安全政策](SECURITY.md)。

完整文档：[使用示例](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/usage-examples.md)、[接入指南](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/integration.md)、[安全说明](https://github.com/lowelllab/hyperf-dpop/blob/main/docs/security.md)。
