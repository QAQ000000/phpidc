# C2 客户基础管理截图与验收证据

所有截图来自隔离数据库和模拟账号，不含真实客户或部署凭据。桌面 1440×1000，手机 390×844；页面全屏截图高度随内容变化。

| 截图 | 尺寸 |
| --- | --- |
| [customer-detail-1440-light.png](customer-detail-1440-light.png) | 1440×6248 |
| [customer-detail-390-dark.png](customer-detail-390-dark.png) | 390×844 |
| [customers-1440-light.png](customers-1440-light.png) | 1440×1148 |
| [disable-390-dark.png](disable-390-dark.png) | 390×844 |
| [disabled-after-action-390-dark.png](disabled-after-action-390-dark.png) | 390×844 |
| [disabled-detail-1440-dark.png](disabled-detail-1440-dark.png) | 1440×2959 |
| [disabled-list-390-dark.png](disabled-list-390-dark.png) | 390×933 |
| [password-reset-390-dark.png](password-reset-390-dark.png) | 390×844 |
| [profile-1440-light.png](profile-1440-light.png) | 1440×1000 |
| [profile-recovery-390-dark.png](profile-recovery-390-dark.png) | 390×844 |
| [protected-detail-1440-dark.png](protected-detail-1440-dark.png) | 1440×3426 |

[完整验收与 SHA-256](acceptance.json)：32 项页面布局、12 项弹窗、8 项锚点、15 项角色边界、44 项交互检查。浏览器 page error 为 0。

只读检查覆盖 58 张表且无变化；写流程只改变账户、验证绑定和审计。标准重置通过本机 SMTP 接收，重复请求未额外发信；此结果不等于外部邮件最终送达。
