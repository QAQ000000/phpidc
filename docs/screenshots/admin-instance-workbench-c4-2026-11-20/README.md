# C4 实例工作台验收截图

日期：2026-11-20；基线 `1f0d38c`。全部为独立 SQLite、合成账号和 loopback 模拟供应商，非真实客户数据。

[实施记录](../../admin-instance-workbench-c4-implementation-2026-11-20.md) · [结构化验收](acceptance.json) · [PNG SHA-256](SHA256SUMS)

| 截图 | 范围 | 尺寸 |
| --- | --- | --- |
| [workbench-active-1440-light.png](workbench-active-1440-light.png) | 实例工作台桌面浅色 | 1440×3779 |
| [workbench-active-1440-dark.png](workbench-active-1440-dark.png) | 实例工作台桌面深色 | 1440×3779 |
| [workbench-active-390-dark.png](workbench-active-390-dark.png) | 实例工作台手机深色 | 390×844 |
| [workbench-local-1440-dark.png](workbench-local-1440-dark.png) | 本地卡密只读工作台 | 1440×2194 |
| [instance-sync-390-dark.png](instance-sync-390-dark.png) | 显式同步弹窗 | 390×844 |
| [instance-task-retry-1-390-dark.png](instance-task-retry-1-390-dark.png) | 已付款失败原任务重试确认 | 390×844 |
| [machine-1440-light.png](machine-1440-light.png) | 机器管理桌面浅色 | 1440×1000 |
| [machine-1440-dark.png](machine-1440-dark.png) | 机器管理桌面深色 | 1440×1000 |
| [machine-390-dark.png](machine-390-dark.png) | 机器管理手机深色 | 390×844 |
| [machine-reinstall-390-dark.png](machine-reinstall-390-dark.png) | 重装系统与OS联动 | 390×844 |
| [machine-rescueSystem-390-dark.png](machine-rescueSystem-390-dark.png) | 救援系统与备份确认 | 390×844 |
| [sync-recovery-390-dark.png](sync-recovery-390-dark.png) | 同步校验失败安全回弹 | 390×844 |

36项桌面／390px明暗布局、20项弹窗、31项角色边界通过；真实表单覆盖重装、救援、未知请求拦截、显式同步、安全回弹和两个原任务重排。独立分页保留另一列表页码。18项HTTP检查覆盖CSRF、VNC身份／篡改／安全头、停用与注销owner、状态归一化及同步保留未决意图。

GET前后60张业务表哈希保持；操作后除实例／原任务／队列／操作审计／上游交换外55表保持，资金、账单、支付与通知事件均未改变。VNC仅验证HTML与签名协议，未模拟真实WSS/TLS/RFB握手。无真实供应商、资金或通知动作。
