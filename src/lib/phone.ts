/**
 * Phone numbers: Belgian formats are understood without a country code
 * ("0470 12 34 56", "02 123 45 67"), international numbers are accepted
 * with "+" or "00". Everything is normalised to E.164 ("+32470123456").
 */

export function normalizePhone(raw: string): string | null {
  let value = raw.trim().replace(/[\s.\-/()]/g, "");
  if (value.startsWith("00")) value = `+${value.slice(2)}`;
  else if (value.startsWith("0")) value = `+32${value.slice(1)}`;
  if (!/^\+[1-9]\d{6,14}$/.test(value)) return null;

  if (value.startsWith("+32")) {
    const national = value.slice(3);
    if (national.startsWith("0")) return null;
    // Mobile numbers: 4xx xx xx xx (9 digits). Landlines: 8 digits.
    const expected = national.startsWith("4") ? 9 : 8;
    if (national.length !== expected) return null;
  }
  return value;
}

/** "+32 470 12 34 56", "+32 2 123 45 67", or the E.164 value for other countries. */
export function formatPhone(e164: string): string {
  if (!e164.startsWith("+32")) return e164;
  const n = e164.slice(3);
  if (n.length === 9) return `+32 ${n.slice(0, 3)} ${n.slice(3, 5)} ${n.slice(5, 7)} ${n.slice(7)}`;
  if ("2349".includes(n[0])) return `+32 ${n[0]} ${n.slice(1, 4)} ${n.slice(4, 6)} ${n.slice(6)}`;
  return `+32 ${n.slice(0, 2)} ${n.slice(2, 4)} ${n.slice(4, 6)} ${n.slice(6)}`;
}
