# FIX-007～017：实现与验收（2026-11-18）

本轮沿用 FIX-006 后的未提交工作区，开工 Gate 为 Pest 753 / 6371 assertions、PHPStan 0、Pint clean、Specs 0。C24/C25 已获人工批准，仅纠正跨栏目字段错误和旧 log 通道三处断言。其余旧断言、Specs、冻结契约/DTO/枚举、schema、业务不变量保持原约定。

## FIX-011 验证前确定的容量与时效目标

初始部署支持目标：1000 台活跃可续费服务，其中临近到期候选不超过 100 台；单任务平均处理（包括本轮所有请求）不超过 3 秒，供应商无持续错误、重试或额外积压。独立 urgent worker 1 个、rotation worker 1 个、catalog worker 1 个，default worker 另设，禁止混用队列。轮转默认 100 台/分钟，近到期默认最多 100 台/分钟；商品默认 100 件/5分钟。上限分别 100、100、200，通过 SettingService 的 lifecycle.urgent_batch / rotation_batch / catalog_batch 调低或配置。调度进程自身不允许同步队列，不做 HTTP。

- 轮转派发：1000 台至多 11 轮（含游标回绕），含本地 next_due_at 为空的可续费服务。只依赖本地到期过滤会漏掉陈旧日期，因此保留独立全量游标。
- 正常轮转处理目标：75 分钟内一次认证同步（11 分钟扫描上界 + 单独 worker 约 50 分钟，预留余量）。临近到期目标：10 分钟内一次检查（在上述最多 100 台的容量假设内）。这是部署容量目标，不是已通过生产 SLA；按分钟派发不意味着按分钟完成。
- urgent 和 rotation 各有专用 worker，避免始终优先 urgent 导致 rotation 饥饿。一个实例只允许一个排队 unique job（TTL 3600 秒），执行还共享 host-sync 锁，锁 210 秒、job timeout 180 秒、retry_after 240 秒；与回调同步共用实例锁。unique 不能替代数据库幂等；停 worker/积压超过 unique TTL 时可能有重复排队，执行锁和账期/任务唯一键仍必须有效。
- 三次尝试、退避 60/300/900 秒；预期业务拒绝脱敏记录并转核对，未知程序异常暴露。开始执行前核对绑定SHA256指纹（Job仅保存实例ID和指纹，不保存token/供应商凭据），认证读取后再复验。自动暂停/恢复仍走原任务协议，代理服务不自动终止。
- 已有认证到期边界的账单先查重，避免同边界每轮再走续费入口；首次建账单仍执行原续费入口的独立预同步/报价，不以减少请求取消财务保护。
- 部署验收监测 urgent 最老待办 >5 分钟、rotation >30 分钟或 last_synced_at 超过75分钟即告警/调查；供应商变慢、规模/临期数量超标需重新测量并调整容量，不承诺故障下时效。当前未部署监控平台或 systemd 单元。
- 分钟 timer 服务限时55秒；供应商 I/O 已移出，但大量过期/佣金扫描仍是既有同步数据库任务，生产数据库负载须另验。不能把该上限理解为所有故障都不会错过调度。

## 实现边界

| FIX | 结果与边界 |
|---|---|
| 007 | 29 个 Web 路由改用具业务前缀的命名额度；用户按 ID、游客按 IP。新购/恢复报价共同使用 quote 额度；机器登录/监控/电源/读管理/写命令分开。保留原次数，HTML429和电源查询读取 Retry-After，只允许手动重试。 |
| 008 | 编辑锁原栏目，篡改返回 category_id 错误；不存在 ID 仍404。未开放移动文章；C24只纠正一处旧断言。 |
| 009 | 原栏目 type 不可改，界面锁定且服务器校验；news/help同slug可共存。未自动修复未知历史 URL。 |
| 010 | 登录仅login_form，监控仅machine_status/chart；单图错误独立不可用；前后核对所有权、绑定、状态、启用、端点和账户认证配置。不开登录秘密缓存。 |
| 011 | 分钟命令有界派发；生命周期/目录有专用队列、轮转、防重入和部署模板。同步 execute 库入口保留兼容，例行调度不调用它；手工单商品同步仍可同步执行。 |
| 012 | Telegram明确ok=false/error_code=429及SMTP明确4xx才自动重试；保留provider Retry-After，至少60/300秒退避，总计最多3次。超时/不明确响应保留人工核对；永久拒绝不重放。逐尝试写日志，最终耗尽进failed_jobs。log邮件/短信统一simulated，sent仅表示传输接受，非已读。 |
| 013 | 供应商与商品边界分别处理DomainException/ValidationException；单商品失败不跳过其余商品；汇总成功、商品失败、账号失败。未知程序错误继续抛出，日志不带原响应或异常消息。 |
| 014 | 摘要按正文SHA256+格式+渲染版本缓存24小时；内容变化立即换键，渲染算法变更须升级键版本。详情复用安全HTML；SEO描述仍优先，正文净化保留。冷缓存仍需解析，非预生成持久摘要。 |
| 015 | 索引仅投影元数据、每100条惰性遍历；不累积模型或正文。普通HTTP响应仍保留最终XML/文本缓冲，内存随输出字节增长，未做wire streaming、sitemap分片或超过50000URL的协议扩展。 |
| 016 | 总览最多6个商品，JSON仅提取一次性价格与表单所需规则，不读取classic_catalog全量配置；保留原快速购买表单和本人账单/实例。其他商品从商城分页进入，购买校验仍在Action。 |
| 017 | 章程标注当前Blade客户门户与历史阶段目标；候选版本保留历史验收、追加日期及本轮索引。Specs为0不等于PVE/生产全部完成。 |

## 通知协议、运维和保留风险

Telegram依据官方 https://core.telegram.org/bots/api#responseparameters 的 retry_after 和明确失败响应；SMTP依据本地 Symfony Mailer 的 UnexpectedResponseException 三位状态码。未核实供应商协议的5xx不自行视为可重放。

现有 bool 驱动接口保持；仅可确认的临时拒绝抛出脱敏 RuntimeException，绝不串联原异常。Idcsmart短信保留 bool 和手机号验证行为，用单次内存标记区分不确定结果，不改变短信供应商契约、不开启真实短信。配置缺失/模板错误明确失败。未知发送异常保留既有脱敏失败日志，不保证自动可恢复。

通知每渠道独立，失败记录位于管理后台“运营扩展/通知”和“任务监控/失败队列”。核对用户、事件、时间、渠道、尝试记录及供应商接受记录，确认从未接受后才可针对 UUID 人工重试；结果不确定禁止直接重试。通知未引入持久事件 outbox 或供应商幂等键：外部接受后进程退出存在重复发送窗口，不能承诺 exactly-once；failed_jobs可能包含收件人/安全变量，数据库及备份访问仍应受限。永久拒绝/结果不确定记failed日志但不进入自动重试队列；最终临时拒绝耗尽进入failed_jobs。

## 实测证据

代码版本：HEAD `16b5e19` 之上的当前未提交工作区，保留此前 FIX-001～006 与用户既有文件。本轮没有Git提交或远程发布。新增 `tests/Feature/BackendRemediationTest.php` 覆盖限流、路由保护、队列公平轮转/唯一性/绑定、通知真实worker重试/耗尽/不确定结果、CMS缓存/投影与后台安全分类；只按C24/C25修改三处旧断言。

`python scripts/verify-backend-remediation.py`：临时SQLite、独立storage/密钥、file-cache/database队列，所有HTTP禁止真实网络。1000实例、临近到期20台，5000文章每篇约32770字节正文，两个独立PHP进程验收慢上游与分钟调度。

| 场景 | 样本结果 |
|---|---|
| 有界派发 | 候选100、排队100，819.82ms；未发HTTP |
| 重叠worker | 相同实例任务共享锁，延后60秒，重复HTTP为0 |
| 慢上游时分钟任务 | 认证header模拟3秒等待并保留屏障；6个分钟/五分钟任务全部运行，2863.70ms完成；账单过期成功，未等待释放上游屏障 |
| 单实例worker | 含屏障3615.04ms，成功同步1台，failed_jobs为0；不代表1000台端到端时效已被压测 |
| sitemap+llms | 5000文章元数据，分块查询102次，663.28ms；进程峰值40.5MiB、相对增量4MiB，输出682798字节，不选择正文 |
| 六篇10KiB摘要 | 冷219.54ms / 热0.70ms，净化6 / 0次，峰值44.5MiB |
| 六篇100KiB摘要 | 冷1800.40ms / 热1.01ms，净化6 / 0次，峰值62.5MiB |
| 六篇500KiB摘要 | 冷7696.29ms / 热2.55ms，净化6 / 0次，峰值152.51MiB |

**FIX-014保留风险**：高密度复杂Markdown的500KiB样本在默认128MiB进程触发内存上限；上表500KiB只在脚本专用256MiB诊断进程完成，未修改项目/FPM内存配置。冷缓存仍需全量渲染，失效后的首个长文列表/详情有耗时和内存风险；热缓存及安全复用修复不能冒充冷解析问题已消除。保存接口既有1,000,000字符上限也不等于每个允许输入都能在128MiB解析；进一步限制正文复杂度/长度或异步预生成应单独评估，不能通过取消净化处理。本轮未实施持久摘要字段。

`node scripts/verify-power-status-rate-limit.js`：独立DOM/fetch替身验证429在解析JSON前处理Retry-After秒数和HTTP日期，禁用按钮到期限，不自动重发；无浏览器/真实上游写操作。

`python scripts/verify-backend-remediation-mysql.py`：本地MySQL8.0.35镜像，--pull=never、--network none、独立socket/schema/storage，finally只清理本轮容器。默认仅执行新修复、面板登录及购买幂等专项，结果见最终Gate记录。JSON标量投影/限流/队列/通知/页面相关行为在MySQL实际执行；不是生产负载或公网验收。

另外曾扩大到历史CMS/目录/遥测套件：**136 passed / 4 failed**，四项均为MySQL JSON对象键序重排导致严格toBe数组比较不通过（值与字段相同）：CmsAdministrationTest产品展示不变、CmsStorefrontTest展示更新、ClassicExpansionTest周期/初装费、ClassicCloudTelemetryTest能力字段顺序。保留四处旧断言、不转换为跳过、不以默认专项通过隐瞒扩展结果。用 `--full` 可重现该扩展；本轮不声明全套MySQL Gate通过。

真实生产负载、真实通知投递和供应商机器写操作未执行。预览PID1448041保持，/up只读复查200；未迁移该隔离库（仍缺C22/C23迁移），不能用新版checkout/续费恢复入口做该库验收。源码修复不等于现有预览库可直接升级。未关闭浏览器/VNC。

最终三道Gate及日志路径见下方收尾记录。

## 收尾记录

2026-11-18：完整默认 **Pest 782 passed / 6605 assertions**（基线753，新增29个展开用例）；**PHPStan 0 errors**、无ignore；**Pint clean**。离线MySQL专项 **73 passed / 615 assertions**。Specs完整性计数仍0，`pest --group=spec`显示No tests found，不作为新的已实现规格计数。本轮没有从Specs移动用例。

日志：`/tmp/backend-fix007-017-before-{pest,stan,pint}.log`、`/tmp/backend-fix007-017-final-{pest,stan,pint}.log`、`/tmp/backend-fix007-017-mysql-final.log`、`/tmp/backend-fix007-017-performance-final.log`、`/tmp/backend-fix007-017-spec.log`。扩展MySQL四项键序差异的原始失败记录保留在`/tmp/backend-fix007-017-mysql.log`，不纳入“全绿”的表述。

开发中可解释的问题及处理：新摘要用例暴露尾部空白，摘要生成统一trim；新目录测试先保存模型在内存的属性顺序，再和数据库读回严格比序，修正新测试夹具为两边均读取持久状态，未改变旧断言；性能夹具补齐既有display_name必填字段，避免HTTP fake重复注册的旧回调先行拦截，并用独立进程屏障证明调度期间上游仍阻塞；复杂500KiB诊断样本明确改用专用256MiB进程并保留128MiB失败证据；账户认证指纹改为从原始模型属性稳定取值，保留原关联泛型注释，解决静态分析的错误推断，没有关闭类型检查。最终绑定/端点/秘密轮换用例全部通过。

DECISIONS：C24/C25只定向纠正错误旧期望；F07运行时批次设置走SettingService，INV-L3网络不跨事务，INV-K1/K2保留账期和任务幂等，INV-K3调度锁保持，INV-Q1按180<210<240配置。独立轮转worker保证不被urgent长期挤占。bool通知接口不改，只有已核实的明确临时拒绝允许重放。FIX-017是既有交付状态澄清，不把原阶段目标当完成声明。

CONCERNS：无生产容量/真实投递/新队列部署验收；通知无持久事件outbox，接受后进程中断仍可重复；复杂长文冷解析和超大索引最终输出缓冲保留；扩展MySQL四项严格JSON对象键序断言未修；原预览库C22/C23迁移未应用，不在本轮直接升级。FIX-018～023仍未交付。未提交Git、未迁移既有数据库、未停止项目。
