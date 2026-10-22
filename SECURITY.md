# Security / 安全说明

本包处理源键、新 ID 与映射指纹，可能涉及敏感数据。请在隔离、授权的数据副本中验证，使用最小权限连接；自行管理 SQLite 文件权限、加密、备份和保留期限。库不提供加密、脱敏或访问控制系统。

- 核心报告只含配置的实体名、阶段与计数；实体名和 scope 不应放入个人信息。原始异常和 PDO 堆栈可能携带 SQL 或值，禁止直接输出到公共日志。
- 标识符采取保守格式校验，SQLite 字段值使用绑定参数；转换函数与自定义适配器是可信应用代码，不是沙箱。不要从不可信输入反序列化配置或加载回调。
- SourceReader 应只读并提供稳定快照。TargetWriter 必须满足原子行写入及幂等凭据契约。不要把未经保护的 INSERT 适配器用于失败后的自动重试。
- 映射与写入凭据可能包含旧 ID；不要提交数据库、vendor、日志、auth.json、.env、私钥或令牌。开发依赖许可独立，依赖审计只是已知公告检查，并非无漏洞证明。
- SQLite 每次变更前复核支持的日志模式，拒绝 OFF/未知模式；这只保护进程内事务回滚。MEMORY 日志不保证进程崩溃恢复，synchronous 与底层存储决定更强的持久性；本包不自动配置或认证断电持久性。
- 本包不自动删除既有目标数据，也不提供通用回滚或业务预演。SQLite 单条事务不能覆盖其他数据库、网络调用或外部触发器副作用。
- CI 只有验证步骤，无发布凭据、仓库创建、可见性切换或 Packagist 发布动作。

发现问题请通过与维护者已经建立的私下渠道提供最小合成复现，不附真实业务数据、凭据或数据库转储。当前无远程仓库，未配置公共漏洞报告入口；未来创建私有仓库后再确认保密报告渠道。

Reports omit row data, but raw exceptions may contain sensitive values. Protect map/receipt databases and backups. Callbacks and adapters are trusted application code. The library provides no encryption, authorization system, arbitrary-database exactly-once guarantee, whole-migration rollback, or production certification. Report vulnerabilities through an established private channel with synthetic reproduction only.
