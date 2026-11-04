/// <reference types="vitest/config" />
import path from 'node:path'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const root = import.meta.dirname

export default defineConfig(({ command }) => ({
  base: command === 'serve' ? '/' : '/assets/admin-app/',
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(root, 'src') },
  },
  server: {
    port: 5173,
    strictPort: true,
    // 比对报告（html）和拆包源码不属于应用，写入时不应触发页面整页刷新（会打断正在进行的截图）
    watch: { ignored: ['**/tools/visual-diff/out/**', '**/.umi-src/**'] },
  },
  css: {
    preprocessorOptions: {
      scss: {
        // bootstrap 4.6 的 SCSS 使用了旧语法，只屏蔽依赖包里的弃用告警
        quietDeps: true,
        silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'slash-div', 'if-function'],
      },
    },
  },
  build: {
    outDir: '../public/assets/admin-app',
    emptyOutDir: true,
    manifest: true,
    target: 'es2023',
    // 页面按需加载（src/app/router.tsx）后最大的包约 600KB：仪表盘（含 ECharts）、React + antd 公共部分
    chunkSizeWarningLimit: 700,
  },
  test: {
    include: ['tests/**/*.test.ts'],
    environment: 'node',
  },
}))
