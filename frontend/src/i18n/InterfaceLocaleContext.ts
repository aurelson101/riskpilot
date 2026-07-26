import { createContext, useContext } from "react";
import type { Locale } from "./translations";

export const InterfaceLocaleContext = createContext<Locale>("fr");

export function useInterfaceLocale() {
  return useContext(InterfaceLocaleContext);
}
