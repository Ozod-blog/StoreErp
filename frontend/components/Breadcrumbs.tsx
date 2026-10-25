"use client";
import Link from "next/link";
import { ChevronRight, Home } from "lucide-react";

export type Crumb = { label: string; href?: string };

/** Yo‘l ko‘rsatkichi: Bosh sahifa › Bo‘lim › Joriy sahifa. */
export default function Breadcrumbs({ items, homeLabel, ariaLabel }: { items: Crumb[]; homeLabel: string; ariaLabel: string }) {
  return (
    <nav aria-label={ariaLabel} className="min-w-0">
      <ol className="flex min-w-0 items-center gap-1 text-sm text-slate-500">
        <li className="shrink-0">
          <Link href="/" aria-label={homeLabel} className="inline-flex items-center rounded p-1 hover:bg-slate-100 hover:text-slate-800"><Home size={15} /></Link>
        </li>
        {items.map((c, i) => {
          const last = i === items.length - 1;
          return (
            <li key={`${c.label}-${i}`} className={`flex min-w-0 items-center gap-1 ${last ? "" : "hidden sm:flex"}`}>
              <ChevronRight size={14} className="shrink-0 text-slate-300" />
              {c.href && !last
                ? <Link href={c.href} className="truncate hover:text-slate-800">{c.label}</Link>
                : <span aria-current={last ? "page" : undefined} className={`truncate ${last ? "font-medium text-slate-800" : ""}`}>{c.label}</span>}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
