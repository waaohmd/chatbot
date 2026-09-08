# Chatbot 与本地管理后台使用说明（中文）

本说明适用于公开网站 Chatbot、WordPress 插件和本地管理后台。文档不包含任何 API key、连接码、密码或真实用户数据；所有敏感值都必须由管理员在部署环境中填写。

## 1. 安装

### WordPress 插件

1. 在 GitHub 下载最新插件 ZIP，确认 ZIP 内第一层目录为 `mustdohr-site-assistant/`。
2. 进入 WordPress 管理后台 → 插件 → 安装插件 → 上传插件。
3. 选择 ZIP，点击“现在安装”，安装完成后点击“启用”。
4. 如果站点已有旧版本，先停用旧版本，再安装新版本；不要同时保留两个同名插件目录。
5. 打开网站首页，确认搜索框、AI 入口和 Contact Form 正常显示。

### 本地管理后台

```bash
cd chatbot-admin
npm install
npm run dev
```

浏览器打开 `http://localhost:3010/local-admin`。Windows 可双击仓库根目录的 `start-admin.bat`；macOS/Linux 可运行仓库根目录的 `start-admin.sh`。

## 2. 启用管理后台

1. 在“WordPress API URL”填写目标站点的 REST 基地址，例如：
   `https://example.com/wp-json/mustdohr-search/v1`
2. 在“Connection code”输入站点生成的短期私有连接码。
3. 点击“Connect and archive”。连接码只保存在当前 15 分钟会话，不写入项目文件。
4. 看到“connected”后，刷新记录区即可查看聊天和 Contact 数据。
5. 结束管理时点击 Disconnect；不要把连接码提交到 GitHub、工单或聊天记录。

## 3. 设置消息通知

1. 连接站点后，在“Notification email addresses”填写收件地址；多个地址可用逗号或换行分隔。
2. 点击“Save changes”。空白收件人不会再回退到 WordPress 管理员地址。
3. 在 WordPress 的 FluentSMTP（或其他 SMTP 插件）中配置发件账号、SMTP 主机、端口、加密方式和发件人地址。
4. 先发送一封 SMTP 测试邮件，再提交一次测试 Contact Form。
5. 只有 Contact Form、重要未回答问题、敏感问题或配置的安全事件才会触发通知；历史邮件日志不会因改地址而改变。

## 4. 拉取聊天记录

1. 连接成功后点击“Refresh records”。
2. 本地管理后台会读取聊天记录和 Contact submissions，并保存到本机 `data/` 目录。
3. 可按网站、日期、客户名称、邮箱和类别筛选。
4. 使用 Export CSV 下载当前筛选结果；导出的文件只保存在本机。
5. 若站点启用了归档策略，确认本地写入成功后再执行“archive/clear WordPress records”。删除动作只针对已成功归档的记录。

## 5. 发信原理和邮箱

- Chatbot/Contact Form 在服务器端调用 WordPress `wp_mail()`。
- FluentSMTP 接管 `wp_mail()`，再通过管理员配置的 SMTP 服务投递。
- 发件人地址由 FluentSMTP 的 Sender Settings 决定；通知收件人由 Chatbot 管理后台的“Notification email addresses”决定。
- 浏览器不会直接连接 SMTP，也不会接触 SMTP 密码。
- 邮件失败时，记录仍应保留在后台，并显示可重试提示；请先检查 SMTP 测试、发件人验证和站点 DNS/SPF/DKIM 状态。

## 6. Bot API 相关

插件提供以下同一 REST 命名空间下的接口（以目标站点为准）：

- `GET /wp-json/mustdohr-search/v1/config`：读取公开配置（不返回密钥）。
- `POST /wp-json/mustdohr-search/v1/search`：关键词搜索公开网站内容。
- `POST /wp-json/mustdohr-search/v1/ai`：基于公开内容生成 AI 回答。
- `POST /wp-json/mustdohr-search/v1/chat`：写入一条聊天记录。
- `POST /wp-json/mustdohr-search/v1/contact`：提交 Contact Form。
- `GET /wp-json/mustdohr-search/v1/records`：受私有连接码保护的记录读取接口。
- `GET /wp-json/mustdohr-search/v1/contact-submissions`：受私有连接码保护的 Contact 读取接口。

公开接口只应返回公开网站内容；私有记录接口必须校验短期连接码、权限范围、来源和速率限制。

## 7. Bot 快速管理功能

在本地管理后台的“Website Assistant”区域可直接修改：

- Chatbot 总开关；
- 欢迎语、品牌名称和 AI 说明；
- 常见 Q&A 的新增、删除和排序；
- 访客提问上限；
- 敏感关键词及标准回复；
- Contact 触发关键词和引导方式；
- Contact Form 链接或内嵌表单开关；
- 通知邮箱；
- 知识库 URL 和排除 URL；
- Quick access 问卷开关。

保存后刷新公共网站验证。关闭 Contact Form 或 Chatbot 前，确认已经向访客提供替代联系方式。

## 8. 给其他 AI 继续维护工程的提示词

> 你正在维护一个 WordPress Chatbot + 本地管理后台项目。先阅读根目录 README、本文件、`chatbot-repo/` 和 `wordpress/` 下最新版本源码。只使用公开网站内容回答问题；私有聊天记录和 Contact 数据只能通过短期连接码读取。不要读取、打印、提交或猜测任何 `.env`、API key、密码、连接码、Cookie、数据库或真实用户数据。修改前先说明影响范围，保持现有 API 路径和数据字段兼容；完成后运行构建、接口冒烟测试和敏感信息扫描，并报告修改文件、测试结果及回滚方式。不要把密钥写入前端、Git、日志或文档。

## 9. 注意事项

- 不要把 `.env.local`、数据库、CSV、聊天记录、Contact 导出或备份目录提交到 Git。
- 不要在公共 Q&A 中放入内部资料、员工隐私或客户隐私。
- 插件升级前保留可回滚的 ZIP；升级后检查插件是否启用且目录结构正确。
- 生产环境使用 HTTPS，并定期检查 WordPress、主题、插件和 SMTP 服务更新。
- AI 无法从公开页面确认答案时，应明确说明并引导 Contact Form，不要编造信息。

## 10. 安全防护

- 连接码采用短时会话、权限范围和失败限速；不要长期保存在浏览器或文件中。
- 记录读取、导出、删除、配置修改和密钥状态查看都应写入审计事件，但审计内容不得包含密钥、密码或聊天正文。
- 聊天和 Contact 写入接口使用字段白名单、长度限制、速率限制和幂等键，防止机器人批量塞满数据库。
- 所有数据库查询使用参数化语句；HTML 输出统一转义；REST 请求校验来源、权限和 nonce/连接码。
- API key 只放服务器环境变量或受保护的 WordPress 设置；前端只显示掩码或状态。
- Contact 邮件只发送必要的客户信息；完整聊天记录只在授权管理后台查看。
- 定期导出、检查磁盘空间、清理过期临时文件，并设置数据库备份和恢复演练。

## 11. 技术栈

- WordPress 插件：PHP、WordPress REST API、`wp_mail()`。
- 公共 Chatbot：原生 JavaScript、CSS、WordPress REST API。
- 本地管理后台：Next.js/Vinext、React、Node.js、npm/pnpm。
- 本地数据：SQLite/JSON 文件，按项目配置保存；私有记录默认写入本机 `data/`。
- 邮件：FluentSMTP 或兼容 SMTP 提供商。
- 安全组件：短期 Connection Code、HTTPS、速率限制、参数化查询、输入校验、输出转义和审计日志。
