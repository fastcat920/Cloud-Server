// 侧边栏菜单（顺序、文案、Simple Line Icons 图标与原版一致）
export type MenuEntry =
  | { type: 'heading'; title: string }
  | { type: 'item'; title: string; href: string; icon: string }

export const MENU: MenuEntry[] = [
  { type: 'item', title: '客户端配置', href: '/client-config', icon: 'si-cloud-upload' },
  { type: 'item', title: '云解析 DNS', href: '/aliyun-dns', icon: 'si-directions' },
  { type: 'item', title: 'Cloudflare 解析', href: '/cloudflare-dns', icon: 'si-globe-alt' },
  { type: 'item', title: '云账号管理', href: '/cloud-accounts', icon: 'si-wallet' },
]
