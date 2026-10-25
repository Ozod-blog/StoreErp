import { toCyrillic } from "./translit";

const TZ = "Asia/Tashkent";
type L = "uz" | "uz-cyrl" | "ru";
let current: L = "uz";

/** I18nProvider tomonidan chaqiriladi; formatlash funksiyalari shu tilga qarab ishlaydi. */
export function setFormatLocale(l: L) { current = l; }
export function getFormatLocale(): L { return current; }

/** Raqam guruhlash: barcha tillarda "1 234 567,5" (bo‘sh joy bilan). */
const NUM_LOCALE = "ru-RU";

export function currencyLabel(): string {
  return current === "ru" ? "сум" : current === "uz-cyrl" ? toCyrillic("so‘m") : "so‘m";
}

export function formatUZS(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "—";
  return `${new Intl.NumberFormat(NUM_LOCALE, { maximumFractionDigits: 2 }).format(Number(value))} ${currencyLabel()}`;
}

/** Server UTC saqlaydi; interfeysda Toshkent vaqtida ko‘rsatiladi (kk.oo.yyyy, SS:DD). */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat(NUM_LOCALE, { timeZone: TZ, dateStyle: "short", timeStyle: "short" }).format(new Date(iso));
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat(NUM_LOCALE, { timeZone: TZ, dateStyle: "short" }).format(new Date(iso));
}

/** Miqdor: kasrli birliklar uchun 3 xonagacha, ortiqcha nollarsiz. */
export function formatQty(value: number | string | null | undefined, unit?: string | null): string {
  const n = Number(value ?? 0);
  const text = new Intl.NumberFormat(NUM_LOCALE, { maximumFractionDigits: 3 }).format(Number.isFinite(n) ? n : 0);
  return unit ? `${text} ${unit}` : text;
}
