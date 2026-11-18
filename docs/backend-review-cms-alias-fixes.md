# CMS 历史地址修复

日期：2026-11-18（Asia/Shanghai）。依据用户已批准的 `docs/backend-review-fix-decisions.md` C，保留按 ID 改名的现有操作，解决改名后旧链接直接 404 的问题。

## 行为与事务

- 新迁移 `2026_11_18_000003_add_cms_slug_aliases.php` 创建两个永久名称登记表：栏目按 `(type, slug)` 唯一，文章按 `(cms_category_id, slug)` 唯一。登记表包含当前名和历史名，避免当前表与历史表各自唯一却互相抢占的并发窗口。迁移按 100 行一批回填所有现有栏目与文章，包含隐藏栏目及未发布文章。
- `SaveCmsCategoryAction`、`SaveCmsArticleAction` 在原有事务中锁定对象、保留旧名、保存新名、登记新名并写审计。索引冲突转换为 `slug` 字段错误，事务整体回滚。名称永久属于原对象；本对象可重新采用自己的历史名，其他对象不能采用。现有栏目类型和文章归属不可改规则、CLI 按当前 slug 更新及后台按 ID 编辑的语义保持一致。
- 公开访问先按当前名称的现有唯一索引查找，未找到时按别名唯一索引定位主键；没有把正常链接查询改成全表 OR 扫描。先定位当前可见栏目，再定位当前已到发布时间的公开文章。旧地址无论经历多少次栏目/文章改名，都一次 301 到当前规范地址；保留 `.md` 后缀及查询参数，`Accept: text/markdown` 继续由原中间件协商。
- 历史地址 301 显式设置 `Cache-Control: no-store`（框架输出同时含 `private`），避免本对象 A→B→A 恢复旧名后，浏览器先前缓存的 A→B 与服务器当前 B→A 形成循环。
- 隐藏栏目、草稿、撤回、无发布时间及未来文章的当前/历史详情地址均返回 404，不给出目标重定向。不同类型可用同栏目名，不同栏目可用同文章名，解析不会串到其他类型/栏目。公开列表、canonical、sitemap 和 llms 只输出当前地址。

## 验证

新增 `ReviewCmsSlugAliasesTest` **16 个实际用例**，覆盖当前名登记、连续改名的全部交叉组合、一次跳转、`.md`/Accept/查询参数、栏目搜索分页、类型/栏目隔离、当前与历史名称抢占拒绝、本人旧名重用、五类不可见状态、公开导出及审计失败时事务回滚。没有修改旧测试。

隔离 APP_KEY/storage、SQLite `:memory:` 中运行新专项及既有 `CmsAdministrationTest`、`CmsStorefrontTest`：**38 passed / 483 assertions**，包含最后补充的 301 不缓存断言。相关 Actions/Controller/辅助类/迁移 PHPStan **0 errors**，Pint 通过。

`scripts/verify-review-identity-cms-mysql.py` 使用新建的离线 MySQL 8.0.35（`--network none`、私有 socket、tmpfs、新测试库），运行新 CMS 与账户/工单专项：**29 passed / 315 assertions**。迁移在已有 **105 个栏目、105 篇文章** 的隔离数据库中回填成功，跨越两个分块并包含隐藏/草稿记录。两组实际并发 PHP 进程分别对不同栏目、同栏目不同文章争抢一个目标 slug，均为 **一成功、一 slug 校验拒绝**；随后他者抢占胜者旧名被拒绝。绕过 Action 的重复 namespace 插入也被数据库唯一索引以 **1062** 拒绝。

可复核证据保存在 `/tmp/laravel13idc-review-identity-cms-kkd2gahn/`：`pest.log`、`setup.json`/`setup.php`、`concurrency.json`、两组 worker 日志、`final-check.json`。脚本结束后已移除独立测试容器；既有数据库及业务/预览环境没有迁移。

## 部署边界

现有业务/预览库仍需在单独授权的部署中执行新增迁移；本次仅提供和验证迁移。此修复只能保护迁移时的当前名称及之后的改名，不能从没有可靠历史记录的数据中找回更早已丢失的 URL。
