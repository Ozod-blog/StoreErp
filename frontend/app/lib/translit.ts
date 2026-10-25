/**
 * O‘zbek lotin → kirill transliteratsiyasi.
 * Lug‘atda faqat lotin yoziladi; kirill varianti shu funksiya bilan avtomatik hosil bo‘ladi.
 * {param} ko‘rinishidagi o‘rinbosarlar o‘zgartirilmaydi.
 */
const APOS = new Set(["‘", "ʻ", "`", "'", "’"]);
const SINGLE: Record<string, string> = {
  a: "а", b: "б", d: "д", e: "е", f: "ф", g: "г", h: "ҳ", i: "и", j: "ж", k: "к", l: "л", m: "м",
  n: "н", o: "о", p: "п", q: "қ", r: "р", s: "с", t: "т", u: "у", v: "в", x: "х", y: "й", z: "з",
};
const isLatin = (c?: string) => !!c && /[A-Za-z]/.test(c);

export function toCyrillic(input: string): string {
  let out = "";
  let i = 0;
  while (i < input.length) {
    const ch = input[i];
    if (ch === "{") {
      const end = input.indexOf("}", i);
      if (end > -1) { out += input.slice(i, end + 1); i = end + 1; continue; }
    }
    if (!isLatin(ch)) {
      // tutuq belgisi (ma’lumot → маълумот)
      if (APOS.has(ch) && isLatin(input[i - 1]) && isLatin(input[i + 1])) { out += "ъ"; i++; continue; }
      out += ch; i++; continue;
    }
    const lower = ch.toLowerCase();
    const upper = ch !== lower;
    const next = input[i + 1];
    const nl = next?.toLowerCase();
    const cap = (s: string) => (upper ? s.toUpperCase() : s);

    if ((lower === "o" || lower === "g") && next && APOS.has(next)) { out += cap(lower === "o" ? "ў" : "ғ"); i += 2; continue; }
    if (lower === "s" && nl === "h") { out += cap("ш"); i += 2; continue; }
    if (lower === "c" && nl === "h") { out += cap("ч"); i += 2; continue; }
    if (lower === "y" && nl === "o" && input[i + 2] && APOS.has(input[i + 2])) { out += cap("й"); i++; continue; } // yo‘l → йўл
    if (lower === "y" && nl === "o") { out += cap("ё"); i += 2; continue; }
    if (lower === "y" && nl === "u") { out += cap("ю"); i += 2; continue; }
    if (lower === "y" && nl === "a") { out += cap("я"); i += 2; continue; }
    if (lower === "y" && nl === "e") { out += cap("е"); i += 2; continue; }
    if (lower === "e" && !isLatin(input[i - 1]) && !APOS.has(input[i - 1] ?? "")) { out += cap("э"); i++; continue; }
    if (lower === "c") { out += cap("ц"); i++; continue; }
    out += SINGLE[lower] ? cap(SINGLE[lower]) : ch;
    i++;
  }
  return out;
}
