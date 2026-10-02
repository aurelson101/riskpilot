import { api } from "./client";

function filenameFromDisposition(value: string | undefined, fallback: string) {
  let name = fallback;
  if (value) {
    const encoded = value.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    try {
      name = encoded
        ? decodeURIComponent(encoded)
        : (value.match(/filename="([^"]+)"/i)?.[1] ?? fallback);
    } catch {
      name = fallback;
    }
  }
  name = name.split(/[\\/]/).pop() ?? fallback;
  name = Array.from(name)
    .filter((character) => {
      const code = character.charCodeAt(0);
      return (
        code >= 32 &&
        code !== 127 &&
        !(code >= 0x202a && code <= 0x202e) &&
        !(code >= 0x2066 && code <= 0x2069)
      );
    })
    .join("")
    .replace(/[<>:"|?*]/g, "_")
    .trim();
  const extension = fallback.split(".").pop();
  return name &&
    name.length <= 180 &&
    name.toLowerCase().endsWith(`.${extension}`)
    ? name
    : fallback;
}

export async function downloadApiFile(
  path: string,
  fallback: string,
  signal?: AbortSignal,
) {
  const response = await api.get<Blob>(path, {
    responseType: "blob",
    timeout: 120_000,
    signal,
  });
  const extension = fallback.split(".").pop();
  const types: Record<string, string[]> = {
    csv: ["text/csv", "application/csv"],
    pdf: ["application/pdf"],
    xlsx: ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"],
  };
  const contentType = String(
    response.headers["content-type"] ?? response.data.type,
  )
    .split(";")[0]
    .trim()
    .toLowerCase();
  if (
    !(response.data instanceof Blob) ||
    !response.data.size ||
    response.data.size > 50 * 1024 * 1024 ||
    !types[extension ?? ""]?.includes(contentType)
  )
    throw new Error("Format ou taille du fichier invalide");
  const url = URL.createObjectURL(response.data);
  const link = document.createElement("a");
  link.href = url;
  link.download = filenameFromDisposition(
    response.headers["content-disposition"],
    fallback,
  );
  link.style.display = "none";
  try {
    document.body.appendChild(link);
    link.click();
  } finally {
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
  }
}
