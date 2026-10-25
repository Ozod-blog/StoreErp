export type PayMethod = "cash" | "card" | "click" | "payme" | "transfer";

export const PAY_METHODS: PayMethod[] = ["cash", "card", "click", "payme", "transfer"];

export const METHOD_LABELS: Record<string, string> = {
    cash: "Naqd pul",
    card: "Bank kartasi",
    click: "Click",
    payme: "Payme",
    transfer: "Bank o‘tkazmasi",
};

/** Bu usullarda chek / tranzaksiya raqami majburiy (backend ham tekshiradi). */
export const REFERENCE_REQUIRED: string[] = ["click", "payme", "transfer"];

export type ReceiptInfo = { store_name: string; address?: string | null; phone?: string | null; tin?: string | null; footer?: string | null };

export type SaleItemRow = {
    id: number; product_id: number; quantity: string; price: string; discount: string; discount_share: string; net_total: string; unit_cost: string;
    product?: { id: number; name: string; sku: string; unit?: { short_name: string; allow_fraction: boolean } | null } | null;
};

export type PaymentRow = {
    id: number; method: string; direction: "in" | "out"; kind: "payment" | "refund" | "reversal"; amount: string;
    reference: string | null; is_online_verified: boolean;
};

export type SaleRow = {
    id: number; number: string; type: "sale" | "return"; status: "approved" | "cancelled";
    subtotal: string; discount: string; total: string; paid_amount: string; debt_amount: string; cogs: string;
    refund_method: string | null; note: string | null; cancel_reason: string | null; sold_at: string; cancelled_at: string | null;
    customer?: { id: number; name: string; phone?: string | null } | null;
    creator?: { id: number; name: string } | null;
    canceller?: { id: number; name: string } | null;
    cashbox?: { id: number; code: string; name: string } | null;
    warehouse?: { id: number; name: string } | null;
    parent?: { id: number; number: string } | null;
    items?: SaleItemRow[];
    payments?: PaymentRow[];
    returns?: { id: number; number: string; total: string; status: string; sold_at: string }[];
    receipt_info?: ReceiptInfo | null;
};

export type PosProduct = {
    id: number; sku: string; barcode: string | null; name: string; sale_price: string; wholesale_price?: string | null; unit: string | null; allow_fraction: boolean; stock: string;
};

export type PosContext = {
    shift: { id: number; opened_at: string; cashbox: { id: number; code: string; name: string } } | null;
    warehouses: { id: number; code: string; name: string }[];
    default_warehouse_id: number | null;
    can: { discount: boolean; debt: boolean; customers_create: boolean };
    methods: Record<string, string>;
    reference_required: string[];
    receipt: ReceiptInfo | null;
};

export type ReturnableRow = {
    product_id: number; name: string | null; sku: string | null; unit: string | null; allow_fraction: boolean;
    sold: string; returned: string; remaining: string; price: string; net_total: string;
};

/** Matn → tiyin (butun son). Vergul ham nuqta sifatida qabul qilinadi. */
export function toCents(value: string | number | null | undefined): number {
    const n = parseFloat(String(value ?? "").replace(",", ".").trim());
    return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

/** Tiyin → kiritish maydoni uchun matn (ortiqcha nolsiz). */
export function centsToInput(cents: number): string {
    if (cents % 100 === 0) return String(cents / 100);
    return (cents / 100).toFixed(2);
}

export function normNumber(value: string): string {
    return value.replace(",", ".").trim();
}
