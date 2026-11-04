import DOMPurify from 'dompurify';

const DEFAULT_OPTIONS = {
  USE_PROFILES: { html: true },
  FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed', 'form'],
  FORBID_ATTR: ['srcdoc']
};

/**
 * Sanitize HTML received from configuration or backend APIs before v-html.
 * DOMPurify also removes inline event handlers and unsafe URL protocols.
 */
export const sanitizeHtml = (value, options = {}) => DOMPurify.sanitize(
  typeof value === 'string' ? value : '',
  { ...DEFAULT_OPTIONS, ...options }
);

export default sanitizeHtml;
