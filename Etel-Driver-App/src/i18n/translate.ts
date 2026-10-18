import { AM, AM_RULES } from './am';

export type Lang = 'en' | 'am';

// leading / trailing emoji, arrows and symbols are ignored when matching ("🧭 Navigate" -> "Navigate")
const EDGE = /^([^A-Za-z0-9'"~·]*)([\s\S]*?)([^A-Za-z0-9'"…).?]*)$/;

export function translate(text: string, lang: Lang): string {
  if (lang !== 'am' || !text) return text;
  const t = text.replace(/\s+/g, ' ').trim();
  if (!t) return text;
  if (Object.prototype.hasOwnProperty.call(AM, t)) return AM[t];
  const m = EDGE.exec(t);
  if (m && m[2] && m[2] !== t && Object.prototype.hasOwnProperty.call(AM, m[2])) return m[1] + AM[m[2]] + m[3];
  for (const [re, rep] of AM_RULES) if (re.test(t)) return t.replace(re, rep);
  return text;
}
