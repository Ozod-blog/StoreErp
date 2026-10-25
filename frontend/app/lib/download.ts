import { ApiError, tokenStore } from "./api";

// api.ts dagi bilan bir xil manzil (NEXT_PUBLIC_API_URL yoki lokal standart).
const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";

/** Token bilan faylni yuklab oladi (Excel/PDF) va brauzerda saqlashni boshlaydi. */
export async function downloadFile(path: string, query: Record<string, string | number | null | undefined>, fallbackName: string): Promise<void> {
  const url = new URL(`${API_URL}${path}`);
  Object.entries(query).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
  });
  const token = tokenStore.get();
  let res: Response;
  try {
    res = await fetch(url.toString(), { headers: { Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
  } catch {
    throw new ApiError("Serverga ulanib bo‘lmadi.", 0);
  }
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new ApiError(data?.message || "Faylni yuklab bo‘lmadi", res.status);
  }
  const disposition = res.headers.get("content-disposition") || "";
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  const blob = await res.blob();
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = match ? decodeURIComponent(match[1]) : fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(link.href);
}
