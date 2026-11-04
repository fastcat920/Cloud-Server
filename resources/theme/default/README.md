# Catboard default theme

This directory is the maintained source code for the built-in `default` user theme.
The current Catboard `/api/v1` endpoints are the only business data source. Build output is committed to `public/theme/default`, so production servers do not need Node.js.

## Develop

```bash
cd resources/theme/default
npm ci
npm run serve
```

## Build for deployment

```bash
cd resources/theme/default
npm ci
npm run build:theme
```

`build:theme` creates the production bundle and replaces `public/theme/default` with the generated assets and Blade entry file. Commit both the source changes and generated output in the same change.

Do not edit generated JavaScript in `public/theme/default/static` and do not implement features with DOM lookup or overlay scripts. Add routes, pages and API calls under `src` and rebuild instead.
