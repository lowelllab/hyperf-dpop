# 双包发布指南

本项目维护 `lowelllab/dpop-core` 和 `lowelllab/hyperf-dpop` 两个 Composer 包，PHP 命名空间分别为 `Acme\Dpop` 和 `Acme\HyperfDpop`。当前正式版本为协议核心 **0.2.0** 和 Hyperf 适配 **0.3.0**。 [本次发布记录](release-0.3.0.md)

历史发行与验收记录见 [首次正式发行](release-0.1.0.md)、[首次 beta](release-0.1.0-beta.1.md)和 [beta 本地验收](acceptance-beta-20261103.md)。

## 三个仓库的用途

| 用途 | GitHub 仓库 | Composer 名称 | 本次状态 |
| --- | --- | --- | --- |
| 开发、测试与文档 | [hyperf-dpop](https://github.com/lowelllab/hyperf-dpop) | lowelllab/hyperf-dpop-workspace（project） | 不作为扩展包安装入口、不打本次扩展标签 |
| 核心包分发 | [dpop-core](https://github.com/lowelllab/dpop-core) | lowelllab/dpop-core（library） | v0.2.0，923334d，18 个文件（含 SECURITY.md） |
| Hyperf适配分发 | [hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | lowelllab/hyperf-dpop（library） | v0.3.0，986421a，37 个文件（含 SECURITY.md） |

开发时改主仓对应子包；发布时只将该包白名单文件同步至对应发布仓库。业务项目安装扩展时只访问两个包的公开分发，不需要克隆三个Git仓库。已经登记的workspace条目不用于扩展安装，本次未删除。

Packagist读取仓库根composer.json并从Git标签推导版本，因此不能把主仓URL再次提交为扩展，也不填写composer.json的version字段。[官方发布及版本规则](https://packagist.org/about#managing-package-versions)

## 安装正式版

```bash
composer require "lowelllab/hyperf-dpop:^0.3.0" "hyperf/redis:~3.2.0"
```

默认 Packagist 提供适配包 v0.3.0 及其依赖的核心 v0.2.0。Composer 版本由标签推导。

无需path或自定义VCS仓库；安装后的lock应指向上表两个提交，不应继续选择dev-main。开发工作区使用 path 及各包的开发别名，仅供开发测试。

## 本地导出与验证

```bash
docker compose run --rm --no-deps test php tools/prepare-packages.php
docker compose run --rm --no-deps test php tools/verify-package-exports.php
```

每次准备新批次，输出 `build/packagist/<批次>/dpop-core` 与 `hyperf-dpop`，指针为build/packagist-latest.json。只复制composer.json、README、LICENSE、SECURITY.md、src及适用publish模板，manifest保存逐文件SHA256；不包含测试目录、vendor、.env、runtime、私钥或主仓配置。

验证器在全新导出副本创建本地候选标签，核对稳定安装、两个提交和逐文件 SHA-256。候选为核心 0.2.0、适配 0.3.0。运行 `bash tools/verify-compatibility.sh candidate` 在独立应用验证默认和最低稳定依赖及网关链路。 [PHP 兼容说明](compatibility.md)

正式发布后，在对应源码上运行带期望提交的公开安装验证。GitHub Actions 的 DPoP verification 工作流选择 `published`，会在四个 PHP 安全补丁点执行相同检查。

```bash
bash tools/verify-compatibility.sh published 0.2.0 0.3.0 "$CORE_COMMIT" "$ADAPTER_COMMIT"
```

## 后续发行顺序

1. 在主仓修复并回归；维护CHANGELOG、兼容/升级说明及实施记录。检查导出白名单、许可与敏感文件。
2. 核对两个发布检出无无关改动、远端main及目标标签；从新导出批次同步文件并比对SHA256。
3. 等待候选提交的远端兼容性 CI 通过。先发布核心标签及 GitHub Release，确认默认 Packagist 索引的版本和 `source.reference` 与标签提交一致。
4. 再发布适配标签及 GitHub Release，执行同样的公开索引检查。预发行版使用 prerelease。发布标签保持固定。
5. 以版本和提交启动 `published` 模式，复验 PHP 8.2 至 8.5 四个分支的独立安装。
6. 新建无path/VCS覆盖、无开发工作区挂载、独立lock/vendor的Hyperf宿主，从默认Packagist安装目标版本，逐文件核对并复跑真实认证/存储/并发/网关验证，记录远端CI结果。

升级本次适配必须先按[网关接入](gateway.md)部署重复头检查、认证Trailer声明拒绝、实际尾部剥离及后端隔离，固定HTTP/1.1后端。仅更新Composer不能替代网关配置。发布项默认保留现有文件，应人工合并已有配置，勿无故使用force。

## 正式分发与目标部署验收

真实CDN/Ingress/网络策略、容量SLO、HA/状态丢失/恢复演练、独立安全审计及维护/漏洞渠道继续按[维护指南](maintenance.md)验收。这些事项由目标部署环境分别验收。原生Swoole HTTP/2的503保护及HTTP/1尾部等待限制保留。

## 升级到 0.3.0

若宿主固定了旧版本或使用本地仓库覆盖，先移除覆盖，再更新适配包约束与锁文件。宿主若直接依赖核心，请将约束更新为 `^0.2.0`。安装前确认 PHP、Hyperf 和 Swoole 版本满足运行时要求。 [当前适配包](https://packagist.org/packages/lowelllab/hyperf-dpop#v0.3.0)

把新增配置键合并到已有 `config/autoload/dpop.php`。mTLS 默认关闭。启用时先准备客户端 CA 和受信网关地址，再用 `dpop:gateway-config --output haproxy-dpop.next.cfg` 生成配置，通过 `haproxy -c` 后部署。宿主继续使用原有日志配置。 [配置步骤](configuration.md) · [v0.2.1 公开安装与验收](release-0.2.1.md)

维护与私密漏洞报告见[SECURITY.md](../SECURITY.md)，三个仓库已启用报告功能。
