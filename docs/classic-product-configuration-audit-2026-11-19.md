# 经典魔方财务产品配置兼容性审查

日期：2026-11-19（Asia/Shanghai）。代码基线 `9e99dff`。本轮按用户要求检查上游产品配置，不改业务源码、规则、schema 或测试，不创建订单、付款或发送通知。

后续状态：本文保留原审查检查点；发布保护、数量步进/零区间、下单资料展示、新购准入和目录运行已按 [第一批实施记录](classic-compatibility-batch-one-2026-11-19.md) 修复并验收。type20、完整试用资格及特殊模块协议仍未完成，不能将本文原始FAIL或后续局部通过外推为当前全部兼容。

结论：常规产品配置已有实现，当前不能声明支持上游全部配置。发现三项可在隔离副本直接复现的实现问题，以及层级联动、特殊模块、试用资格和详情展示的能力边界。当前三个实品的部分检查通过，不能代替未使用配置类型的兼容证明。

## 范围与证据等级

- 参考上游：`/www/wwwroot/zjmf-manger-decoded` 的经典魔方财务 3.7.7 自维护源码，重点是路由、`CartController`、`Shop`、`ConfigOptions` 和高级配置校验。此参考分支不等于当前远程供应商的全部版本。
- 检查本地同步、存储、配置表单、服务端解析、本地报价、提交实时核价、购买快照和开通参数，也检查升级/降配已声明限制。
- 复制当前预览 SQLite 为私有一致副本，隔离数据库、storage、编译目录、应用配置缓存和缓存存储。真实请求仅读取已接入三个产品的配置、用户币种、库存及报价；没有调用购物车新增、结算、付款或机器控制接口。
- 对导入失败、数量步进及高级约束的复现使用另一独立副本和 HTTP fake，禁止未声明请求，不连接真实供应商。
- 下文 `PASS` 表示列明范围通过；`FAIL` 是确定实现差异；`N/A` 是既有明确限制；`未验证` 不等于代码缺失。

## 当前真实产品检查

| 产品 | 上游 PID | 读取的配置 | 本地变更样本 | 实时核价结果 |
| --- | --- | --- | --- | --- |
| CDN体验 | 391 | 无可选配置；5个本地周期 | 5个通过 | 月付0.01CNY、试用1.00CNY，均与本地一致 |
| API KEY | 731 | 1个普通数量配置，面额1–1000 | 4个通过 | 默认面额一次性1.00CNY，与本地一致 |
| 洛杉矶轻量弹性云 | 14 | 13个配置、44个子项，含系统/CPU/内存/区域/磁盘/网络等 | 4周期共180个通过 | 默认16.00、变更CPU42.00是本地估价；两次实时请求均因上游库存0被提前拒绝，未取得实时金额 |

189个本地样本为各周期默认配置及逐个选项的变更，包括数量边界；不是所有选项的笛卡尔组合，也没有逐项真实购买。三个产品的上游/本地版本分别为63/63、14/14、82/82，均没有type20、非零父子级联、高级约束或产品自定义字段。

库存另有运行层面的差异：CDN上游73/本地75，API KEY上游486/本地487，云产品上游0/本地2。当前只启用default消费者，目录调度与catalog消费者尚未启用，因此同步库存不能视为实时库存。云产品的提交阶段检查已正常阻止无库存购买；本地配置预估仍可计算，并不代表可下单。参见 [报价的实时库存检查](../app/Drivers/Upstream/ZjmfPurchaseProtocol.php#L68)。

## 确定问题与能力缺口

### F1 / P1：完整配置导入失败，基础商品仍公开

后台 [ClassicOperationsController](../app/Http/Controllers/ClassicOperationsController.php#L80) 先调用导入Action并提交，再调用配置同步Action；两步没有一个共同的发布成功条件。[ImportClassicProductAction](../app/Actions/ImportClassicProductAction.php#L50) 的新商品默认可见，[公开目录](../app/Models/Product.php#L73) 也不要求完整配置快照。

隔离复现：给新商品配置type20，基础导入成功，后续同步抛 `Unsupported upstream option identifier or type.`；商品仍存在、`is_hidden=false`、`publicCatalog`可见，但没有`classic_catalog`和配置组。管理员看见失败提示，客户却可能看见不完整商品。

建议先修发布条件：完整读取、能力校验及配置同步成功后才公开；失败不得留下公开半成品，也不能以忽略未知配置让导入成功。本轮没有修改现有商品。

### F2 / 配置能力缺口：type20层级联动未支持

上游共有20类配置；`app/app.php:23` 包含 `RadioLevelLinkAge`，`app/common/logic/Shop.php:263`、`ConfigOptions.php:873` 有真实执行分支。它依靠配置项的 `linkage_pid/linkage_top_pid` 与子项的 `linkage_pid/linkage_level` 表达父子路径。

[同步](../app/Actions/SyncClassicCatalogAction.php#L58) 和 [本地报价](../app/Actions/QuoteLocalProductAction.php#L35) 仅接受1–19。同步保留的元数据白名单也没有这些父子关系，表单/JS只有平面选择和高级约束逻辑。type20商品因此直接同步失败，不能按普通select接入。

此项是明确未交付的配置能力，不是已证明的资金漏洞；与F1组合会产生半成品发布问题。当前三个实品未使用type20。

### F3 / P2：高级约束的数量区间[0,0]判断错误

[ResolveProductOptionsAction::matches](../app/Actions/ResolveProductOptionsAction.php#L104) 以 `qty_minimum || qty_maximum` 判断是否为数量范围；上下界都为0时转而按子项ID匹配，前端 [classic-config.js](../public/js/classic-config.js#L6) 同样如此。

隔离复现：数量条件为[0,0]、实际数量0时，本应触发的结果限制未触发，不合法结果被放行；把条件改为[0,1]，同一不合法结果正常被拒绝。反向把结果限制设为数量[0,0]时，合法数量0又会被错误拒绝。

上游 `app/common.php:6093` 按配置类型识别数量，再做包含端点的范围比较；`AdvancedOptionsController.php:504` 允许相等的合法上下界。因此[0,0]是有效规则。该问题影响本地联动/解析判断，不表示全部advanced均未实现，也没有证据表明当前三个产品已触发或产生资损。

### F4 / P2：上游qty_stage没有成为实际数量步进

上游真实字段是 `qty_stage`，见 `ConfigOptions.php:148`、`ConfigOptionsController.php:1424`；本地 [同步](../app/Actions/SyncClassicCatalogAction.php#L70) 却用 `qty_step` 设置实际step，缺失时取1，`qty_stage`只留在meta。

隔离复现：type4、min=1、max=1000、`qty_stage=5`、没有`qty_step`，同步后meta保留5但step=1，解析接受数量2。服务端与 [表单](../resources/views/sales/config-options.blade.php#L4) 均只用step，未实现上游步进体验。

还需保留真实上游语义：上游前端允许“5的整数倍，或者min/max端点”，不是简单的 `(value-min)%step==0`；上游PHP购物车主要做min/max钳制，不能把所有非倍数声称为远端必定拒绝。当前API KEY的步进为1，没有触发本差异。

### F5 / 特殊模块能力缺口：hostid/serverid/os[]没有接入

上游 `CartController.php:2850,2860,2873,2941` 额外接收 `serverid`、`os[]`、`hostid`。开发者应用产品明确要求有效且属于上游账号的`hostid`，缺少时返回400；`/cart/get_product_config` 本身不提供服务器及可绑定主机列表，另一配置页面接口在同文件1199–1247行提供相关信息。

本地 [结账输入](../app/Http/Controllers/CheckoutController.php#L30) 与 [开通传参](../app/Drivers/Upstream/ZjmfPurchaseProtocol.php#L149) 没有这些参数及选择流程。因此开发者应用不能完整购买，需要额外选择服务器或独立OS参数的模块也不能保证兼容。普通type5操作系统选项已接入，不能与独立`os[]`参数混淆。

这是源码确认的条件性能力缺口；当前三个实品不作为该模块的验证样本。支持之前应明确拦截不兼容产品进入收款流程。

### F6 / P1条件风险：试用资格和上游配额可能到付款后才失败

本地 [新购预校验](../app/Actions/CreateComboInvoiceAction.php#L60) 校验配置、密码及实时报价；[上游报价协议](../app/Drivers/Upstream/ZjmfPurchaseProtocol.php#L68) 检查币种、库存和金额，没有核验上游试用次数或身份资格。`pay_type`虽被保存，不能据此认为相关限制已执行。

参考上游报价阶段也不检查这些资格；`CartController.php:2880` 的P3才检查试用次数，2115–2136行的P4再次检查次数和实名/邮箱/手机/微信条件。上游启用相关限制时，本地可能已收客户款，开通任务才被拒绝。`/cart/ontrialmax`只返回账号配额，不是剩余次数或已满足身份要求的完整证明。

此项为代码证实的触发顺序风险，未实购触发。当前CDN配置有3小时试用、无身份条件，本轮试用报价成功；未验证剩余次数或试用完整购买/到期流程。不能用“报价成功”作为资格保证。实现时应先确定可验证的资格/额度接口及并发处理，证据不足的受限产品应在收款前阻断，不以假定剩余额度放行。

### F7 / P2：自定义字段showdetail有存储设计，没有详情展示

上游字段 `showdetail` 被 [同步](../app/Actions/SyncClassicCatalogAction.php#L109) 保存为`show_on_detail`，但业务查询/视图没有读取；实例`custom_field_values`也没有对应填充链路。[详情查询](../app/Services/CustomerPortalQueries.php#L71) 与 [详情页面](../resources/views/sales/instance.blade.php#L53) 仅展示现有交付字段。

因此下单填写的产品自定义字段无法按`showdetail`在服务详情展示。其购买值仍通过加密参数传给上游，不能误报为下单参数丢失。description/sortorder/showinvoice同样未完整保留使用，属于展示差异，不能拔高为开通失败。后续展示仍需遵守本人授权和私密字段处理。

## 已支持和既有明确限制

| 能力 | 本轮结论 |
| --- | --- |
| type1–19基础映射、真实配置ID和子项ID、普通数量范围 | 源码已实现；PASS的逐类型参数化主要证明本地解析/计价，完整同步/表单/传参未逐类型端到端证明；实际三个产品只覆盖其中部分类型 |
| type3是/否 | PASS：启用传真实subID正确；报价的0/1是数量标记，不是协议字段错误 |
| 15–19阶梯新购本地计价 | PASS：累计前段期费、只用当前段初装费，符合正常连续区间的上游主语义；不能再报为未实现 |
| advanced的seq/sneq和普通数量区间 | 前后端均有源码实现，现有回归主要证明seq链路；sneq本轮未单独参数化实测，[0,0]边界见F3 |
| config_links | N/A：它是商品绑定配置组的gid列表，配置组已展开；未单独保存不等于遗漏高级联动 |
| 自定义text/textarea/dropdown/password/link/tickbox | 有同步/表单/必填/成员/正则实现；当前真实产品无这些字段，完整类型端到端仍未验证 |
| 自定义字段only_admin/default | N/A：该上游API先过滤adminonly/showorder，并不返回任意默认字段；不能凭本地未接收判定丢失 |
| 主机名、密码生成及下单/开通前规则复验 | PASS：现有实现及相关回归通过 |
| 免费产品 | 有周期/价格和报价字段支持；未真实完成经典free购买，不能仅凭枚举标完整验收，也未发现“免费报价缺金额字段”的确定问题 |
| 试用、小时/天及多年周期 | 有枚举/同步；不同组合没有逐一实购，试用资格边界见F6 |
| 阶梯配置升级/降配 | N/A：既有批准边界明确拒绝type15–19，不能将新购支持扩展成生命周期全部支持 |
| 阶梯或非空qty_stage绑定复核 | N/A：现有规则明确阻断无法证明的价格核对，不在本轮开放 |
| 多商品数量、非CNY支付、V10 | N/A：当前购买qty固定1、钱包/易支付仅CNY，经典接入不含V10 |
| 上游折扣/账号优惠 | 本地为估价，提交实时核价及价格变化确认仍保留；不承诺同步价表重现所有上游优惠 |

## 验证与私有证据

相关8文件隔离回归：**202 passed /2357 assertions，13.01秒，exit0**。显式使用SQLite `:memory:`、array缓存/邮件/session与HTTP fake，未继承预览进程环境。运行文件如下：

```text
tests/Feature/LocalCatalogQuotationTest.php
tests/Feature/ClassicExpansionTest.php
tests/Feature/InstanceMappingReviewTest.php
tests/Feature/ClassicQuotationFreshnessTest.php
tests/Feature/GeneratedProvisioningDefaultsTest.php
tests/Feature/GeneratedProvisioningFormTest.php
tests/Feature/CheckoutPriceConfirmationSecurityTest.php
tests/Feature/ZjmfPurchaseTest.php
```

私有目录 `/root/.local/state/laravel13idc/classic-config-audit-20261119-u766mdbf/`：

- `summary.json`、三个`upstream-product-*.json`：当前上游完整配置快照；不进入Git。
- `samples-result.json`、`stock-result.json`：189本地样本、5次真实只读核价尝试及库存核对。
- `repro-local-config-gqa9gtb4/result.json`：F1/F2和F4隔离复现；`zero-dependency-result.json`：F3条件漏判/结果误拒的对照复现。脚本同目录保留。
- `preview-preservation.json`：原预览16张关键业务/资金/通知/任务表与一致副本逐行保持，健康与前后台登录HTTP200，常驻默认worker仍active/running。无新订单/付款/通知。

修复顺序建议：先关闭半成品发布路径、修复高级零区间与数量步进；再实现type20完整父子选择，补特殊模块与试用资格的收款前能力判定；最后补齐产品自定义字段展示。库存同步的消费者启用属于另一个运行范围，不在只读审查中自动开启。

IMPLEMENTED: 本轮仅完成兼容审查与证据记录；常规能力依现有源码实现。
NOT DONE: 本报告F1–F7未修复或开放；type20/特殊模块/受限试用的实购及免费等完整验收未执行。
DECISIONS: 仅检查；不改变既有资金/生命周期规则，不执行新增实购、开通或通知，不启用调度器。
CONCERNS: 三个现有产品未覆盖全部类型；云产品上游库存0，不能把本地报价成功视为可购买。
GATES: 202相关隔离测试PASS；189本地变更样本PASS；3次实时核价匹配、2次因库存0正确拦截；三项隔离复现确认FAIL；原预览数据/HTTP/worker保持。
SPEC CASES: 0（未改动）。
