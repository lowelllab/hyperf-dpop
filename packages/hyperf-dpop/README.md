# Hyperf DPoP

未发布开发版，占位包名`acme/hyperf-dpop`，依赖acme/dpop-core ^0.1。PHP ^8.5，Hyperf ~3.2.0；首期验证运行引擎为Swoole6.2。

ConfigProvider自动发现提供依赖工厂、启动配置检查、可选doctor命令和配置/DDL发布。业务项目仍须显式实现TokenBindingResolverInterface并挂载DpopMiddleware到目标路由。

默认MySQL独立事务防重放及Redis共享认证预算，另提供显式Redis重放驱动。生产不得替换成无共享状态的防重放或Noop预算。

资源身份通过PSR请求属性`DpopContext::ATTRIBUTE`传递；TokenEndpoint提供签发/刷新证明辅助器。刷新后宿主必须以锁或CAS核对原绑定再轮换令牌。

安装不会自动修改路由、迁移业务库或创建账号。发布配置后填写公开HTTPS origin、nonce密钥、安全域、共享存储及resolver；再运行`dpop:doctor`检查。

WebSocket、自定义设备管理、终端SDK及mTLS不在此版范围。JWT验证、会话撤销和业务授权由宿主实现。正式使用前阅读工作区完整文档；本包尚未发布稳定版或完成独立安全审计。
