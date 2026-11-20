# 后台运营与内容页面实施记录（B1）

日期：2026-11-20。基线：`a00680d`。页面合同见 [B1 合同](admin-operations-content-ui-contract-2026-11-20.md)。本记录登记本批实现与最终验收证据。

## 交付范围

本批继续使用现有 Filament 外壳、`x-admin` 公共组件和原生 `<dialog>`，没有数据库迁移、新增依赖或前端构建链。六个可折叠全局分类、三十个业务入口保持；内容区没有新增分类侧栏或主分类 Tab。B2 库存、清算与系统运维页仍单独实施。

| 页面 | 实施结果 |
| --- | --- |
| `/admin/coupons` | 优惠券列表、额度与状态改为紧凑表格；创建 / 编辑、定向发放领取资格进入各自对话框；保留商品范围及领取条件 |
| `/admin/affiliates` | 推广规则和佣金明细使用独立信息卡、表格与分页；规则编辑进入对话框，佣金仍按原推广人 ID 筛选 |
| `/admin/payouts` | 佣金转钱包申请改为表格及逐笔审核对话框，保留余额、申请占用、应收核验进度与完整核验入口 |
| `/admin/support` | 工单列表使用状态筛选和表格，选中记录后显示会话；处理对话框保留客服回复、关闭及重开动作 |
| `/admin/notifications` | 通知模板与投递日志分卡展示；模板编辑、短信模板 ID 和变量映射进入对话框；日志保持原隐私投影 |
| `/admin/downloads` | 下载资源列表集中显示访问要求、状态、文件信息与请求数；上传 / 外链编辑进入对话框 |
| `/admin/content` | 文章、栏目、商品展示使用三组信息卡及表格；各自编辑进入对话框，发布及分类固定边界保持 |

原 `operations.blade.php` 按六个已有业务域拆为 `filament/operations/` 下的六个模板；CMS 三种表单拆为 `filament/content/` 下的三个模板。`admin-operations.css` 复用共享颜色和尺寸，统一双列表单、全宽正文、按钮区、消息与复杂事实展示，手机转为单列。列表、徽章、分页和对话框继续使用既有 `x-admin` 组件。

## 表单与历史边界

全部原业务字段、POST 路径、CSRF 和 Controller / Action 保留；`edit`、`article`、`category`、`product` 及原分页参数继续有效。筛选条只展示当前查询支持的条件，没有增加无实现的分类、时间或通用状态筛选。多列表分页保留查询条件，模板同事件记录增加 ID 次序作为稳定排序。

新增固定白名单 `_admin_form` 区分同页表单。验证失败只恢复对应表单的非秘密输入、选项、开关及记录 ID，并重开相应对话框；从列表 URL 返回时不会把编辑变成新建。显式选择另一条记录优先，不让旧错误输入替换新选中记录。没有该标记的旧直接 POST 仍显示页面错误。

商品范围明确选择“指定商品”且清空勾选后，错误恢复继续保留空选择，不重新勾回数据库原记录。SMS 表单保留 `mapping_rows_present`：删除全部变量映射后，即使其他字段校验失败，也恢复为空映射状态，不从已保存模板重新带回旧映射。非 SMS 渠道的字段、非当前下载存储方式的字段仍通过原条件禁用。

只读角色没有管理表单；优惠券、推广、客服、通知、下载和财务沿用各自原 Gate。转钱包审核保留 `pending` / `wallet` 条件、`finance.payout`、确认勾选与驳回理由要求；批准时仍由原 Action 重新核验余额及应收，未完成核验不表示无债。CMS 草稿编辑与发布权限分开，已发布 / 预约文章仍要求发布权限，原栏目、栏目类型及商品价格 / 驱动 / 上游绑定均未在展示表单中开放。

通知日志继续只显示 `delivery_note` 白名单说明，不投影收件人或原始传输异常。表单、会话、名称与说明仍由 Blade 转义。本批 GET 与打开对话框不调用上游、支付或通知；原转钱包页的应收读取、时间预算和未知状态提示保留，不把 UI 改版当作新的资金准入规则。

## A 批三处微调

移除总览主标题下重复的“业务概况”标题，保留说明和更新时间。390px 账单表限定前三列宽度及手机单元格内边距，使金额列完整显示，创建时间仍可在表格容器内横向滚动。未结支付异常和失败履约任务大于零时将数字标红，待回复工单大于零时使用琥珀色；零值沿用普通文字颜色，深色模式使用对应颜色。没有改变总览权限、查询、结算额或七天到期口径。

## 验收

| 验收项 | 当前证据 |
| --- | --- |
| 原运营专项 | **79 passed / 607 assertions** |
| 原 CMS 专项 | **6 passed / 67 assertions** |
| 原 Dashboard 专项 | **18 passed / 178 assertions** |
| 新 B1 UI 回归 | **29 passed / 499 assertions**；覆盖表单恢复与跨记录隔离、权限、隐私、分页及 SMS 清空回弹；未改动既有测试 |
| 浏览器布局矩阵 | 七个入口及首页 × 桌面 / 手机 × 浅色 / 深色，共 **32 组**通过，页面错误为 0 |
| 角色浏览器验证 | 七入口 × 查看角色 / 普通客户，共 **14 组**通过：查看角色返回 200 且无 POST 表单，普通客户返回 403 |
| 真实 HTTP / CSRF 回弹 | CMS 重复 slug、下载 HTTP 外链被拒绝后，原表单自动重新打开且非秘密输入保留；切回本地上传后文件启用、外链禁用及重选提示通过 |
| 原生弹窗与会话 | 390px 创建优惠券弹窗在视口内且无内部横向溢出；Escape 关闭后焦点回到创建按钮；工单会话与处理弹窗打开、取消关闭通过 |
| 总览微调 | 390px 金额列边界 215–319px，位于表格可视范围 17–373px 内；重复标题已移除，三项异常强调色与零值区别通过 |
| 全量 Pest | **2,531 passed / 22,387 assertions**，161.29s；日志 `/tmp/phpidc-b1-pest-final.log`。初轮 CLI 128 MiB 耗尽后，仅将本次测试进程额度改为 512 MiB 重跑，应用配置不变，无断言放宽或用例跳过 |
| PHPStan / Pint / 差异检查 | **0 errors / PASS / PASS**，新增测试与两个页面类 PHP lint 通过 |
| 共享预览只读检查 | `/up`、`/admin/login`、两份本批 CSS 均返回 200；匿名 `/admin/content` 返回 302 到 `/admin/login` |

浏览器使用独立 SQLite、storage 与模拟资料，不在共享预览制造订单、执行真实付款 / 出款 / 开通或发送业务通知。查看角色、普通客户和全部布局矩阵通过；未重新执行真实供应商、支付或通知验收。

## 截图

以下八张为本批原始 PNG 截图，总计约 855 KiB，金额、用户编号、工单和内容均为隔离测试资料。除弹窗为视口截图外，其余为整页截图；表格仍在局部滚动区域内显示。已检查图片完整性及文档链接。

| 场景 | 截图 |
| --- | --- |
| 优惠券桌面浅色 | [coupons-desktop-light.png](screenshots/admin-operations-content-2026-11-20/coupons-desktop-light.png) |
| 通知模板与日志桌面深色 | [notifications-desktop-dark.png](screenshots/admin-operations-content-2026-11-20/notifications-desktop-dark.png) |
| 商城与内容手机深色 | [content-mobile-dark.png](screenshots/admin-operations-content-2026-11-20/content-mobile-dark.png) |
| 总览手机浅色 | [dashboard-mobile-light.png](screenshots/admin-operations-content-2026-11-20/dashboard-mobile-light.png) |
| 总览手机深色 | [dashboard-mobile-dark.png](screenshots/admin-operations-content-2026-11-20/dashboard-mobile-dark.png) |
| 优惠券手机创建弹窗 | [coupon-dialog-mobile-dark.png](screenshots/admin-operations-content-2026-11-20/coupon-dialog-mobile-dark.png) |
| 下载资源验证回弹及文件重选提示 | [download-recovery-mobile-dark.png](screenshots/admin-operations-content-2026-11-20/download-recovery-mobile-dark.png) |
| 工单会话桌面浅色 | [support-detail-desktop-light.png](screenshots/admin-operations-content-2026-11-20/support-detail-desktop-light.png) |

## 保留限制

全局 `dontFlash(code)` 仍保护验证码 / 授权码字段，因此优惠券创建或发放失败后，刚输入的券码不会闪存恢复；编辑时可显示原已保存券码，页面明确提示重新输入。上传文件受浏览器限制不能回填，失败后提示重新选择，编辑留空仍按原逻辑保留旧文件。

CMS 三表单的备注 `maxlength` 收为 255，以匹配 `audit_logs.reason` 持久化列。原 Controller / Action 仍允许 1000 字符，本批没有修改其直接 POST 校验；绕过浏览器限制提交超长备注的历史差异仍需后续独立对齐，不能将本次 UI 限长称为全面修复。

后续状态（2026-11-20，B2）：上述 CMS 差异已在 Web Controller 和三个保存 Action 中统一为 255 字符；Action 使用同一规范化字符串校验与持久化。工单副标题、通知日志中文映射及下载外链 URL 中文错误提示一并完成，见 [B2 实施记录](admin-inventory-finance-operations-ui-implementation-2026-11-20.md)。以下尾注保留 B1 当时的实施边界。

IMPLEMENTED: B1 七个运营 / 内容入口使用统一组件；六个运营模板与三类 CMS 表单拆分；原生编辑 / 审核对话框及按表单、记录隔离的输入恢复；SMS / 商品范围清空保持；A 批标题、手机金额列和异常色微调。
NOT DONE: B2 库存 / 清算 / 系统运维旧页；没有新增真实业务外部验收。
DECISIONS: 保留原 Gate、动作、金额、核验与发布规则；只用现有查询筛选；错误恢复尊重显式选中记录；CMS UI 备注限 255 而不扩展本批业务接口改造；零迁移、零新增构建链。
CONCERNS: 券码沿用全局 dontFlash 需重新输入，文件不能恢复；CMS 直接 POST 的 1000 / 审计列 255 历史差异保留；应收未核验不能显示为无债，通知受理不表示阅读或最终送达。
GATES: pest 2531 passed / 22387 assertions | new UI 29 passed / 499 assertions | phpstan 0 errors | pint PASS | diff-check PASS | browser matrix 32 groups | role checks 14 groups。
SPEC CASES: 0（未变）。
