# U1 对象工作台验收截图

2026-11-20；[实施记录](../../admin-workspace-u1-implementation-2026-11-20.md)。全部为独立临时 SQLite 的合成客户、订单和服务，不含真实客户／供应商凭据。静态头像使用本机 SVG 替身；服务与资金事实均为测试夹具。

| 文件 | 场景 |
| --- | --- |
| customer-1440-light.png | 桌面浅色客户页头与原操作 |
| customer-390-dark.png | 手机深色客户身份与两列操作 |
| instance-1440-dark.png | 桌面深色实例与客户来源返回 |
| invoice-1440-light.png | 桌面浅色账单来源与原收款事实 |
| invoice-390-dark.png | 手机深色账单身份与返回实例 |
| profile-validation-390-dark.png | 真实 CSRF 验证失败后恢复编辑弹窗 |

`observations.json`：20组布局、14项实际交互、隔离 mock 请求／零资金与通知记录、临时环境清理结果和门禁汇总。`SHA256SUMS` 可核对文件完整性。模拟环境不证明真实支付、供应商执行或客户邮件送达。
