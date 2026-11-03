# 0.1.0-beta.1 发布记录

日期：2026-11-03。阶段：首次测试版，非稳定版。双包已公开发布并通过全新Hyperf消费验证；远端CI首次暴露测试脚本日志目录缺失，窄修复和干净检出复验通过，等待后续CI。

## 分发范围

| 包 | 发布仓库 | 标签 | 发布提交 | Packagist |
| --- | --- | --- | --- | --- |
| lowelllab/dpop-core | [dpop-core](https://github.com/lowelllab/dpop-core) | [v0.1.0-beta.1](https://github.com/lowelllab/dpop-core/releases/tag/v0.1.0-beta.1) | 503fbfed2410c06682997edf0f345a77dac88d1c | p2索引与目标提交匹配 |
| lowelllab/hyperf-dpop | [hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | [v0.1.0-beta.1](https://github.com/lowelllab/hyperf-dpop-package/releases/tag/v0.1.0-beta.1) | c92cd27b23beae51c205341a338628acc6810db9 | p2索引与目标提交匹配 |

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

- 发布前：Composer strict、153测试/281断言、PHPStan6、84语法、MySQL/Redis各12进程1成功11拒绝、协程取消及生产CLI17项通过。新导出17+32文件的本地VCS消费和两个精确beta标签安装均通过；无不稳定第三方依赖。
- 远端标签与GitHub Releases：核心503fbfe、适配c92cd27；均为公开prerelease、draft=false，未覆盖已有标签。两个push钩子收到202，Composer p2索引分别与标签提交相同。普通package.json接口可能有缓存，以真实Composer使用的p2和安装结果交叉核实。
- 公开消费：新建同级hyperf-dpop-beta-consumer，沿用官方Hyperf3.2验收宿主源码；没有repositories/minimum-stability覆盖、全新lock/vendor，无工作区挂载。两个beta均由默认Packagist解析到上表提交，88个生产包，49个包文件逐字节一致，包根文件集合无多余内容。
- 独立真实Hyperf：完整执行acceptance/retest-gateway.ps1（含install），MySQL/Redis分别HTTP1冷缓存与HTTP2热缓存，四组各87 PASS/0 LIMITATION；生产APP_ENV、SCAN_CACHEABLE=true、无dev。每组64并发跨两Worker身份隔离、24路同proof仅1成功、8路refresh仅1成功。37宿主语法、strict及锁依赖公告审计通过。新解析依赖实际包括PHP8.5.12、Swoole6.2.4、Hyperf framework3.2.0及DI3.2.2，不将宿主旧锁冒充本次新锁。
- 环境：独立beta宿主脚本finally已移除自身容器/网络，tmpfs清除；根实验环境也已移除自身容器/网络；两项目查询为空，其他业务项目未操作。
- 远端CI：[首次执行](https://github.com/lowelllab/hyperf-dpop/actions/runs/41563085542)失败于consumer-smoke日志目录缺失，而非扩展行为。tools/consumer-smoke.php显式创建build目录；在无build/vendor的新git archive源码副本实际安装消费通过。后续远端CI待核实，已发布包源码不因此修改或重打标签。

[结构化版本/49文件摘要/四组验收证据](release-0.1.0-beta.1.json)；原始运行日志保留忽略的build/release-0.1.0-beta.1/。87项为同一场景集在四种模式重复，不宣称348个独立场景。生产验收事项继续开放。
