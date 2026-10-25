export type CashboxRow = {
    id: number; code: string; name: string; branch_id: number | null; is_active: boolean; balance?: string;
    branch?: { id: number; name: string } | null;
    open_shift?: { id: number; user?: { id: number; name: string } | null } | null;
};

export type CashTx = {
    id: number; direction: "in" | "out"; category: string; amount: string; balance_after: string; note: string | null;
    occurred_at: string; shift_id: number | null;
    cashbox?: { id: number; code: string; name: string } | null; user?: { id: number; name: string } | null;
};

export type Shift = {
    id: number; status: "open" | "closed"; cashbox_id: number; user_id: number; opened_at: string; opening_cash: string; opening_note: string | null;
    closed_at: string | null; expected_cash: string | null; actual_cash: string | null; difference: string | null; difference_reason: string | null;
    reviewed_at: string | null; review_note: string | null; handover_from_shift_id: number | null;
    cashbox?: { id: number; code: string; name: string } | null; user?: { id: number; name: string } | null;
    closer?: { id: number; name: string } | null; reviewer?: { id: number; name: string } | null; handed_over_to?: { id: number; name: string } | null;
    sales_summary?: {
        sales_count: number; sales_total: string; sales_discount: string; sales_debt: string; returns_count: number; returns_total: string;
        non_cash_net: string; by_method: { method: string; received: string; refunded: string; net: string }[];
    };
    summary?: {
        opening_cash: string; cash_in: string; cash_out: string; expected_cash: string;
        by_category: { direction: "in" | "out"; category: string; total: string; count: number }[];
    };
};

export const CATEGORY_LABELS: Record<string, string> = {
    manual_in: "Kassaga kirim",
    manual_out: "Kassadan chiqim",
    opening_adjustment: "Boshlang‘ich qoldiq tuzatishi",
    shift_difference: "Smena farqi",
    sale: "Savdo",
    sale_return: "Sotuvni qaytarish",
    sale_cancel: "Savdo bekor qilindi",
    debt_payment: "Mijoz qarzi to‘lovi",
    debt_payment_cancel: "Mijoz to‘lovi bekor qilindi",
    supplier_payment: "Yetkazib beruvchiga to‘lov",
    supplier_payment_cancel: "Yetkazib beruvchi to‘lovi bekor qilindi",
    expense: "Xarajat",
    bank_transfer: "Bank o‘tkazmasi",
};

export const SCHEDULE_TYPES: Record<string, { label: string; short: string; cls: string }> = {
    work: { label: "Ishlaydi", short: "Ish", cls: "bg-emerald-50 text-emerald-700" },
    off: { label: "Dam oladi", short: "Dam", cls: "bg-slate-100 text-slate-600" },
    vacation: { label: "Ta’til", short: "Ta’til", cls: "bg-sky-50 text-sky-700" },
    sick: { label: "Kasallik", short: "Kasal", cls: "bg-amber-50 text-amber-700" },
    absent_excused: { label: "Sababli kelmagan", short: "Sababli", cls: "bg-violet-50 text-violet-700" },
    absent_unexcused: { label: "Sababsiz kelmagan", short: "Sababsiz", cls: "bg-red-50 text-red-700" },
};

export type ScheduleRow = { id: number; employee_id: number; work_date: string; type: string; start_time: string | null; end_time: string | null; note: string | null };

/** Toshkent vaqti bo‘yicha bugungi sana (YYYY-MM-DD). */
export function todayTashkent(): string {
    return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Tashkent" }).format(new Date());
}

export function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/** Berilgan sana haftasining dushanbasi. */
export function mondayOf(date: string): string {
    const d = new Date(`${date}T00:00:00Z`);
    const dow = (d.getUTCDay() + 6) % 7; // dushanba = 0
    return addDays(date, -dow);
}
