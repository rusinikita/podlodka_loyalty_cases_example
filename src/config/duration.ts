const units: Record<string, number> = {
  ms: 1,
  s: 1000,
  m: 60 * 1000,
  h: 60 * 60 * 1000,
};

/** Parses a duration like "500ms", "15s" or "1m30s" into milliseconds. */
export function parseDuration(value: string): number {
  const s = value.trim();
  if (!/^(\d+(\.\d+)?(ms|s|m|h))+$/.test(s)) {
    throw new Error(`invalid duration "${value}"`);
  }
  let total = 0;
  for (const [, amount, , unit] of s.matchAll(/(\d+(\.\d+)?)(ms|s|m|h)/g)) {
    total += Number(amount) * units[unit!]!;
  }
  return total;
}
