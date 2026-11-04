import type { ComponentType } from 'react'
import { createBrowserRouter, Navigate, type RouteObject } from 'react-router'
import { adminBasePath } from '@/app/settings'
import { preloadDarkReader } from '@/utils/darkMode'

// 各页面单独打包、按需加载：首次打开只下载当前页面的代码（ace、Markdown 编辑器、ECharts 分别只在节点管理、
// 知识库、仪表盘的包里）。当前页面加载完成后，在浏览器空闲时预加载其余页面和暗黑模式（darkreader），
// 之后切换页面、打开暗黑模式都不需要等待下载
type PageModule = { default: ComponentType }

const loaders: Array<() => Promise<PageModule>> = []

// 预加载失败不处理：用到时会重新下载
function prefetchAll() {
  for (const load of loaders) void load().catch(() => undefined)
  void preloadDarkReader()
}

let prefetchScheduled = false
function schedulePrefetch() {
  if (prefetchScheduled) return
  prefetchScheduled = true
  if (typeof requestIdleCallback === 'function') requestIdleCallback(prefetchAll, { timeout: 2000 })
  else setTimeout(prefetchAll, 200)
}

// 重新部署后，已打开的页面可能还引用着旧版本的文件（已被删除）：下载失败时刷新一次，加载新版本
// （切换页面时刷新到要打开的页面）。10 秒内只自动刷新一次，文件确实缺失时显示路由的错误提示，不会反复刷新。
// 开发环境（编译错误等）不刷新
const RELOAD_KEY = 'v2b_chunk_reload'
function reloadOnce() {
  if (import.meta.env.DEV) return
  try {
    const last = Number(sessionStorage.getItem(RELOAD_KEY))
    if (Date.now() - last < 10_000) return
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()))
  } catch {
    return
  }
  const target = router.state.navigation.location
  if (target) history.replaceState(history.state, '', `${adminBasePath}${target.pathname}${target.search}`)
  location.reload()
}

function page(load: () => Promise<PageModule>): Pick<RouteObject, 'lazy'> {
  loaders.push(load)
  return {
    lazy: async () => {
      try {
        const { default: Component } = await load()
        schedulePrefetch()
        return { Component }
      } catch (error) {
        reloadOnce()
        throw error
      }
    },
  }
}

const routes: RouteObject[] = [
  { path: '/', element: <Navigate to="/login" replace /> },
  { path: '/login', ...page(() => import('@/pages/login/LoginPage')) },
  { path: '*', element: <Navigate to="/client-config" replace /> },
  { path: '/cloud-accounts', ...page(() => import('@/pages/cloud/CloudAccountPage')) },
  { path: '/client-config', ...page(() => import('@/pages/client-config/ClientConfigPage')) },
  { path: '/aliyun-dns', ...page(() => import('@/pages/aliyun-dns/AliyunDnsPage')) },
  { path: '/cloudflare-dns', ...page(() => import('@/pages/cloudflare-dns/CloudflareDnsPage')) },
]

// 首次打开时等当前页面的代码下载完成再渲染，期间页面空白（与原版加载完整个脚本才渲染一致）
function EmptyFallback() {
  return null
}

export const router = createBrowserRouter([{ HydrateFallback: EmptyFallback, children: routes }], {
  basename: adminBasePath,
})