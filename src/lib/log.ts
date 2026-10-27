/**
 * Structured server logs. Call sites pass event codes and technical details
 * only (booking reference, error messages) — never names, emails or phones.
 */
type Fields = Record<string, string | number | boolean | undefined>;

function write(level: "info" | "warn" | "error", event: string, fields: Fields = {}) {
  if (process.env.VITEST && level === "info") return;
  const line = JSON.stringify({ level, event, ...fields, at: new Date().toISOString() });
  if (level === "error") console.error(line);
  else if (level === "warn") console.warn(line);
  else console.info(line);
}

export const log = {
  info: (event: string, fields?: Fields) => write("info", event, fields),
  warn: (event: string, fields?: Fields) => write("warn", event, fields),
  error: (event: string, fields?: Fields) => write("error", event, fields),
};
