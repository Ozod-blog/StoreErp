"use client";
import { useRef } from "react";
import { Printer } from "lucide-react";
import { Button, Modal } from "./ui";
import { formatDateTime, formatQty } from "../app/lib/format";
import { METHOD_LABELS, ReceiptInfo, SaleRow } from "../app/lib/sales";

const num = (v: string | number | null | undefined) => new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 2 }).format(Number(v ?? 0));

/** Chek. Stillar inline — shuning uchun ekrandagi ko‘rinish va chop etiladigan nusxa bir xil (tashqi CSS kerak emas). */
export function ReceiptView({ sale, info, change }: { sale: SaleRow; info: ReceiptInfo | null; change?: number }) {
    const row: React.CSSProperties = { display: "flex", justifyContent: "space-between", gap: 8 };
    const hr: React.CSSProperties = { borderTop: "1px dashed #64748b", margin: "6px 0" };
    const isReturn = sale.type === "return";
    const payments = (sale.payments ?? []).filter((p) => p.kind === "payment" || p.kind === "refund");

    return (
        <div style={{ width: "100%", maxWidth: 300, margin: "0 auto", fontFamily: "Arial, Helvetica, sans-serif", fontSize: 12, color: "#0f172a" }}>
            <div style={{ textAlign: "center" }}>
                <div style={{ fontSize: 15, fontWeight: 700 }}>{info?.store_name || "Do‘kon"}</div>
                {info?.address && <div>{info.address}</div>}
                {info?.phone && <div>Tel: {info.phone}</div>}
                {info?.tin && <div>STIR: {info.tin}</div>}
            </div>
            <div style={hr} />
            <div style={{ textAlign: "center", fontWeight: 700 }}>{isReturn ? "QAYTARISH CHEKI" : "SAVDO CHEKI"} № {sale.number}</div>
            {sale.status === "cancelled" && <div style={{ textAlign: "center", fontWeight: 700, color: "#b91c1c" }}>*** BEKOR QILINGAN ***</div>}
            <div style={row}><span>Sana:</span><span>{formatDateTime(sale.sold_at)}</span></div>
            {sale.creator && <div style={row}><span>Kassir:</span><span>{sale.creator.name}</span></div>}
            {sale.customer && <div style={row}><span>Mijoz:</span><span>{sale.customer.name}</span></div>}
            {sale.parent && <div style={row}><span>Asl sotuv:</span><span>{sale.parent.number}</span></div>}
            <div style={hr} />

            {(sale.items ?? []).map((i) => {
                const unit = i.product?.unit?.short_name;
                const lineDiscount = Number(i.discount) + Number(i.discount_share);
                return (
                    <div key={i.id} style={{ marginBottom: 4 }}>
                        <div style={{ fontWeight: 600 }}>{i.product?.name ?? `#${i.product_id}`}</div>
                        <div style={row}>
                            <span>{formatQty(i.quantity, unit)} × {num(i.price)}</span>
                            <span>{num(i.net_total)}</span>
                        </div>
                        {!isReturn && lineDiscount > 0 && <div style={{ color: "#475569" }}>Chegirma: −{num(lineDiscount)}</div>}
                    </div>
                );
            })}
            <div style={hr} />

            {!isReturn && Number(sale.discount) > 0 && (
                <>
                    <div style={row}><span>Jami (chegirmasiz):</span><span>{num(sale.subtotal)}</span></div>
                    <div style={row}><span>Chegirma:</span><span>−{num(sale.discount)}</span></div>
                </>
            )}
            <div style={{ ...row, fontSize: 15, fontWeight: 700 }}><span>{isReturn ? "QAYTARILADI:" : "JAMI:"}</span><span>{num(sale.total)} so‘m</span></div>

            {payments.length > 0 && <div style={hr} />}
            {payments.map((p) => (
                <div key={p.id}>
                    <div style={row}><span>{isReturn ? "Qaytarildi" : "To‘landi"}: {METHOD_LABELS[p.method] ?? p.method}</span><span>{num(p.amount)}</span></div>
                    {p.reference && <div style={{ color: "#475569" }}>Chek/tranzaksiya: {p.reference}</div>}
                </div>
            ))}
            {!isReturn && Number(sale.debt_amount) > 0 && <div style={{ ...row, fontWeight: 700 }}><span>Qarzga:</span><span>{num(sale.debt_amount)}</span></div>}
            {isReturn && Number(sale.debt_amount) > 0 && <div style={row}><span>Qarzdan ayrildi:</span><span>{num(sale.debt_amount)}</span></div>}
            {!isReturn && change !== undefined && change > 0 && <div style={{ ...row, fontWeight: 700 }}><span>Qaytim:</span><span>{num(change)}</span></div>}

            {info?.footer && (<><div style={hr} /><div style={{ textAlign: "center" }}>{info.footer}</div></>)}
        </div>
    );
}

/** Yashirin iframe orqali chop etish (qalqib chiquvchi oyna bloklanmaydi, ilova sahifasi chekka aralashmaydi). */
export function printHtml(inner: string, title: string): void {
    const iframe = document.createElement("iframe");
    iframe.setAttribute("aria-hidden", "true");
    Object.assign(iframe.style, { position: "fixed", right: "0", bottom: "0", width: "0", height: "0", border: "0" });
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow?.document;
    if (!doc || !iframe.contentWindow) {
        iframe.remove();
        return;
    }
    doc.open();
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>@page{size:80mm auto;margin:4mm}body{margin:0}</style></head><body>${inner}</body></html>`);
    doc.close();

    const win = iframe.contentWindow;
    setTimeout(() => {
        win.focus();
        win.print();
        setTimeout(() => iframe.remove(), 1500);
    }, 150);
}

export function ReceiptModal({ open, sale, info, change, onClose, footer }: {
    open: boolean; sale: SaleRow | null; info: ReceiptInfo | null; change?: number; onClose: () => void; footer?: React.ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    if (!sale) return null;

    return (
        <Modal open={open} title={`Chek ${sale.number}`} onClose={onClose}>
            <div className="rounded-lg border border-dashed border-slate-300 bg-white p-4">
                <div ref={ref}><ReceiptView sale={sale} info={info} change={change} /></div>
            </div>
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                {footer}
                <Button variant="secondary" onClick={() => ref.current && printHtml(ref.current.innerHTML, sale.number)}>
                    <Printer size={16} /> Chop etish
                </Button>
                <Button onClick={onClose}>Yopish</Button>
            </div>
        </Modal>
    );
}
