/** Spreadsheet-safe UTF-8 CSV. User content is never interpreted as a formula. */
export function csvDocument(rows: readonly (readonly unknown[])[]): string {
  return (
    "\ufeff" +
    rows
      .map((row) =>
        row
          .map((value) => {
            let text = value == null ? "" : String(value);
            let firstVisible = 0;
            while (
              firstVisible < text.length &&
              (/\s/u.test(text[firstVisible]) ||
                text.charCodeAt(firstVisible) <= 31)
            )
              ++firstVisible;
            if (
              (firstVisible < text.length && "=+@-".includes(text[firstVisible])) ||
              [9, 10, 13].includes(text.charCodeAt(0))
            )
              text = "'" + text;
            return '"' + text.replace(/"/g, '""') + '"';
          })
          .join(";"),
      )
      .join("\r\n") +
    "\r\n"
  );
}

export function downloadCsv(
  filename: string,
  rows: readonly (readonly unknown[])[],
): void {
  const url = URL.createObjectURL(
    new Blob([csvDocument(rows)], { type: "text/csv;charset=utf-8" }),
  );
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
