import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { InterfaceLocaleContext } from "../i18n/InterfaceLocaleContext";
import { useConfirmation } from "./confirmation-context";
import { ConfirmationProvider } from "./ConfirmationProvider";

function Trigger({ onResult }: { onResult: (result: boolean) => void }) {
  const confirm = useConfirmation();
  return (
    <button
      onClick={async () =>
        onResult(
          await confirm({
            message: "confirmation.archiveRisk",
            values: { name: "Supplier access" },
          }),
        )
      }
    >
      Open
    </button>
  );
}

describe("ConfirmationProvider", () => {
  it("renders an accessible localized dialog and resolves confirmation", async () => {
    const onResult = vi.fn();
    render(
      <InterfaceLocaleContext.Provider value="en">
        <ConfirmationProvider>
          <Trigger onResult={onResult} />
        </ConfirmationProvider>
      </InterfaceLocaleContext.Provider>,
    );

    fireEvent.click(screen.getByRole("button", { name: "Open" }));
    expect(
      await screen.findByRole("dialog", { name: "Confirm action" }),
    ).toHaveAccessibleDescription("Archive “Supplier access”?");
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(onResult).toHaveBeenCalledWith(true));
  });
});
