export const SUPPORTED_LOCALES = ['zh-CN', 'en-US'];

export const normalizeLocale = value => {
  if (value === 'en-US' || String(value || '').toLowerCase().startsWith('en')) return 'en-US';
  return 'zh-CN';
};

export const getRequestLocale = () => {
  const stored = localStorage.getItem('language');
  if (SUPPORTED_LOCALES.includes(stored)) return stored;
  if (stored) return 'zh-CN';
  return normalizeLocale(navigator.language || navigator.userLanguage || 'zh-CN');
};
