# 经典魔方兼容第三批：当前三款商品验收

日期：2026-11-19。基线 `7c18aad`。用户指示“开始下一批”，并明确“先完成当前三款商品”。按[本批方案](classic-compatibility-batch-three-plan-2026-11-19.md)检查 CDN391、API731 和云14，不新增真实订单、扣款或通知。

## 协议边界结论

这三款无需新增 `serverid`、独立 `os[]` 或开发者应用 `hostid`。参考源码为 `/www/wwwroot/zjmf-manger-decoded`，只读核对：

- `home/CartController::addToShop`（2850行起）将 serverid 定为可选数值；`Shop::addProduct`（244行附近）仅非空时校验；结算2155–2158行按 server_group 调用 `common.php::getServesId`（7064行起）自动选服。
- `common.php::cartCheckOs`（3092行起）只对 type=dcim 校验独立 os 数组。云14实际为 dcimcloud，使用 type5 配置组276852，选中子项通过 configoption 传递；结算2280–2283行从配置解析系统值。
- `common.php::checkDeveloperApp`（5600行起）按 p_uid>0 判断开发者应用；addToShop2941–2952行只对该类商品要求本人上游主机。当前三款 p_uid 全0，不需要绑定。

没有为不存在的需求增加模块、参数、列或新业务规则。已批准 S1 继续生效：CDN的新试用暂停，普通周期继续；缺完整同步事实或开发者应用仍收款前阻断。

试用不能只读静态上限后重开：`CartController::ontrialmax`（431–442行）返回主账号静态上限；`common.php::judgeOntrialNum`（4867–4913行）按实际主机和共享购物车计算，身份条件也属于供应商主账号。Host列表会排除部分代理客户主机，不能还原完整计数。上游结算及本地开通都有账户锁，但现有预检没有可跨客户付款窗口保留额度的凭证。本批没有新建本地试用次数表或用本地用户身份替代上游事实。

## 修复的实际展示问题

预览云14的 HTML 已显示售罄，但 Markdown 详情没有库存/不可售原因，仍给“查看产品与购买”。现已从同一个 `DescribeStoreProductAction` 结果输出库存、准入和无周期状态；售罄/禁售时链接文案为“查看产品详情”。`.md` 和 `Accept: text/markdown` 两种形式一致，恢复库存和不控库存的正常链接保持。

API731基础价格为0，必选面额最小1的完整估价为1元。HTML/Markdown 现明确标为商品基础价，并说明配置费用以完整报价为准；没有额外计算另一套最低价。LocalPriced卡密保留固定价格与库存文字。

## 最新真实配置与只读核价

先备份原预览，创建私有独立 SQLite、storage、配置/编译缓存和 array 邮件/队列/缓存环境。最新真实配置同步及调用日志只写该副本；原预览不做商品导入或账单写入。

| 商品 | 最新配置 | 真实只读核价 |
| --- | --- | --- |
| CDN391 | cdn，publisher0，无配置组；主机名/密码要求均显示，库存73 | monthly周期费0.01、设置费0、CNY，与本地一致 |
| API731 | software，publisher0，type4面额1–1000；主机名显示/密码不显示，库存485 | onetime面额1，周期费1.00、设置费0、CNY，与本地一致 |
| 云14 | dcimcloud，publisher0，13组44子项，type5系统13个；主机名不显示/密码显示，库存0 | 本地默认完整配置16.00；真实driver因库存0拒绝，没有调用该产品get_total |

实际远端调用共12条：登录1、完整配置3、主账号3、库存3、只读get_total2；没有购物车清空/新增、结算、付款、控制或通知调用。普通API读取会在供应商留下访问日志，不能称为完全无外部写入；没有供应商业务变更。

## 隔离与预览验收

- 最新三份快照沿原逐项算法跑189个配置样本：188正常、1试用按S1预期拒绝、0非预期失败，HTTP0。三款完整映射9组；云14全部13个type5系统子项在4个周期下共52次，保持完整13组配置及本地/上游映射。
- 副本样本阶段16表保持、setup期间12张非目录表保持；原预览11张受保护资金/身份/账单/履约/通知/设置表保持。
- `ClassicCurrentProductAcceptanceTest` 使用明确标注的合成形状与脱敏ID/金额，覆盖三款导入→本地无请求报价→实时确认→钱包Paid→fake P3/P4和幂等重放，核对OS及数量配置不丢失。云正链独立标注为合成可售分支；实际库存0仍拒单。高静态试用额度和未知字段均不能形成收款旁路。
- `ClassicCatalogAvailabilityContentTest` 先复现展示失败再修复，覆盖两种Markdown、库存恢复/不控库存、准入/试用禁售、无周期、基础价和卡密原文案。
- 实际预览健康、前后台登录及三款HTML/Markdown suffix/Accept共9个商品响应均HTTP200；基础价提示一致，云14三种展示均售罄。该检查只读取页面，没有新订单/付款/通知。

私有证据：`/root/.local/state/laravel13idc/classic-batch3-20261119-027dcnc4/`，包含一致备份、最新原始配置、`live-read-result.json`、`isolated-target-products/final-result.json`、`preview-http-result.json`。真实响应、环境和密钥不进入Git。

## 最终门禁与提交

编辑前门禁1959/16378通过。冻结后的完整门禁 **1987 passed /17026 assertions**（新增28例），PHPStan **0 errors**，Pint **PASS**；原报价、数量规则、type20联动三个 Node 脚本 **PASS**。Specs仍0，未修改既有测试或规范。独立复核发现合成夹具的主机名/密码显示开关与实品不一致，已按实际三款事实纠正并保留全部断言，最终全量通过。

最终原预览11张受保护表逐行哈希保持，数据库完整性ok、外键错误0、jobs=0、failed_jobs=0；默认消费者PID2162575、目录消费者PID2166913及目录派发timer均active/enabled。本批仅Web内容和测试，无需迁移或重启队列；迁移仍24条。

- `6db2801`：HTML/Markdown 可售事实一致及基础价提示，附20项回归。
- 三款8项合成购买链验收、计划/本记录和发布索引随后独立提交。本轮仅本地提交，未推送远端。

IMPLEMENTED: 当前三款普通售卖协议逐项核验、最新实品副本样本/OS完整映射、真实只读报价、合成Paid交付链、HTML/Markdown可售事实及基础价提示修复。
NOT DONE: 云14真实实购（上游库存0）；新type20实品目标及其他特殊模块/试用开放不在用户指定三款范围，本轮没有新增真实交付验收。
DECISIONS: 用户指定先完成当前三款；维持S1，不增加没有实际需求的参数/模块/schema，不以静态额度承诺试用资格。
CONCERNS: 只读报价不等于真实开通；合成云可售分支明确标注fake，实际云仍售罄。未来商品需按其真实模块协议另验。
GATES: Pest1987/17026、PHPStan0、Pint/Node通过；189样本符合预期、52次OS映射通过；两款真实只读价格匹配、一款库存正确拒绝；实际HTTP和11表保持、24迁移不变。
SPEC CASES: 0（未改动）。
