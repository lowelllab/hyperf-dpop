# 0.1.0-beta.1 发布记录

日期：2026-11-03。阶段：首次测试版，非稳定版。发布执行与公开安装验证进行中，以下结果只在有实际证据时更新。

## 分发范围

| 包 | 发布仓库 | 标签 | 发布提交 | Packagist |
| --- | --- | --- | --- | --- |
| lowelllab/dpop-core | [dpop-core](https://github.com/lowelllab/dpop-core) | v0.1.0-beta.1 | 待推送 | 待核实 |
| lowelllab/hyperf-dpop | [hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | v0.1.0-beta.1 | 待推送 | 待核实 |

开发仓库根为workspace，不打扩展包标签、不作为安装入口。实施契约0.8与发行版0.1.0-beta.1是两个编号。

本次包含标准Hyperf代理的原始头检查、HTTP/1重复认证头/凭据一致性检查、认证尾部拒绝、HTTP/2失败关闭及HAProxy请求尾部剥离模板。核心协议行为保持最近已测版本；核心首次标签同时固定依赖基线。PHP命名空间维持Acme\Dpop和Acme\HyperfDpop。

## 安装

```bash
composer require "lowelllab/hyperf-dpop:0.1.0-beta.1" "lowelllab/dpop-core:0.1.0-beta.1"
```

显式允许两个根包的beta版本，不必把全项目minimum-stability设为dev。发布包不携带repositories、测试目录、vendor、宿主配置或私钥。Composer版本由Git标签推导，不填写version字段。

## 升级与保留限制

先按[网关说明](gateway.md)部署认证字段重复检查、认证Trailer声明拒绝和实际请求尾部剥离，固定HTTP/1.1后端并通过网络策略隔离，再更新应用与配置。vendor:publish默认保留已有文件，应人工合并已有网关配置，不能只更新Composer。

原生Swoole HTTP/2受保护入口返回503；原生HTTP/1尾部报文可能在PHP处理前等待。本次仅保证已验证的HAProxy入口链路，不将客户端超时计为拒绝成功。真实生产拓扑/容量SLO/HA恢复/独立安全审计仍待验收，见[最近审核](review-deep-20261103.md)。

## 验证与执行证据

- 发布前：进行中。
- 远端标签及GitHub Releases：待核实。
- Packagist双包索引：待核实。
- 纯Packagist干净消费及独立Hyperf回归：待执行。
- 远端CI：待核实。
