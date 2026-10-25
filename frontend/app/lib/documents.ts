export type DocType = "purchase" | "purchase_return" | "issue" | "transfer" | "inventory";
export type DocStatus = "draft" | "approved" | "cancelled";

/** prefix = API yo‘li, module = backend ruxsat moduli (can:<module>.<amal>). */
export const DOC_META: Record<DocType, { title: string; subtitle: string; single: string; prefix: "purchases" | "stock-documents"; module: "purchases" | "warehouses" }> = {
    purchase: { title: "Xaridlar (kirim)", subtitle: "Yetkazib beruvchidan mahsulot kirimi. Tasdiqlangach ombor qoldig‘i oshadi.", single: "Xarid hujjati", prefix: "purchases", module: "purchases" },
    purchase_return: { title: "Yetkazib beruvchiga qaytarish", subtitle: "Tasdiqlangan xarid hujjatiga bog‘liq qaytarish. Tasdiqlangach qoldiq kamayadi.", single: "Qaytarish hujjati", prefix: "purchases", module: "purchases" },
    issue: { title: "Chiqim", subtitle: "Ombordan hisobdan chiqarish (yaroqsiz, o‘z ehtiyoji va boshqa). Tasdiqlangach qoldiq kamayadi.", single: "Chiqim hujjati", prefix: "stock-documents", module: "warehouses" },
    transfer: { title: "Omborlararo ko‘chirish", subtitle: "Bir ombordan ikkinchisiga. Jami qoldiq o‘zgarmaydi.", single: "Ko‘chirish hujjati", prefix: "stock-documents", module: "warehouses" },
    inventory: { title: "Inventarizatsiya", subtitle: "Sanalgan miqdor kiritiladi; tasdiqlashda farq (ortiqcha/kamomad) qoldiqqa qo‘llanadi.", single: "Inventarizatsiya hujjati", prefix: "stock-documents", module: "warehouses" },
};

export const REASONS: Record<string, string> = {
    write_off: "Yaroqsiz / hisobdan chiqarish",
    damage: "Shikastlanish",
    own_use: "O‘z ehtiyoji uchun",
    other: "Boshqa",
};

export const MOVEMENT_TYPES: Record<string, string> = {
    purchase: "Xarid (kirim)",
    purchase_return: "Yetkazib beruvchiga qaytarish",
    issue: "Chiqim",
    transfer_out: "Ko‘chirish (chiqish)",
    transfer_in: "Ko‘chirish (kirish)",
    inventory_gain: "Inventarizatsiya: ortiqcha",
    inventory_loss: "Inventarizatsiya: kamomad",
    sale: "Savdo (sotuv)",
    sale_return: "Mijozdan qaytarish",
    reversal: "Bekor qilish (teskari)",
};

export const PAYMENT_METHODS: Record<string, string> = { cash: "Naqd", bank: "Bank" };

export function todayISO(): string {
    // Toshkent sanasi (UTC+5), brauzer vaqt zonasidan qat’i nazar.
    return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Tashkent" }).format(new Date());
}

export function newToken(): string {
    if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
    return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
