# HTTP错误契约

默认响应为JSON错误对象及`Cache-Control: no-store`；资源无认证时返回空JSON对象和算法挑战，不自造错误原因。

| 情况 | token入口 | 资源入口 | 头部 |
| --- | --- | --- | --- |
| 无Authorization | 不由扩展处理OAuth客户端认证 | 401，空JSON对象 | WWW-Authenticate: DPoP algs="ES256" |
| 重复/歧义认证头或Authorization语法错误 | 400 invalid_request | 400 invalid_request | no-store |
| 认证Trailer声明/字段、原生请求起始认证字段与PSR字段不一致 | 400 invalid_request | 同左 | no-store；网关另外剥离全部实际请求尾部字段 |
| proof缺失、格式/签名/方法/URI/时间/ath错误或重放 | 400 invalid_dpop_proof | 401 invalid_dpop_proof | 资源带WWW-Authenticate |
| 缺nonce、过期或上下文不符 | 400 use_dpop_nonce | 401 use_dpop_nonce | DPoP-Nonce，资源另带WWW-Authenticate |
| proof密钥与原绑定不符 | 400 invalid_dpop_proof | 401 invalid_token | 资源带WWW-Authenticate |
| access过期/撤销/无效，或Bearer降级 | 由宿主决定token客户端认证错误 | 401 invalid_token | WWW-Authenticate |
| refresh真正失效 | 400 invalid_grant | 不适用 | no-store |
| 安全依赖不可用/时钟偏差超预算 | 503 dpop_security_unavailable | 同左 | no-store |
| Swoole原生HTTP/2或原始头不可读，无法检查认证头唯一性 | 503 dpop_security_unavailable | 同左 | no-store；通过检查重复头的网关转发为HTTP/1.1 |
| 入口预算耗尽 | 429 rate_limited | 同左 | Redis窗口预算返回权威时钟计算的剩余秒数；本地并发槽耗尽建议1秒 |

业务权限不足、业务校验失败、消息幂等等不属于本包认证错误。扩展不会自动发起客户端续期或重试，使用方须区分invalid_token与invalid_dpop_proof，不因临时503清理refresh凭据。

自定义预算可通过`DpopException('rate_limited', retryAfter: $seconds)`提供1至3600秒的建议；未提供时保留60秒默认值。Retry-After只是重试建议，不保证届时一定能取得共享预算或本地槽位。
