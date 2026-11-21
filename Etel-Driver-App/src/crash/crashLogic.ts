// Crash reports from the phone app: what is sent and when, with no React or phone modules so it can be tested alone.
// The server (delivery/api/client-error.php) stores each distinct error once with a counter, cuts every field short and keeps no tokens or addresses.
// Here we keep the note small, remove anything that looks like a secret, and never send the same error twice in one run of the app.

export type CrashKind = 'js' | 'promise';
export interface CrashReport {
  app: string;
  kind: CrashKind;
  message: string;
  page: string;
  source: string;
  line: number;
  stack: string;
  uid: number;
  ua: string;
}

export const MAX_REPORTS_PER_RUN = 10;

// Long hex strings, JWT-like tokens, "Bearer x" and phone numbers are blanked so a token or a number never leaves the phone by accident.
export function scrub(s: string): string {
  return String(s == null ? '' : s)
    .replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer #')
    .replace(/eyJ[A-Za-z0-9._-]{10,}/g, '#jwt')
    .replace(/[A-Fa-f0-9]{24,}/g, '#')
    .replace(/(token|password|secret|authorization)(["']?\s*[:=]\s*["']?)[^\s"',}]+/gi, '$1$2#')
    .replace(/\+?\d[\d\s-]{8,}\d/g, '#');
}

// The first stack line that points into the app's own code gives "file:line"; the rest of the stack goes along (the server keeps 1200 letters).
export function firstFrame(stack: string): { source: string; line: number } {
  const lines = String(stack || '').split('\n');
  for (const l of lines) {
    const m = /([A-Za-z0-9_./-]+\.(?:js|ts|tsx|bundle))[:@](\d+)/.exec(l) || /\(?([^\s()]+):(\d+):\d+\)?\s*$/.exec(l);
    if (m) return { source: m[1].replace(/[?#].*$/, ''), line: parseInt(m[2], 10) || 0 };
  }
  return { source: '', line: 0 };
}

export interface CrashContext {
  version: string;
  os: string;
  osVersion: string | number;
  uid: number;
}

export function buildReport(kind: CrashKind, err: unknown, where: string, ctx: CrashContext): CrashReport | null {
  const e = err as { message?: unknown; stack?: unknown } | string | null | undefined;
  const rawMsg = typeof e === 'string' ? e : e && typeof e.message === 'string' ? e.message : e && typeof e === 'object' ? 'Unknown error' : String(e == null ? '' : e);
  const message = scrub(rawMsg).replace(/\s+/g, ' ').trim().substring(0, 300);
  if (!message) return null;
  const stack = scrub(typeof e === 'object' && e && typeof e.stack === 'string' ? e.stack : '').substring(0, 1200);
  const f = firstFrame(stack);
  return {
    app: 'etelapp',
    kind,
    message,
    page: (where + ' - app ' + ctx.version + ' - ' + ctx.os + ' ' + ctx.osVersion).substring(0, 120),
    source: f.source.substring(0, 160),
    line: f.line,
    stack,
    uid: ctx.uid > 0 ? ctx.uid : 0,
    ua: ('ETEL app ' + ctx.version + ' (' + ctx.os + ' ' + ctx.osVersion + ')').substring(0, 200),
  };
}

// At most MAX_REPORTS_PER_RUN notes per run of the app, and the same error only once: a crash loop cannot flood the server or the phone's data.
export function createLimiter(max: number = MAX_REPORTS_PER_RUN) {
  const seen = new Set<string>();
  return (r: CrashReport): boolean => {
    const key = r.kind + '|' + r.message.replace(/\d+/g, '#').substring(0, 120) + '|' + r.source + ':' + r.line;
    if (seen.has(key) || seen.size >= max) return false;
    seen.add(key);
    return true;
  };
}
