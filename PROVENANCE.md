# 来源与许可边界 / Provenance and licensing

本仓库依据通用行为要求独立编写：关联数据迁移、依赖排序、字段转换、ID 重映射、外键解析和明确的失败恢复。根提交建立了新的独立代码与合成测试数据，后续提交记录了改进；未从旧公司项目导入实现、表结构、凭据、真实业务数据或 Git 历史。

根据上述已记录来源及维护者授权，本仓库原创实现、测试、示例与文档按根目录 LICENSE 中的 MIT 条款提供。Copyright 归属保留为 Lfcheng 及贡献者。本声明记录本仓库的来源和许可范围，不对其他项目的权属作判断，也不授权重新许可公司或第三方代码。不能仅凭“重新实现”就断言其他权利问题已被自动解决。

运行核心没有第三方 Composer 包依赖。PHP / PDO / SQLite 是外部运行环境，保留各自许可。开发工具及其传递依赖由 Composer 安装在不纳入源码发行物的 vendor 目录中，保留上游 MIT 或 BSD-3-Clause 条款；锁定版本和许可记录见 THIRD_PARTY.md。本包的 MIT 许可不替换那些项目的版权或许可证。

This repository was independently implemented from generic behavioral requirements, using a new Git history and synthetic fixtures. No legacy company implementation, schema, credentials, business data, or Git history was imported.

The original implementation, tests, examples and documentation are provided under the root MIT license, as authorized by the maintainer. Copyright attribution remains with Lfcheng and contributors. This statement records the boundaries of this repository; it does not decide ownership of other projects or grant rights to company/third-party material. Reimplementation alone is not a general resolution of ownership issues.

There are no runtime Composer packages. PHP, PDO, SQLite and development dependencies retain their own licenses. Development tools are excluded from the source distribution; see THIRD_PARTY.md for the locked dependency inventory.
