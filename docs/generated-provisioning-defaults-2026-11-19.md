# 按产品规则生成主机名与开通密码

日期：2026-11-19（Asia/Shanghai）。用户要求“主机名和开通密码不要留空，应该按产品配置自动生成”。本轮为配置表单增加本地生成的默认值，沿用自动提交授权，无 schema 或迁移变更。

配置页、无 JavaScript 的报价返回页及 HTML 变价确认页均使用产品本地规则和已同步的 `classic_catalog.host/password` 规则生成新默认值。字段不使用固定密码，不从 `old()` 或之前提交的密码回填；生成使用安全随机数。已有 JavaScript 表单在报价或 409 原地确认时保持当前主机名和密码，以维持未知结果重试时的原购买意图。

生成仅使用本地同步快照，不增加报价或页面的供应商调用。主机名前缀、随机后缀长度与字符类别，以及密码长度与启用字符类别，沿用参考项目生成语义，并通过本地产品约束检查。当前商品 3 要求 `ser` 前缀加 12 位数字、12 位大小写字母及数字密码，不含特殊字符；生成的实际密码不写入记录。

生成失败会显示具体字段提示并允许手工输入，不生成已知违反产品规则的值；不实现通用正则表达式反向生成。最终下单仍按原校验器与实时供应商配置校验，生成过程不自动补全缺失的采购 API 参数，不改变必填/无效输入拒绝、资金事务和购买幂等契约。已创建账单的请求重放不重新生成凭据。

脚本本地报价继续只传 CSRF、计费周期与计价配置；报价 JSON 不含主机名或密码。HTML 页面按用户要求填入本次新生成密码，并保持 `private, no-store`；密码输入使用 `type=password` 和 `autocomplete=new-password`。无 JavaScript 的 409 页重新生成密码，不回显旧密码；自定义交付字段仍需重新填写。

参考项目 `/www/wwwroot/zjmf-manger-decoded` 仅只读对照 `app/common.php` 的 `generateHostName`、`generateHostPassword` 及首次配置表单调用，未复用其非加密随机实现，也未运行参考项目业务。

完整门禁 **1527 passed / 12604 assertions**，PHPStan **0 errors**，Pint **PASS**；6 个变更 PHP/Blade 文件语法与原 Node 报价行为验证通过。测试使用 `/www/server/php/84/bin/php -d memory_limit=512M vendor/bin/pest`，日志为 `/tmp/laravel13idc-generated-defaults-final-{pest,phpstan,pint}.log`。

定向验证通过：生成器与原 CheckoutValidation **40 passed / 401 assertions**，表单集成 **8 passed / 188 assertions**；原变价确认、幂等和校验组合 **106 passed / 1209 assertions**。测试覆盖字符串/数组规则、默认长度、字符类别、前缀和长度冲突、不可满足的自定义格式、零 HTTP/SQL、旧密码不回显，以及 JSON 报价/409 不调用生成器。旧测试及 Specs 未修改。

原预览商品 3 的两个独立表单均生成 15 字符主机名，匹配 `^ser[0-9]{12}$`；密码均为 12 字符且包含大写、小写和数字，仅由字母数字构成。两表单主机名、密码与购买请求键分别不同；报价后同一表单的凭据和请求键保持，两字段清空后必填校验失败。页面首字节 **247.7 / 253.3 ms**，DOMContentLoaded **310.8 / 304.4 ms**；8 次本地报价均 HTTP 200，响应 **222.2–302.2 ms**、中位 **259.5 ms**，前台点击更新至可见结果 **306 / 316 ms**。背景标签曾受浏览器节流影响，其原始结果保留在证据中，不与前台响应混用。

浏览器未尝试 `/checkout`，无 JavaScript 错误；报价请求与响应均无凭据字段。59 张业务表、197 行原值保持，上游记录 **352→352**；schema、环境和封印不变，原父进程及四个子进程保持，jobs=0、21 条迁移。私有证据：`/root/.local/state/laravel13idc/preview-performance-c5__2nj8/generated-defaults-browser-result.json`，仅记录长度、规则验证与耗时，不保存或输出密码、密码哈希、CSRF 或报价凭证明文。真实预览仅进行页面及本地报价验收，不发送真实下单或供应商写操作。

IMPLEMENTED: 按产品配置生成表单主机名与开通密码。
NOT DONE: 真实购买和供应商写操作未执行。
DECISIONS: 使用本地规则快照生成并保留最终实时校验；异常规则提示手填，不放宽原规则。
CONCERNS: 同步快照可能过期，最终规则仍以下单实时核验为准。
GATES: Pest 1527 passed / 12604 assertions；PHPStan 0；Pint PASS；PHP语法、Node行为、真实预览页面及数据保持核对PASS。
SPEC CASES: 0。
