# Catboard 管理端源码

该目录是 Catboard 的可维护管理端，以 `sinalphabeta/v2board-admin` 为源码基线，并使用 Catboard 当前 API 和业务功能继续迁移。

## 本地开发

```bash
cp .env.example .env.local
pnpm install
pnpm dev
```

`.env.local` 只供本地开发使用。正式环境由 `resources/views/admin-next.blade.php` 注入站点、主题和安全后台路径，不再使用独立 `config.js`。

## 检查与构建

```bash
pnpm check
```

命令会依次执行类型检查、代码检查、测试和正式构建。构建产物直接写入：

```text
public/assets/admin-app/
```

构建产物需要与源码一起提交，部署服务器无需安装 Node.js。

## 管理端入口

```text
/{secure_path}/login
/{secure_path}/dashboard
```

旧压缩版管理端保留在 `/{secure_path}/legacy`，仅用于紧急回退。迁移阶段使用过的
`/{secure_path}/v2/*` 会自动跳转到对应的新正式路径。

## 授权

上游源码的 MIT + Commons Clause 和第三方声明保留在本目录的 `LICENSE` 中。
