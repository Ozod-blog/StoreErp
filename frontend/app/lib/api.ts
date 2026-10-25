const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";
const TOKEN_KEY = "store_erp_token";

// Qisqa muddatli, faqat joriy brauzer xotirasidagi GET kesh.
// Faqat katalog/roʻyxat endpointlari keshlanadi; moliya va tranzaksiya API'lari keshlanmaydi.
const GET_CACHE_TTL_MS = 30_000;
const getCache = new Map<string, { expiresAt: number; data: unknown }>();
const inFlightGets = new Map<string, Promise<unknown>>();
const CACHEABLE_PATHS = new Set(["/products", "/users", "/categories", "/units", "/roles"]);

function clearApiCache() {
    getCache.clear();
}

/** Invalidate this browser's API cache and notify active pages after a data mutation. */
export function notifyErpDataChanged(detail?: { path?: string; method?: string; changedAt?: string }) {
    clearApiCache();
    if (typeof window !== "undefined") {
        window.dispatchEvent(new CustomEvent("erp:data-changed", {
            detail: {
                path: detail?.path ?? "*", method: detail?.method ?? "LOCAL", changedAt: detail?.changedAt ?? new Date().toISOString(),
            }
        }));
    }
}

if (typeof window !== "undefined") {
    window.addEventListener("erp:data-changed", clearApiCache);
}

function makeCacheKey(url: URL, token: string | null) {
    // Token kesh kalitiga kiradi: turli foydalanuvchilar ma'lumoti aralashmaydi.
    return `${token ?? "anonymous"}:${url.toString()}`;
}

export const tokenStore = {
    get: () => (typeof window === "undefined" ? null : localStorage.getItem(TOKEN_KEY)),
    set: (token: string) => { localStorage.setItem(TOKEN_KEY, token); clearApiCache(); },
    clear: () => { localStorage.removeItem(TOKEN_KEY); clearApiCache(); },
};

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;
    constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

type Query = Record<string, string | number | null | undefined>;

export async function api<T>(
    path: string,
    options: { method?: string; body?: unknown; query?: Query; bypassCache?: boolean } = {},
): Promise<T> {
    const url = new URL(`${API_URL}${path}`);
    Object.entries(options.query ?? {}).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
    });

    const headers: Record<string, string> = { Accept: "application/json" };
    const token = tokenStore.get();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body !== undefined) headers["Content-Type"] = "application/json";

    const method = (options.method ?? "GET").toUpperCase();
    const cacheable = method === "GET" && CACHEABLE_PATHS.has(path);
    const useCachedValue = cacheable && !options.bypassCache;
    const dedupeGet = method === "GET";
    const cacheKey = makeCacheKey(url, token);
    if (useCachedValue) {
        const cached = getCache.get(cacheKey);
        if (cached && cached.expiresAt > Date.now()) return cached.data as T;
        if (cached) getCache.delete(cacheKey);
    }
    // Bir xil GET bir vaqtda bir necha komponentdan chaqirilsa, bitta HTTP so‘rovi ishlaydi.
    if (dedupeGet) {
        const pending = inFlightGets.get(cacheKey);
        if (pending) return pending as Promise<T>;
    }

    const requestPromise = (async (): Promise<T> => {
        let response: Response;
        try {
            response = await fetch(url.toString(), {
                method,
                headers,
                body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
                cache: "no-store",
            });
        } catch {
            throw new ApiError("Serverga ulanib bo‘lmadi. Internet yoki API manzilini tekshiring.", 0);
        }

        const data = await response.json().catch(() => ({}));

        if (response.status === 401 && token) {
            tokenStore.clear();
            if (typeof window !== "undefined" && !window.location.pathname.startsWith("/login")) {
                window.location.href = "/login";
            }
        }
        if (response.status === 429) {
            throw new ApiError("Juda ko‘p urinish. Biroz kutib, qayta urinib ko‘ring.", 429);
        }
        if (!response.ok) {
            throw new ApiError(data?.message || "So‘rov bajarilmadi", response.status, data?.errors ?? {});
        }

        // Muvaffaqiyatli yozishdan keyin eski roʻyxat keshlari bekor qilinadi.
        if (method !== "GET") notifyErpDataChanged({ path, method });
        else if (cacheable) getCache.set(cacheKey, { data, expiresAt: Date.now() + GET_CACHE_TTL_MS });
        return data as T;
    })();

    if (dedupeGet) {
        inFlightGets.set(cacheKey, requestPromise);
        try { return await requestPromise; }
        finally { if (inFlightGets.get(cacheKey) === requestPromise) inFlightGets.delete(cacheKey); }
    }
    return requestPromise;
}

/** Validatsiya xatolarini bitta matnga aylantiradi. */
export function errorMessage(e: unknown): string {
    if (e instanceof ApiError) {
        const first = Object.values(e.errors)[0]?.[0];
        return first || e.message;
    }
    return e instanceof Error ? e.message : "Noma’lum xatolik";
}
