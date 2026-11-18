# 复审十项缺陷修复与验收

日期：2026-11-18（Asia/Shanghai）。审查与修复基线均为当前工作区，包含此前未提交改动；本轮不以 Git HEAD 代替实际基线。

## IMPLEMENTED

| 项 | 原问题 | 修复后的行为 |
| --- | --- | --- |
| 1 | 已付款升级遇配置锁忙被误判为未知创建，无法继续 | 仅对严格证明 not_sent 的重试结果清除当前租约意图并退避重试；真正未知不重放 |
| 2 | 自动续费忽略客户付款选择，未付续费又阻断终止 | 新自动单由客户一次确认钱包/网关；终止原子取消未付续费并按原流水解冻，晚到款只入钱包 |
| 3 | 商户配置替换导致在途回调无法验签 | attempt 精确绑定加密商户版本；配置、发起和回调选择串行核对，旧款继续用原版本 |
| 4 | 同步期间换账号配置，旧结果覆盖新配置 | HTTP 前后核对绑定及账号认证指纹，提交时持锁，变化即丢弃结果 |
| 5 | 正常终止后本地 Deleted 无法执行 terminate 换绑 | 管理员显式换绑的窄例外，认证旧 Deleted 和新主机，拒绝退款/未决财务并保留历史审计 |
| 6 | LOWER(email) 扫描并扩大密码重置锁范围 | MySQL 使用原唯一索引等值查询，候选主键定向锁并复验身份 |
| 7 | 工单字符校验通过后超过 TEXT 字节容量 | 同时限制 20,000 字符和 65,535 UTF-8 字节，返回字段错误 |
| 8 | 栏目/文章改名后旧公开地址 404 | 唯一索引保留当前与历史 slug，旧地址一步 301 到当前公开目标，避免别名抢占和跳转链 |
| 9 | 按分取整为零的佣金每次调度重复处理 | 锁内保存一次零计提完成审计，调度 SQL 排除；不生成虚假零佣金/通知 |
| 10 | 工单页读取并输出客户所有服务 | 支持本人服务名称/IP/ID 搜索，最多读取 51 条、显示 50 个选项，保留历史服务关联与校验失败选择 |

对应实现和专项证据：

- `backend-review-delivery-fixes.md`
- `backend-review-renewal-workflow.md`
- `backend-review-payment-affiliate-fixes.md`
- `backend-review-identity-support-fixes.md`
- `backend-review-cms-alias-fixes.md`

## DECISIONS

用户已明确采用 `backend-review-fix-decisions.md` A/B/C 三项定向方案，分别涉及自动续费的一次分配确认、Deleted 管理员 terminate 换绑例外和 CMS slug 保留表。章程、INV-I1/INV-S1、guardrails 已同步。商户版本继续使用现有 settings/attempt JSON，不改 DTO 字段或状态枚举；零计提不改财务快照。

## GATES

修复前：Pest 782 passed / 6605 assertions；PHPStan 0 errors；Pint clean。修复前工作区副本及哈希保存在 `/tmp/laravel13idc-review-fixes-57vtwn92/before` 与 `before-manifest.json`。

交叉审查修正完成后的最终门禁：Pest **903 passed / 7999 assertions**，48.62 秒；PHPStan **0 errors**；Pint **passed**；`git diff --check` **passed**。新增独立回归 **121 项**；相较修复前没有删除或变更旧测试。日志在 `/tmp/laravel13idc-review-fixes-57vtwn92/final-{pest,phpstan,pint,diff-check}.log`。最终验证使用隔离 APP_KEY/storage、SQLite 内存数据库和假 HTTP/队列。

独立 MySQL 8.0.35 验证：

| 专项 | 实际结果 |
| --- | --- |
| 续费确认/终止 | 54 passed / 708 assertions；三个真实用户行锁竞争通过 |
| 支付商户/零佣金 | 23 passed / 179 assertions；配置/发起/旧回调三进程与零计提双进程竞争通过 |
| 同步/升级/换绑 | 106 passed / 1100 assertions；经典操作筛选另 25 passed / 284 assertions；退款持锁后换绑等待并拒绝通过 |
| 身份/工单/CMS | 29 passed / 315 assertions；105 栏目+105 文章迁移回填、两组别名争抢、唯一键冲突通过 |
| 邮箱锁与字节容量 | 邮箱 const/users_email_unique/rows1；无关账号更新不被锁，目标账号确实被锁；65,535 字节完整写入，65,536 字节校验拒绝 |

测试脚本、原始输出及证据目录见各专项文档。所有本轮创建的离线 MySQL 容器已移除，既有 `mysql_8i4q1reyl8` 未动。没有将 SQLite 通过当作 MySQL 并发证明，也没有改变既有 MySQL JSON 对象键序断言来声称全 MySQL 套件通过。

交叉复核确认续费锁序与钱包路径；CMS 复核发现的旧名恢复缓存循环已通过 301 `Cache-Control: no-store` 和回归断言修正。对比本轮开始副本没有发现既有测试文件改动或源文件丢失。

## SPEC CASES

`tests/Specs/FulfillmentSpecsTest.php` 及计数未改，仍为 0。新增回归在独立 `Review*Test.php` 中。与修复前副本比较，既有测试文件无变更，没有通过删测试、跳过或放宽旧断言获取通过结果。

## NOT DONE / CONCERNS

- 本轮完成代码与隔离验证；没有执行现有业务/预览库迁移。部署需安排新增 `2026_11_18_000003_add_cms_slug_aliases.php`，并核对此前已存在的 000001/000002 迁移状态。新代码与迁移作为同一次部署安排，本文不授权生产迁移。
- 不能从不存在的历史证据恢复修复前已丢失的 CMS 地址、商户密钥或已误记未知的升级意图；不批量改历史账本、账单或任务。
- 新自动续费的一次确认只适用于明确标记的新单，不把旧单的钱包/优惠分配重新计算。
- 没有真实商户收款、真实供应商续费/删除/换机、生产负载或生产部署验收；已有冷缓存复杂长 Markdown、海量 sitemap 分片和设置变更需重启常驻 worker 等已知边界仍保留。
- PVE、主动充值、账户注销、外部提现、应收清偿、通知事件 outbox 继续为原已延期能力，不属于本轮十项缺陷实现。
- 保留当前未提交工作区，未创建提交、推送或发布。
