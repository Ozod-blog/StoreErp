"use client";
import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { setFormatLocale } from "./format";
import { toCyrillic } from "./translit";

export type Locale = "uz" | "uz-cyrl" | "ru";
export const LOCALES: { value: Locale; short: string; label: string }[] = [
  { value: "uz", short: "UZ", label: "O‘zbekcha (lotin)" },
  { value: "uz-cyrl", short: "ЎЗ", label: "Ўзбекча (кирилл)" },
  { value: "ru", short: "RU", label: "Русский" },
];
const KEY = "store_erp_locale";

/** Asosiy lug‘at — o‘zbek (lotin). Kirill varianti avtomatik hosil bo‘ladi. */
const uz = {
  "common.loading": "Yuklanmoqda...", "common.close": "Yopish", "common.cancel": "Bekor qilish", "common.confirm": "Tasdiqlash",
  "common.prev": "Oldingi", "common.next": "Keyingi", "common.total": "Jami", "common.logout": "Chiqish", "common.menu": "Menyu",
  "common.noRole": "Rol berilmagan", "common.refresh": "Yangilash", "common.details": "Batafsil", "common.skuNone": "SKU yo‘q",
  "unit.thousand": "ming", "unit.million": "mln", "unit.billion": "mlrd", "unit.currency": "so‘m",
  "status.active": "Faol", "status.inactive": "Faol emas", "status.leave": "Ta’tilda", "status.archived": "Arxivda",
  "status.success": "Muvaffaqiyatli", "status.failed": "Xato / rad etildi", "status.draft": "Qoralama",
  "status.approved": "Tasdiqlangan", "status.cancelled": "Bekor qilingan", "status.open": "Ochiq", "status.closed": "Yopilgan",

  "nav.dashboard": "Boshqaruv paneli", "nav.pos": "Savdo (POS)", "nav.sales": "Sotuv tarixi", "nav.customers": "Mijozlar",
  "nav.suppliers": "Yetkazib beruvchilar", "nav.products": "Mahsulotlar", "nav.labels": "Yorliq (etiket)", "nav.categories": "Kategoriyalar",
  "nav.units": "O‘lchov birliklari", "nav.branches": "Filiallar", "nav.warehouses": "Omborlar", "nav.purchases": "Xaridlar (kirim)",
  "nav.purchaseReturns": "Yetkazib beruvchiga qaytarish", "nav.issues": "Chiqim", "nav.transfers": "Omborlararo ko‘chirish",
  "nav.inventory": "Inventarizatsiya", "nav.stockMovements": "Qoldiq harakati", "nav.cashboxes": "Kassalar", "nav.bank": "Bank",
  "nav.expenses": "Xarajatlar", "nav.shifts": "Kassa smenalari", "nav.workSchedules": "Ish jadvali", "nav.users": "Foydalanuvchilar",
  "nav.employees": "Xodimlar", "nav.roles": "Rollar va ruxsatlar", "nav.reports": "Hisobotlar", "nav.audit": "Audit jurnali", "nav.imports": "Excel import",
  "nav.more": "Ko‘proq", "nav.home": "Bosh sahifa", "nav.notifications": "Bildirishnomalar", "nav.onboarding": "Boshlang‘ich sozlash", "nav.security": "Xavfsizlik", "nav.batches": "Partiya va yaroqlilik", "nav.pricing": "Aksiyalar va chegirmalar", "nav.loyalty": "Loyallik kartalari", "nav.telegram": "Telegram ogohlantirishlari", "nav.sessions": "Faol sessiyalar",

  "shell.noAccess": "Bu bo‘limga kirish uchun ruxsatingiz yo‘q.", "shell.breadcrumb": "Joylashuv",
  "prefs.title": "Ko‘rinish sozlamalari", "prefs.language": "Til", "prefs.theme": "Mavzu", "prefs.density": "Jadval zichligi",
  "theme.light": "Yorug‘", "theme.dark": "Qorong‘i", "theme.system": "Tizim", "density.comfortable": "Oddiy", "density.compact": "Ixcham (1C kabi)",

  "login.subtitle": "Hisobingizga kiring", "login.email": "Email", "login.password": "Parol", "login.submit": "Kirish",

  "dash.title": "Boshqaruv paneli", "dash.subtitle": "Do‘koningizning bugungi holati va asosiy ko‘rsatkichlari",
  "dash.badge": "Store ERP · Umumiy ko‘rinish", "dash.welcome": "Xush kelibsiz, {name}!", "dash.userFallback": "foydalanuvchi",
  "dash.intro": "Savdo, moliya va ombor ko‘rsatkichlarini bitta joydan kuzating. Hisobotlar tanlangan davr va filial bo‘yicha yangilanadi.",
  "dash.period": "Hisobot davri", "dash.roleNone": "Rol belgilanmagan", "dash.filters": "Hisobot filtrlari",
  "dash.filtersHint": "Ko‘rsatkichlarni kerakli vaqt va filial bo‘yicha ko‘ring.", "dash.dateFrom": "Boshlanish sanasi", "dash.dateTo": "Tugash sanasi",
  "dash.branch": "Filial", "dash.allBranches": "Barcha filiallar", "dash.noBranches": "Filial filtri filiallar sozlangandan keyin ko‘rinadi.",
  "dash.compare": "Oldingi davr bilan solishtirish", "dash.comparePeriod": "Oldingi davr: {from} — {to}",
  "dash.loadingKpi": "Ko‘rsatkichlar yuklanmoqda...", "dash.kpiTitle": "Asosiy ko‘rsatkichlar", "dash.kpiHint": "Tanlangan davr uchun moliyaviy va ombor holati",
  "dash.toReports": "Hisobotlarga o‘tish", "dash.vsPrev": "oldingi davrga nisbatan",
  "kpi.sales_net": "Sof savdo", "kpi.sales_net.note": "Tanlangan davr", "kpi.gross_profit": "Yalpi foyda", "kpi.gross_profit.note": "Hisobiy ko‘rsatkich",
  "kpi.approved_expenses": "Xarajatlar", "kpi.approved_expenses.note": "Tasdiqlangan", "kpi.operating_result": "Operatsion natija", "kpi.operating_result.note": "Foyda − xarajat",
  "kpi.cash_balance": "Kassa qoldig‘i", "kpi.cash_balance.note": "Joriy qoldiq", "kpi.bank_balance": "Bank qoldig‘i", "kpi.bank_balance.note": "Joriy qoldiq",
  "kpi.customer_debt_estimate": "Mijozlar qarzi", "kpi.customer_debt_estimate.note": "Taxminiy jami",
  "kpi.low_stock_products": "Kam qolgan mahsulot", "kpi.low_stock_products.note": "Minimal qoldiq yoki kam",
  "dash.trendTitle": "Savdo dinamikasi", "dash.trendHint": "Kunlik tushum va qaytarishlar", "dash.legendSales": "Savdo", "dash.legendReturns": "Qaytarish",
  "dash.legendPrev": "Oldingi davr savdosi", "dash.chartEmpty": "Bu davr uchun savdo grafigini chizishga yetarli ma’lumot yo‘q.",
  "dash.axisAmount": "Summa (so‘m)", "dash.points": "{n} kunlik nuqta", "dash.revenue": "Sotuv tushumi", "dash.returnsTotal": "Qaytarishlar",
  "dash.prevRevenue": "Oldingi davr tushumi", "dash.colDate": "Sana", "dash.colSales": "Savdo", "dash.colReturns": "Qaytarish", "dash.colNet": "Sof savdo",
  "dash.quickTitle": "Tezkor amallar", "dash.quickHint": "Ko‘p ishlatiladigan bo‘limlar",
  "dash.quick.pos": "Yangi savdo", "dash.quick.pos.d": "Kassada savdoni boshlash", "dash.quick.products": "Mahsulotlar", "dash.quick.products.d": "Katalog va narxlar",
  "dash.quick.warehouses": "Omborlar", "dash.quick.warehouses.d": "Qoldiq va omborlar", "dash.quick.reports": "Barcha hisobotlar", "dash.quick.reports.d": "Savdo, ombor va moliya",
  "dash.topTitle": "Eng ko‘p sotilgan mahsulotlar", "dash.topHint": "Tanlangan davrdagi TOP mahsulotlar", "dash.topMore": "Batafsil →",
  "dash.colProduct": "Mahsulot", "dash.colQty": "Miqdor", "dash.colAmount": "Savdo summasi", "dash.topEmpty": "Tanlangan davrda sotuv ma’lumoti topilmadi.",
  "dash.lowTitle": "Kam qolgan mahsulotlar", "dash.lowHint": "Qoldig‘i minimal darajaga yetgan mahsulotlar", "dash.lowMore": "Ombor hisoboti →",
  "dash.lowEmpty": "Kam qoldiqdagi mahsulot aniqlanmadi.", "dash.min": "Min: {value}",
  "dash.footNote": "Ma’lumotlar ERP bazasidagi tasdiqlangan hujjatlar asosida.", "dash.footUser": "Foydalanuvchi: {email} · Ruxsatlar: {count}",
  "dash.restrictedTitle": "Boshqaruv paneli cheklangan", "dash.restrictedText": "Statistikani ko‘rish uchun administrator sizga reports.view ruxsatini berishi kerak.",
} as const;

export type MessageKey = keyof typeof uz;

const ru: Record<MessageKey, string> = {
  "common.loading": "Загрузка...", "common.close": "Закрыть", "common.cancel": "Отмена", "common.confirm": "Подтвердить",
  "common.prev": "Назад", "common.next": "Вперёд", "common.total": "Всего", "common.logout": "Выйти", "common.menu": "Меню",
  "common.noRole": "Роль не назначена", "common.refresh": "Обновить", "common.details": "Подробнее", "common.skuNone": "Нет артикула",
  "unit.thousand": "тыс.", "unit.million": "млн", "unit.billion": "млрд", "unit.currency": "сум",
  "status.active": "Активен", "status.inactive": "Неактивен", "status.leave": "В отпуске", "status.archived": "В архиве",
  "status.success": "Успешно", "status.failed": "Ошибка / отклонено", "status.draft": "Черновик",
  "status.approved": "Подтверждён", "status.cancelled": "Отменён", "status.open": "Открыта", "status.closed": "Закрыта",

  "nav.dashboard": "Панель управления", "nav.pos": "Продажа (POS)", "nav.sales": "История продаж", "nav.customers": "Клиенты",
  "nav.suppliers": "Поставщики", "nav.products": "Товары", "nav.labels": "Этикетки", "nav.categories": "Категории",
  "nav.units": "Единицы измерения", "nav.branches": "Филиалы", "nav.warehouses": "Склады", "nav.purchases": "Закупки (приход)",
  "nav.purchaseReturns": "Возврат поставщику", "nav.issues": "Расход", "nav.transfers": "Перемещение между складами",
  "nav.inventory": "Инвентаризация", "nav.stockMovements": "Движение остатков", "nav.cashboxes": "Кассы", "nav.bank": "Банк",
  "nav.expenses": "Расходы", "nav.shifts": "Кассовые смены", "nav.workSchedules": "График работы", "nav.users": "Пользователи",
  "nav.employees": "Сотрудники", "nav.roles": "Роли и права", "nav.reports": "Отчёты", "nav.audit": "Журнал аудита", "nav.imports": "Импорт Excel",
  "nav.more": "Ещё", "nav.home": "Главная", "nav.notifications": "Уведомления", "nav.onboarding": "Первичная настройка", "nav.security": "Безопасность", "nav.batches": "Партии и сроки", "nav.pricing": "Акции и скидки", "nav.loyalty": "Карты лояльности", "nav.telegram": "Уведомления Telegram", "nav.sessions": "Активные сессии",

  "shell.noAccess": "У вас нет доступа к этому разделу.", "shell.breadcrumb": "Навигация",
  "prefs.title": "Настройки отображения", "prefs.language": "Язык", "prefs.theme": "Тема", "prefs.density": "Плотность таблиц",
  "theme.light": "Светлая", "theme.dark": "Тёмная", "theme.system": "Системная", "density.comfortable": "Обычная", "density.compact": "Компактная (как в 1С)",

  "login.subtitle": "Войдите в свой аккаунт", "login.email": "Email", "login.password": "Пароль", "login.submit": "Войти",

  "dash.title": "Панель управления", "dash.subtitle": "Текущее состояние магазина и основные показатели",
  "dash.badge": "Store ERP · Обзор", "dash.welcome": "Добро пожаловать, {name}!", "dash.userFallback": "пользователь",
  "dash.intro": "Следите за продажами, финансами и складом в одном месте. Отчёты обновляются по выбранному периоду и филиалу.",
  "dash.period": "Период отчёта", "dash.roleNone": "Роль не назначена", "dash.filters": "Фильтры отчёта",
  "dash.filtersHint": "Смотрите показатели за нужный период и по нужному филиалу.", "dash.dateFrom": "Дата начала", "dash.dateTo": "Дата окончания",
  "dash.branch": "Филиал", "dash.allBranches": "Все филиалы", "dash.noBranches": "Фильтр по филиалам появится после настройки филиалов.",
  "dash.compare": "Сравнить с предыдущим периодом", "dash.comparePeriod": "Предыдущий период: {from} — {to}",
  "dash.loadingKpi": "Загрузка показателей...", "dash.kpiTitle": "Основные показатели", "dash.kpiHint": "Финансы и склад за выбранный период",
  "dash.toReports": "Перейти к отчётам", "dash.vsPrev": "к предыдущему периоду",
  "kpi.sales_net": "Чистые продажи", "kpi.sales_net.note": "Выбранный период", "kpi.gross_profit": "Валовая прибыль", "kpi.gross_profit.note": "Расчётный показатель",
  "kpi.approved_expenses": "Расходы", "kpi.approved_expenses.note": "Подтверждённые", "kpi.operating_result": "Операционный результат", "kpi.operating_result.note": "Прибыль − расходы",
  "kpi.cash_balance": "Остаток в кассе", "kpi.cash_balance.note": "Текущий остаток", "kpi.bank_balance": "Остаток в банке", "kpi.bank_balance.note": "Текущий остаток",
  "kpi.customer_debt_estimate": "Долги клиентов", "kpi.customer_debt_estimate.note": "Ориентировочный итог",
  "kpi.low_stock_products": "Товары с малым остатком", "kpi.low_stock_products.note": "Минимум или меньше",
  "dash.trendTitle": "Динамика продаж", "dash.trendHint": "Дневная выручка и возвраты", "dash.legendSales": "Продажи", "dash.legendReturns": "Возвраты",
  "dash.legendPrev": "Продажи прошлого периода", "dash.chartEmpty": "Недостаточно данных для графика продаж за этот период.",
  "dash.axisAmount": "Сумма (сум)", "dash.points": "Точек (дней): {n}", "dash.revenue": "Выручка", "dash.returnsTotal": "Возвраты",
  "dash.prevRevenue": "Выручка прошлого периода", "dash.colDate": "Дата", "dash.colSales": "Продажи", "dash.colReturns": "Возвраты", "dash.colNet": "Чистые продажи",
  "dash.quickTitle": "Быстрые действия", "dash.quickHint": "Часто используемые разделы",
  "dash.quick.pos": "Новая продажа", "dash.quick.pos.d": "Начать продажу на кассе", "dash.quick.products": "Товары", "dash.quick.products.d": "Каталог и цены",
  "dash.quick.warehouses": "Склады", "dash.quick.warehouses.d": "Остатки и склады", "dash.quick.reports": "Все отчёты", "dash.quick.reports.d": "Продажи, склад и финансы",
  "dash.topTitle": "Самые продаваемые товары", "dash.topHint": "ТОП товаров за выбранный период", "dash.topMore": "Подробнее →",
  "dash.colProduct": "Товар", "dash.colQty": "Кол-во", "dash.colAmount": "Сумма продаж", "dash.topEmpty": "За выбранный период данных о продажах нет.",
  "dash.lowTitle": "Товары с малым остатком", "dash.lowHint": "Остаток достиг минимального уровня", "dash.lowMore": "Отчёт по складу →",
  "dash.lowEmpty": "Товаров с малым остатком не найдено.", "dash.min": "Мин: {value}",
  "dash.footNote": "Данные основаны на подтверждённых документах в базе ERP.", "dash.footUser": "Пользователь: {email} · Прав: {count}",
  "dash.restrictedTitle": "Панель управления ограничена", "dash.restrictedText": "Чтобы видеть статистику, администратор должен выдать вам право reports.view.",
};

const cyrl = Object.fromEntries(Object.entries(uz).map(([k, v]) => [k, toCyrillic(v)])) as Record<MessageKey, string>;
const DICTS: Record<Locale, Record<string, string>> = { uz, "uz-cyrl": cyrl, ru };

type Params = Record<string, string | number>;
type I18nValue = { locale: Locale; setLocale: (l: Locale) => void; t: (key: MessageKey, params?: Params) => string };
const I18nContext = createContext<I18nValue | null>(null);

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("uz");
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const next: Locale = "uz";
    localStorage.removeItem(KEY);
    setFormatLocale(next);
    setLocaleState(next);
    setReady(true);
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale === "ru" ? "ru" : locale === "uz-cyrl" ? "uz-Cyrl" : "uz";
  }, [locale]);

  const setLocale = useCallback((_l: Locale) => {
    // Professional ERP interfeysi hozircha faqat o‘zbek (lotin) tilida ishlaydi.
    localStorage.removeItem(KEY);
    setFormatLocale("uz");
    setLocaleState("uz");
  }, []);

  const t = useCallback((key: MessageKey, params?: Params) => {
    let text = DICTS[locale][key] ?? uz[key] ?? key;
    if (params) for (const [k, v] of Object.entries(params)) text = text.split(`{${k}}`).join(String(v));
    return text;
  }, [locale]);

  const value = useMemo(() => ({ locale, setLocale, t }), [locale, setLocale, t]);
  // Til almashganda sahifa qayta chiziladi, shunda format.ts funksiyalari ham yangi tilda ishlaydi.
  return <I18nContext.Provider value={value}>{ready ? <div key={locale} className="contents">{children}</div> : null}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n faqat I18nProvider ichida ishlatiladi");
  return ctx;
}
