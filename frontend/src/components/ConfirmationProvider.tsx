import {
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
} from "@mui/material";
import { useCallback, useRef, useState, type ReactNode } from "react";
import { useTranslation } from "../i18n/useTranslation";
import {
  ConfirmationContext,
  type ConfirmationRequest,
} from "./confirmation-context";

export function ConfirmationProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const [request, setRequest] = useState<ConfirmationRequest | null>(null);
  const resolveRef = useRef<((confirmed: boolean) => void) | null>(null);

  const confirm = useCallback(
    (nextRequest: ConfirmationRequest) =>
      new Promise<boolean>((resolve) => {
        resolveRef.current?.(false);
        resolveRef.current = resolve;
        setRequest(nextRequest);
      }),
    [],
  );

  const close = useCallback((confirmed: boolean) => {
    resolveRef.current?.(confirmed);
    resolveRef.current = null;
    setRequest(null);
  }, []);

  return (
    <ConfirmationContext.Provider value={confirm}>
      {children}
      <Dialog
        open={request !== null}
        onClose={() => close(false)}
        aria-labelledby="confirmation-dialog-title"
        aria-describedby="confirmation-dialog-description"
      >
        <DialogTitle id="confirmation-dialog-title">
          {t("confirmation.title")}
        </DialogTitle>
        <DialogContent>
          <DialogContentText id="confirmation-dialog-description">
            {request ? t(request.message, request.values) : ""}
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => close(false)}>{t("common.cancel")}</Button>
          <Button
            color="error"
            variant="contained"
            onClick={() => close(true)}
            autoFocus
          >
            {t(request?.confirmLabel ?? "common.confirm")}
          </Button>
        </DialogActions>
      </Dialog>
    </ConfirmationContext.Provider>
  );
}
