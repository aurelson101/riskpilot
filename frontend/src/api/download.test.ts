import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "./client";
import { downloadApiFile } from "./download";

let filename = "";
beforeEach(() => {
  filename = "";
  vi.stubGlobal("URL", {
    createObjectURL: vi.fn(() => "blob:test"),
    revokeObjectURL: vi.fn(),
  });
  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function (
    this: HTMLAnchorElement,
  ) {
    filename = this.download;
  });
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});
function file(type = "text/csv", disposition?: string) {
  vi.spyOn(api, "get").mockResolvedValue({
    data: new Blob(["report"], { type }),
    headers: { "content-disposition": disposition },
  });
}
describe("secure downloads", () => {
  it.each(["text/html", "application/json", "application/javascript"])(
    "blocks unexpected MIME %s",
    async (type) => {
      file(type);
      await expect(
        downloadApiFile("/exports/risks.csv", "risks.csv"),
      ).rejects.toThrow();
      expect(URL.createObjectURL).not.toHaveBeenCalled();
    },
  );
  it("blocks empty and oversized files", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValueOnce({
      data: new Blob([], { type: "text/csv" }),
      headers: {},
    });
    await expect(
      downloadApiFile("/exports/risks.csv", "risks.csv"),
    ).rejects.toThrow();
    const blob = new Blob(["report"], { type: "text/csv" });
    vi.spyOn(blob, "size", "get").mockReturnValue(51 * 1024 * 1024);
    get.mockResolvedValueOnce({ data: blob, headers: {} });
    await expect(
      downloadApiFile("/exports/risks.csv", "risks.csv"),
    ).rejects.toThrow();
  });
  it.each([
    ['attachment; filename="../../report.csv"', "report.csv"],
    ["attachment; filename*=UTF-8''%ZZ", "risks.csv"],
    ['attachment; filename="report.exe"', "risks.csv"],
    ["attachment; filename*=UTF-8''risk%E2%80%AE.csv", "risk.csv"],
  ])("sanitizes %s", async (disposition, expected) => {
    file("text/csv", disposition);
    await downloadApiFile("/exports/risks.csv", "risks.csv");
    expect(filename).toBe(expected);
  });
  it("cleans up the anchor and blob URL even if the click fails", async () => {
    vi.useFakeTimers();
    file();
    vi.mocked(HTMLAnchorElement.prototype.click).mockImplementation(() => {
      throw new Error("blocked");
    });
    await expect(
      downloadApiFile("/exports/risks.csv", "risks.csv"),
    ).rejects.toThrow("blocked");
    expect(document.querySelector("a[download]")).toBeNull();
    vi.runAllTimers();
    expect(URL.revokeObjectURL).toHaveBeenCalledOnce();
  });
});
