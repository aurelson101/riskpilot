import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { FrameworkImportDialog } from "./FrameworkImportDialog";

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("../../api/client", () => ({ api: { post } }));
vi.mock("../../auth/useAuth", () => ({ useAuth: () => ({ user: { locale: "en" } }) }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe("FrameworkImportDialog", () => {
  it("requires preview, confirms and invalidates preview after an edit", async () => {
    const imported = vi.fn();
    const close = vi.fn();
    render(<FrameworkImportDialog open onClose={close} onImported={imported} />);
    expect(screen.getByRole("button", { name: "Preview" })).toBeDisabled();
    expect(screen.getByRole("link", { name: "Download CSV template" })).toHaveAttribute("download");
    fireEvent.change(screen.getByLabelText("Framework name"), { target: { value: "Local" } });
    fireEvent.change(screen.getByLabelText("Version"), { target: { value: "1" } });
    const csv = "reference,title,category,description,parentReference\nA,Root,Security,,\n";
    const file = new File([csv], "local.csv", { type: "text/csv" });
    Object.defineProperty(file, "arrayBuffer", { value: async () => new TextEncoder().encode(csv).buffer });
    fireEvent.change(document.querySelector('input[type="file"]')!, { target: { files: [file] } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Preview" })).toBeEnabled());
    post.mockResolvedValue({ data: { checksum: "test-checksum", count: 1, requirements: [{ reference: "A", title: "Root", parentReference: "", status: "ARCHIVED" }] } });
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    expect(await screen.findByRole("button", { name: "Confirm creation" })).toBeEnabled();
    expect(screen.getByText(/Archived$/)).toBeInTheDocument();
    expect(imported).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Version"), { target: { value: "2" } });
    expect(screen.queryByRole("button", { name: "Confirm creation" })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    fireEvent.click(await screen.findByRole("button", { name: "Confirm creation" }));
    await waitFor(() => expect(imported).toHaveBeenCalledOnce());
    expect(post).toHaveBeenLastCalledWith("/frameworks/import/confirm", { name: "Local", version: "2", csv, checksum: "test-checksum" });
    expect(close).toHaveBeenCalledOnce();
  });

  it("rejects oversized files before any network request", () => {
    render(<FrameworkImportDialog open onClose={vi.fn()} onImported={vi.fn()} />);
    const file = new File(["data"], "large.csv");
    Object.defineProperty(file, "size", { value: 1048577 });
    fireEvent.change(document.querySelector('input[type="file"]')!, { target: { files: [file] } });
    expect(screen.getByText("File exceeds 1 MiB.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });
});
