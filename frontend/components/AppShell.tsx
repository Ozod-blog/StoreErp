"use client";
import { ReactNode, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { LayoutDashboard, UserCog, Users, ShieldCheck, ScrollText, LogOut, Menu, X, Store, Lock, Package, Tags, Ruler, Warehouse, ShoppingCart, PackageMinus, ArrowLeftRight, ClipboardCheck, Undo2, History, Wallet, Clock, CalendarDays, ShoppingBasket, Receipt, UserRound, Truck, Landmark, Banknote, BarChart3, Building2, Upload, Barcode, Ellipsis, CalendarCheck, Shield, PackageCheck, BadgePercent, Bot, MonitorSmartphone, CreditCard, ChevronDown, UserCircle } from "lucide-react";
import { useAuth } from "../app/lib/auth";
import { Spinner } from "./ui";
import { useI18n } from "../app/lib/i18n";
import Breadcrumbs from "./Breadcrumbs";
import NotificationBell from "./NotificationBell";
import PreferencesMenu from "./PreferencesMenu";
import GlobalSearch from "./GlobalSearch";

// Faqat amalda ishlaydigan bo‘limlar ko‘rsatiladi. Yangi modul tayyor bo‘lganda shu yerga qo‘shiladi.
const NAV = [
    { href: "/", label: "nav.dashboard", icon: LayoutDashboard, permission: null },
    { href: "/pos", label: "nav.pos", icon: ShoppingBasket, permission: "sales.create" },
    { href: "/sales", label: "nav.sales", icon: Receipt, permission: "sales.view" },
    { href: "/customers", label: "nav.customers", icon: UserRound, permission: "customers.view" },
    { href: "/suppliers", label: "nav.suppliers", icon: Truck, permission: "suppliers.view" },
    { href: "/products", label: "nav.products", icon: Package, permission: "products.view" },
    { href: "/labels", label: "nav.labels", icon: Barcode, permission: "products.view" },
    { href: "/categories", label: "nav.categories", icon: Tags, permission: "products.view" },
    { href: "/units", label: "nav.units", icon: Ruler, permission: "products.view" },
    { href: "/branches", label: "nav.branches", icon: Building2, permission: "branches.view" },
    { href: "/warehouses", label: "nav.warehouses", icon: Warehouse, permission: "warehouses.view" },
    { href: "/purchases", label: "nav.purchases", icon: ShoppingCart, permission: "purchases.view" },
    { href: "/purchase-returns", label: "nav.purchaseReturns", icon: Undo2, permission: "purchases.view" },
    { href: "/issues", label: "nav.issues", icon: PackageMinus, permission: "warehouses.view" },
    { href: "/transfers", label: "nav.transfers", icon: ArrowLeftRight, permission: "warehouses.view" },
    { href: "/inventory", label: "nav.inventory", icon: ClipboardCheck, permission: "warehouses.view" },
    { href: "/stock-movements", label: "nav.stockMovements", icon: History, permission: "warehouses.view" },
    { href: "/cashboxes", label: "nav.cashboxes", icon: Wallet, permission: "cashboxes.view" },
    { href: "/bank", label: "nav.bank", icon: Landmark, permission: "bank.view" },
    { href: "/expenses", label: "nav.expenses", icon: Banknote, permission: "expenses.view" },
    { href: "/shifts", label: "nav.shifts", icon: Clock, permission: "shifts.view" },
    { href: "/work-schedules", label: "nav.workSchedules", icon: CalendarDays, permission: "employees.view" },
    { href: "/users", label: "nav.users", icon: UserCog, permission: "users.view" },
    { href: "/employees", label: "nav.employees", icon: Users, permission: "employees.view" },
    { href: "/roles", label: "nav.roles", icon: ShieldCheck, permission: "roles.view" },
    { href: "/reports", label: "nav.reports", icon: BarChart3, permission: "reports.view" },
    { href: "/audit", label: "nav.audit", icon: ScrollText, permission: "audit.view" },
    { href: "/imports", label: "nav.imports", icon: Upload, permission: "products.create" },
    { href: "/product-batches", label: "nav.batches", icon: PackageCheck, permission: "warehouses.view" },
    { href: "/pricing", label: "nav.pricing", icon: BadgePercent, permission: "products.view" },
    { href: "/loyalty", label: "nav.loyalty", icon: CreditCard, permission: "customers.view" },
    { href: "/onboarding", label: "nav.onboarding", icon: CalendarCheck, permission: "users.view" },
    { href: "/settings/security", label: "nav.security", icon: Shield, permission: null },
    { href: "/settings/telegram", label: "nav.telegram", icon: Bot, permission: "users.view" },
    { href: "/settings/sessions", label: "nav.sessions", icon: MonitorSmartphone, permission: "users.view" }
] as const;

// Mobil pastki navigatsiyada ko‘rinadigan asosiy bo‘limlar (ruxsat bo‘lsa)
const BOTTOM = ["/", "/pos", "/products", "/sales"];

// Chapdagi to‘liq menyu bo‘limlari. Boshqaruv paneli ("/") bo‘limsiz, yakka holda turadi.
// Yangi sahifa qo‘shilganda NAV ga qo‘shing va shu yerda mos bo‘limga biriktiring.
const GROUPS = [
    { key: "sales", label: "Savdo va mahsulotlar", hrefs: ["/pos", "/sales", "/products", "/categories", "/units", "/pricing", "/labels", "/product-batches", "/loyalty"] },
    { key: "stock", label: "Ombor va mahsulotlar harakati", hrefs: ["/inventory", "/warehouses", "/transfers", "/stock-movements", "/issues", "/branches"] },
    { key: "partners", label: "Xaridlar va hamkorlar", hrefs: ["/purchases", "/purchase-returns", "/suppliers", "/customers"] },
    { key: "finance", label: "Moliya va kassa", hrefs: ["/cashboxes", "/shifts", "/expenses", "/bank"] },
    { key: "staff", label: "Xodimlar va boshqaruv", hrefs: ["/users", "/employees", "/roles", "/work-schedules", "/audit", "/onboarding"] },
    { key: "reports", label: "Hisobotlar va ma’lumotlar almashinuvi", hrefs: ["/reports", "/imports"] },
    { key: "security", label: "Xavfsizlik sozlamalari", hrefs: ["/settings/security", "/settings/sessions", "/settings/telegram"] },
] as const;
const isActiveHref = (pathname: string, href: string) => (href === "/" ? pathname === "/" : pathname.startsWith(href));

export default function AppShell({ title, subtitle, permission, actions, children }: {
    title: string; subtitle?: string; permission?: string; actions?: ReactNode; children: ReactNode;
}) {
    const { user, loading, can, logout } = useAuth();
    const { t } = useI18n();
    const router = useRouter();
    const pathname = usePathname();
    const [drawer, setDrawer] = useState(false);
    const [profileMenu, setProfileMenu] = useState(false);
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
    const profileMenuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!loading && !user) router.replace("/login");
    }, [loading, user, router]);

    useEffect(() => setDrawer(false), [pathname]);
    useEffect(() => {
        if (!profileMenu) return;
        const onPointer = (event: MouseEvent) => { if (!profileMenuRef.current?.contains(event.target as Node)) setProfileMenu(false); };
        const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") setProfileMenu(false); };
        document.addEventListener("mousedown", onPointer);
        document.addEventListener("keydown", onKey);
        return () => { document.removeEventListener("mousedown", onPointer); document.removeEventListener("keydown", onKey); };
    }, [profileMenu]);

    if (loading || !user) return <div className="min-h-screen bg-slate-50"><Spinner label={t("common.loading")} /></div>;

    const items = NAV.filter((i) => !i.permission || can(i.permission));
    const allowed = !permission || can(permission);
    const section = NAV.filter((i) => i.href !== "/" && pathname.startsWith(i.href)).sort((a, b) => b.href.length - a.href.length)[0];
    const crumbs = pathname === "/" ? [] : [
        ...(section ? [{ label: t(section.label), href: section.href }] : []),
        ...(!section ? [{ label: title }] : []),
    ];
    const bottomItems = BOTTOM.map((h) => items.find((i) => i.href === h)).filter((i): i is (typeof items)[number] => !!i);

    const linkCls = (active: boolean) => `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ${active ? "bg-blue-600 text-white" : "text-slate-300 hover:bg-white/5 hover:text-white"}`;
    const dashboard = items.find((i) => i.href === "/");
    const groups = GROUPS.map((g) => ({
        ...g,
        items: (g.hrefs as readonly string[]).map((h) => items.find((i) => i.href === h)).filter((i): i is (typeof items)[number] => !!i),
    })).filter((g) => g.items.length > 0);

    const nav = (
        <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto p-3 [scrollbar-width:thin] [scrollbar-color:#334155_transparent]">
            {dashboard && (
                <Link href={dashboard.href} className={linkCls(isActiveHref(pathname, dashboard.href))}>
                    <dashboard.icon size={18} />{t(dashboard.label)}
                </Link>
            )}
            {groups.map((g) => {
                const hasActive = g.items.some((i) => isActiveHref(pathname, i.href));
                const open = openGroups[g.key] ?? hasActive;
                return (
                    <div key={g.key} className="pt-2">
                        <button type="button" aria-expanded={open} onClick={() => setOpenGroups((p) => ({ ...p, [g.key]: !open }))}
                            className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide transition hover:text-white ${hasActive ? "text-blue-400" : "text-slate-500"}`}>
                            <span className="pr-2">{g.label}</span>
                            <ChevronDown size={14} className={`shrink-0 transition-transform ${open ? "rotate-180" : ""}`} />
                        </button>
                        {open && (
                            <div className="mt-1 space-y-1">
                                {g.items.map(({ href, label, icon: Icon }) => (
                                    <Link key={href} href={href} className={linkCls(isActiveHref(pathname, href))}>
                                        <Icon size={18} />{t(label)}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );

    return (
        <div className="flex min-h-screen bg-slate-50">
            <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col bg-slate-950 md:flex">
                <div className="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white"><Store size={18} /></div>
                    <span className="font-bold text-white">Store ERP</span>
                </div>
                {nav}
            </aside>

            {drawer && (
                <div className="fixed inset-0 z-40 md:hidden" onClick={() => setDrawer(false)}>
                    <div className="absolute inset-0 bg-slate-900/50" />
                    <aside className="relative flex h-full w-64 flex-col bg-slate-950" onClick={(e) => e.stopPropagation()}>
                        <div className="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-5">
                            <span className="font-bold text-white">Store ERP</span>
                            <button onClick={() => setDrawer(false)} aria-label={t("common.close")} className="text-slate-300"><X size={20} /></button>
                        </div>
                        {nav}
                    </aside>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <header className="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 md:px-7">
                    <button onClick={() => setDrawer(true)} aria-label={t("common.menu")} className="rounded-lg p-2 hover:bg-slate-100 md:hidden"><Menu size={20} /></button>
                    <div className="min-w-0 flex-1 px-2 md:px-0"><Breadcrumbs items={crumbs} homeLabel={t("nav.home")} ariaLabel={t("shell.breadcrumb")} /></div>
                    <div className="flex items-center gap-2 sm:gap-3">
                        <GlobalSearch />
                        <NotificationBell />
                        <PreferencesMenu />
                        <div className="relative" ref={profileMenuRef}>
                            <button type="button" aria-haspopup="menu" aria-expanded={profileMenu} onClick={() => setProfileMenu((v) => !v)}
                                className="inline-flex max-w-[190px] items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-2 text-sm hover:bg-slate-50 sm:px-3">
                                <UserCircle size={20} className="shrink-0 text-blue-600" />
                                <span className="hidden min-w-0 text-left sm:block"><span className="block truncate font-semibold text-slate-800">{user.name}</span><span className="block truncate text-xs text-slate-500">{user.roles.map((r) => r.name).join(", ") || t("common.noRole")}</span></span>
                                <ChevronDown size={15} className={`shrink-0 text-slate-500 transition-transform ${profileMenu ? "rotate-180" : ""}`} />
                            </button>
                            {profileMenu && <div role="menu" className="absolute right-0 top-full z-50 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                                <div className="border-b border-slate-100 px-4 py-3"><p className="truncate text-sm font-semibold text-slate-800">{user.name}</p><p className="truncate text-xs text-slate-500">{user.email}</p></div>
                                <Link role="menuitem" href="/profile" onClick={() => setProfileMenu(false)} className="flex items-center gap-3 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50"><UserCircle size={17} />Profil</Link>
                                <button role="menuitem" onClick={async () => { setProfileMenu(false); await logout(); router.replace("/login"); }} className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm text-rose-600 hover:bg-rose-50"><LogOut size={17} />{t("common.logout")}</button>
                            </div>}
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl p-4 pb-24 md:p-7 md:pb-7">
                    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
                            {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
                        </div>
                        {allowed && actions}
                    </div>
                    {allowed ? children : (
                        <div className="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500">
                            <Lock className="mx-auto mb-3" />{t("shell.noAccess")}
                        </div>
                    )}
                </main>
            </div>

            <nav aria-label={t("common.menu")} className="safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white md:hidden">
                <ul className="grid grid-cols-5">
                    {bottomItems.map(({ href, label, icon: Icon }) => {
                        const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
                        return (
                            <li key={href}>
                                <Link href={href} className={`flex flex-col items-center gap-0.5 px-1 py-2 text-[11px] ${active ? "font-semibold text-blue-600" : "text-slate-500"}`}>
                                    <Icon size={20} /><span className="max-w-full truncate">{t(label)}</span>
                                </Link>
                            </li>
                        );
                    })}
                    <li>
                        <button onClick={() => setDrawer(true)} className="flex w-full flex-col items-center gap-0.5 px-1 py-2 text-[11px] text-slate-500">
                            <Ellipsis size={20} /><span>{t("nav.more")}</span>
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    );
}
