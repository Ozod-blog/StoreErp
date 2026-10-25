import { ApiError, tokenStore } from "./api";

// api.ts dagi bilan bir xil manzil (NEXT_PUBLIC_API_URL yoki lokal standart).
const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";

/** Faylni multipart sifatida yuboradi. 422 kabi javoblarda ham JSON tanasini qaytaradi (xatolar jadvali uchun). */
export async function uploadFile<T>(path: string, file: File, fields: Record<string, string> = {}): Promise<{ status: number; data: T }> {
  const form = new FormData();
  form.append("file", file);
  Object.entries(fields).forEach(([k, v]) => { if (v) form.append(k, v); });
  const token = tokenStore.get();
  let res: Response;
  try {
    res = await fetch(`${API_URL}${path}`, {
      method: "POST",
      headers: { Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: form,
    });
  } catch {
    throw new ApiError("Serverga ulanib bo‘lmadi.", 0);
  }
  if (res.status === 401) {
    tokenStore.clear();
    if (typeof window !== "undefined") window.location.href = "/login";
  }
  const data = (await res.json().catch(() => ({}))) as T;
  return { status: res.status, data };
}
