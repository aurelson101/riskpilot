import { createContext, useContext } from "react";
import type { MessageKey, MessageValues } from "../i18n/messages";

export type ConfirmationRequest = {
  message: MessageKey;
  values?: MessageValues;
  confirmLabel?: MessageKey;
};

export type ConfirmationContextValue = (
  request: ConfirmationRequest,
) => Promise<boolean>;

export const ConfirmationContext =
  createContext<ConfirmationContextValue | null>(null);

export function useConfirmation() {
  const context = useContext(ConfirmationContext);
  if (!context) {
    throw new Error("useConfirmation must be used inside ConfirmationProvider");
  }
  return context;
}
