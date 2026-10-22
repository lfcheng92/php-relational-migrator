# 来源与权属边界 / Provenance boundaries

本实现依据用户给出的通用行为要求独立编写：关联数据迁移、依赖排序、字段转换、ID 映射、外键解析及明确的失败恢复。未读取或复制旧公司项目实现、表结构、凭据、真实业务数据、历史 Git 或任何旧项目的测试夹具。本仓库使用新建的合成实体及临时 SQLite 数据；未从任何已有项目复制 Git 历史。

通用需求背景仅用于确定要解决的问题，不等同于对旧实现的许可。重新实现本身不自动解决权属、合同、商业秘密或专利问题；不得据此声称已完成法律判断。未来公开之前必须由有权决定的人复核来源及权属，并明确选定可授予的许可。当前 composer.json 标记 proprietary，仅记录未授予开源许可的状态。

核心运行时代码没有第三方 Composer 依赖。PHP / PDO / SQLite 是运行环境，保留各自许可；开发工具安装在未纳入源码包的 vendor 中，许可清单见 THIRD_PARTY.md。没有将其擅自改成包自身许可。

The implementation was written from generic behavioral requirements, with new synthetic entities and isolated SQLite fixtures. No legacy company implementation, schema, credentials, real business data, or Git history was imported. Requirement background is not a license to legacy material. Reimplementation does not itself settle ownership or contractual rights. A separate rights/provenance review and explicit license decision are required before any public release.
