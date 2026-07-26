import type { Locale } from "./translations";

export function confirmLocalized(
  locale: Locale,
  messages: Record<Locale, string>,
) {
  return window.confirm(messages[locale]);
}
