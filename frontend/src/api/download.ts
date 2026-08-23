import { api } from "./client";

function filenameFromDisposition(value: string | undefined, fallback: string) {
  if (!value) return fallback;
  const encoded = value.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) return decodeURIComponent(encoded);
  return value.match(/filename="([^"]+)"/i)?.[1] ?? fallback;
}

export async function downloadApiFile(path: string, fallback: string) {
  const response = await api.get<Blob>(path, { responseType: "blob" });
  const url = URL.createObjectURL(response.data);
  const link = document.createElement("a");
  link.href = url;
  link.download = filenameFromDisposition(
    response.headers["content-disposition"],
    fallback,
  );
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
}
