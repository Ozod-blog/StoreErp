"use client";
import { ReactNode, useMemo, useState } from "react";

type Column<T> = { key: string; title: string; render: (row: T) => ReactNode; className?: string };
export default function VirtualTable<T extends { id: string | number }>({ rows, columns, rowHeight = 48, height = 520 }: { rows: T[]; columns: Column<T>[]; rowHeight?: number; height?: number }) {
  const [scrollTop, setScrollTop] = useState(0);
  const viewportRows = Math.ceil(height / rowHeight);
  const overscan = 8;
  const start = Math.max(0, Math.floor(scrollTop / rowHeight) - overscan);
  const end = Math.min(rows.length, start + viewportRows + overscan * 2);
  const visible = useMemo(() => rows.slice(start, end), [rows, start, end]);
  return <div className="overflow-auto rounded-2xl border bg-white" style={{ maxHeight: height }} onScroll={e => setScrollTop(e.currentTarget.scrollTop)}>
    <table className="min-w-full text-left text-sm"><thead className="sticky top-0 z-10 bg-slate-50 text-xs uppercase text-slate-500"><tr>{columns.map(c=><th key={c.key} className={`whitespace-nowrap px-4 py-3 ${c.className||""}`}>{c.title}</th>)}</tr></thead>
      <tbody>{start > 0 && <tr aria-hidden="true"><td colSpan={columns.length} style={{height:start*rowHeight,padding:0,border:0}}/> </tr>}
        {visible.map(row=><tr key={row.id} className="border-t border-slate-100" style={{height:rowHeight}}>{columns.map(c=><td key={c.key} className={`whitespace-nowrap px-4 py-2 ${c.className||""}`}>{c.render(row)}</td>)}</tr>)}
        {end < rows.length && <tr aria-hidden="true"><td colSpan={columns.length} style={{height:(rows.length-end)*rowHeight,padding:0,border:0}}/></tr>}
      </tbody>
    </table>
  </div>;
}
