import { useCallback } from "react";
import { useInterfaceLocale } from "./InterfaceLocaleContext";
import { translate, type MessageKey, type MessageValues } from "./messages";

export function useTranslation() {
  const locale = useInterfaceLocale();
  const t = useCallback(
    (key: MessageKey, values?: MessageValues) => translate(locale, key, values),
    [locale],
  );

  return { locale, t };
}
