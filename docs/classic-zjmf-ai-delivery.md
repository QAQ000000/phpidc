# AI专区 API KEY：已有服务与兑换码同步验收

日期：2026-11-18。用户要求新增 AI专区的 apikey 商品，不付款，绑定已开通的 4066 并检查激活码。本轮沿用私有隔离验收库，不改正式业务数据库。

## ID 与真实结果

| 项目 | 认证查询及本地结果 |
|---|---|
| 上游商品 | PID **731**，名称 **API KEY**，一次性 onetime，CNY |
| 上游已开通服务 | host ID **4066**，属于当前 API 账户，Active，productid=731 |
| 本地商品 / 服务 | **2 / 2**，分类「AI专区」，服务名「AI专区 · API KEY」 |
| 上游交付栏目 | module_client_area：key=redeem，name=兑换码 |
| 已有激活码 | 真实返回 **32 位兑换码**；已进入加密凭据，未在文档或操作日志中记录明文 |
| 兑换入口 | `https://api.zhuzn.top/redeem` |
| 上游余额 | **5.85 CNY → 5.85 CNY** |
| 本地钱包 / 流水 | **0.99 CNY / 2 条**，不变；原账单 1、原任务 1 均不新增 |
| 原 CDN 服务 | 本地实例 1 / 上游主机 4291 原记录未变，仍保留 |

4066 是已购服务 ID，不能写进 products.upstream_pid 冒充商品 ID。本轮保留正确的商品映射 731，并另建既有资源绑定 4066；没有把原 CDN 改绑或伪造一笔 Paid 新购。

商品通过 `ImportClassicProductAction` 和 `SyncClassicCatalogAction` 导入配置；既有服务使用私有验收 Action，在事务中先锁用户、再核对商品并创建唯一实例绑定和原因审计，随后由 `ApplyInstanceSnapshotAction` 应用认证状态与凭据。私有导入脚本仅服务本次明确指定的验收目标，不声明已交付通用既有服务导入后台。

没有调用购物车创建、付款、发码、兑换、续费、暂停、终止或远端回调注册接口；既有上游服务的回调绑定保持原状。原验收库在导入前已单独备份。

## 已核实的交付协议

参考副本 `public/plugins/servers/sub2api/sub2api.php` 的 `ClientArea` / `ClientAreaOutput` 及 `templates/redeem.html`：该模块只在 state=delivered 且可解密已有 code 时展示兑换码；读取栏目不是生成或兑换。

真实认证 `/host/header?host_id=4066&source=API` 确认 Active 并广告 redeem 栏目后，读取：

```text
POST /zjmf_api/provision/custom/content
Content-Type: application/x-www-form-urlencoded
id=4066&key=redeem

status=200
data.html=<兑换码内容>
```

真实 HTML 中有唯一 `input#sub2api-redeem-code`，type=text、readonly、value 非空，以及 HTTPS `/redeem` 链接。代码仅提取该已核实结构，不执行或输出上游 HTML、脚本。空值、多个码、可编辑字段、未知模板均不作为已交付；失败响应明确报错。兑换链接只接受无凭据、无查询参数和 fragment 的 HTTPS `/redeem` 地址，其他链接不输出。

## 实现决策

- `ZjmfFinanceDriver::syncInfo` 复用已认证 header，仅在 Active 且广告 redeem 时读取内容；返回已有 `InstanceInfoResult.panelWidgets` 的结构化 redemption_code 数据。没有新增 DTO 字段、接口、枚举、表或列。
- Driver 保持纯 I/O；`ApplyInstanceSnapshotAction` 在现有锁与绑定复验之后，才把 card_key / redeem_url 写入既有 encrypted_credentials。HTTP 不在写库事务内（F10、INV-L3/L4）。
- 整段 html 继续由客户端日志脱敏，不记录兑换码明文（INV-U1）。本人服务页沿用卡密显示分支与 no-store 响应；已交付卡密时隐藏不适用的“登录面板”按钮。
- 不修改金额规则，不新增付款或任务，不借零价 checkout 再申请一个服务；商品价格与配置仍使用 Money 及上游真实配置。既有资源导入是用户指定的非采购验收，不替代购买结算路径（INV-P2）。

## 验收与边界

专项回归覆盖纯 Driver、不持新增事务做 HTTP、Active/栏目限制、失败与不明模板、密文存储、日志脱敏、本人可见/他人拒绝、旧绑定丢弃、无账务任务副作用和不安全链接拒绝。旧断言和 Specs 未改。

真实 Chrome 已登录隔离验收账户并打开 `/console/instances/2`，确认「AI专区 · API KEY」标题、完整 32 位卡密以及「前往 Sub2API 兑换」链接（href=`https://api.zhuzn.top/redeem`）均显示；此卡密页不显示无适用登录协议的“登录面板”按钮。随后通过正常 `ReconcileHostSyncAction` 再同步，状态仍 Active、兑换码一致，日志关联实例 2，原 settle / apply_credit 仍各一次。本轮浏览器会话及临时 loopback HTTP 服务均已关闭，监听已消失。

收尾 Gate：Pest **485 passed / 3796 assertions**；PHPStan **0 errors**；Pint **clean**；Specs **0**。本轮新增 10 个专项测试实例。没有提交、发布或部署正式站点。

私有原始响应、恢复配置及浏览器日志保存在 `/root/.local/state/laravel13idc/classic-live-20261117-5bbd1a71`；含敏感内容，不入仓库。激活码只证明可读取，**没有实际兑换，也没有查询其是否已被消费**；本轮授权不包括消费已有卡密。其他上游模块的激活码格式仍需另行核实。
