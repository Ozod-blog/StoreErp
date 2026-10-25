export type PartyKind = "customer" | "supplier";

export type PartyConfig = {
    base: string;
    title: string;
    subtitle: string;
    single: string;           // "Mijoz" | "Yetkazib beruvchi"
    permView: string;
    permCreate: string;
    permUpdate: string;
    permDelete: string;
    permPay: string;          // to‘lov qabul qilish / to‘lash ruxsati
    permCancelPay: string;
    methods: string[];
    historyPath: string;
    historyTitle: string;
    payTitle: string;
    payButton: string;
    debtLabel: string;
    cards: [string, string][]; // [sarlavha, summary kaliti]
};

export const PARTY: Record<PartyKind, PartyConfig> = {
    customer: {
        base: "/customers", title: "Mijozlar", subtitle: "Mijozlar, ularning qarzi, to‘lovlari va o‘zaro hisob-kitob varaqasi", single: "Mijoz",
        permView: "customers.view", permCreate: "customers.create", permUpdate: "customers.update", permDelete: "customers.delete",
        permPay: "sales.create", permCancelPay: "sales.cancel",
        methods: ["cash", "card", "click", "payme", "transfer"], historyPath: "sales", historyTitle: "Xaridlar tarixi",
        payTitle: "Qarz to‘lovini qabul qilish", payButton: "Qarz to‘lovi", debtLabel: "Mijoz qarzi",
        cards: [["Jami xarid (qaytarishsiz)", "net_total"], ["Jami to‘langan", "paid_total"], ["Qarz to‘lovlari", "debt_payments_total"], ["Qarz qoldig‘i", "debt"]],
    },
    supplier: {
        base: "/suppliers", title: "Yetkazib beruvchilar", subtitle: "Yetkazib beruvchilar, ularga qarz, to‘lovlar va o‘zaro hisob-kitob varaqasi", single: "Yetkazib beruvchi",
        permView: "suppliers.view", permCreate: "suppliers.create", permUpdate: "suppliers.update", permDelete: "suppliers.delete",
        permPay: "suppliers.update", permCancelPay: "purchases.cancel",
        methods: ["cash", "bank"], historyPath: "documents", historyTitle: "Xaridlar va qaytarishlar",
        payTitle: "Yetkazib beruvchiga to‘lov", payButton: "To‘lov qilish", debtLabel: "Yetkazib beruvchiga qarz",
        cards: [["Jami xarid (qaytarishsiz)", "net_total"], ["Jami to‘langan", "paid_total"], ["Keyingi to‘lovlar", "payments_total"], ["Qarz qoldig‘i", "debt"]],
    },
};

export const SUPPLIER_METHOD_LABELS: Record<string, string> = { cash: "Naqd pul (kassadan)", bank: "Bank orqali" };

export type PartyRow = {
    id: number; name: string; phone: string | null; address: string | null; note: string | null; contact_info?: string | null;
    is_active: boolean; debt?: string | number | null;
};
export type Summary = Record<string, string | number>;
export type StatementRow = { date: string; kind: string; number: string; description: string; charge: string; credit: string; balance: string };
export type Statement = { party: { id: number; name: string; phone: string | null }; opening_balance: string; closing_balance: string; rows: StatementRow[]; summary: Summary };
export type PaymentRec = {
    id: number; number: string; amount: string; method: string; reference: string | null; note: string | null; status: string;
    paid_at: string; cancel_reason: string | null; is_online_verified?: boolean; creator?: { id: number; name: string } | null;
};
export type HistoryRow = {
    id: number; number: string; type: string; status: string; total: string; paid_amount: string;
    sold_at?: string; doc_date?: string; debt_amount?: string; payment_method?: string | null;
};
export type Totals = { total_debt: string; total_credit: string; debtors_count: number };

export const DOC_TYPE_LABELS: Record<string, string> = { sale: "Savdo", return: "Qaytarish", purchase: "Xarid", purchase_return: "Qaytarish" };
export const STATEMENT_KIND_LABELS: Record<string, string> = { sale: "Savdo", purchase: "Xarid", return: "Qaytarish", payment: "To‘lov" };
