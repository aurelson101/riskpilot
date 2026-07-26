import { useLayoutEffect, type PropsWithChildren } from "react";
import { InterfaceLocaleContext } from "./InterfaceLocaleContext";
import { enToFr, frToEn, phrasePairs, type Locale } from "./translations";

const originalText = new WeakMap<Text, string>();
const originalAttributes = new WeakMap<Element, Map<string, string>>();
const translatedAttributes = ["aria-label", "placeholder", "title"];

function replacePhrase(value: string, source: string, target: string) {
  if (source.startsWith(" ") || source.endsWith(" "))
    return value.split(source).join(target);
  const escaped = source.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(
    `(^|[^\\p{L}\\p{N}])${escaped}(?=$|[^\\p{L}\\p{N}])`,
    "gu",
  );
  return value.replace(pattern, (_match, prefix: string) => prefix + target);
}

function translateValue(value: string, locale: Locale): string {
  const dictionary = locale === "en" ? frToEn : enToFr;
  const exact = dictionary.get(value);
  if (exact) return exact;

  let translated = value;
  for (const [fr, en] of phrasePairs) {
    const source = locale === "en" ? fr : en;
    const target = locale === "en" ? en : fr;
    if (source.length < 4 || !translated.includes(source)) continue;
    translated = replacePhrase(translated, source, target);
  }
  return translated;
}

function translateTextNode(node: Text, locale: Locale) {
  const current = node.nodeValue ?? "";
  let original = originalText.get(node);
  if (original !== undefined) {
    const trimmed = original.trim();
    const expected = trimmed
      ? original.replace(trimmed, translateValue(trimmed, locale))
      : original;
    if (current !== expected) original = current;
  } else {
    original = current;
  }
  originalText.set(node, original);
  const trimmed = original.trim();
  if (!trimmed) return;
  const leading = original.slice(0, original.indexOf(trimmed));
  const trailing = original.slice(original.indexOf(trimmed) + trimmed.length);
  const translated = `${leading}${translateValue(trimmed, locale)}${trailing}`;
  if (current !== translated) node.nodeValue = translated;
}

function translateElement(element: Element, locale: Locale) {
  const originals =
    originalAttributes.get(element) ?? new Map<string, string>();
  for (const attribute of translatedAttributes) {
    const current = element.getAttribute(attribute);
    if (current === null) continue;
    if (!originals.has(attribute)) originals.set(attribute, current);
    element.setAttribute(
      attribute,
      translateValue(originals.get(attribute) ?? current, locale),
    );
  }
  originalAttributes.set(element, originals);
}

function translateTree(root: Node, locale: Locale) {
  if (root.nodeType === Node.TEXT_NODE) {
    translateTextNode(root as Text, locale);
    return;
  }
  if (root.nodeType !== Node.ELEMENT_NODE) return;
  const element = root as Element;
  translateElement(element, locale);
  const walker = document.createTreeWalker(
    element,
    NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
  );
  let node = walker.nextNode();
  while (node) {
    if (node.nodeType === Node.TEXT_NODE)
      translateTextNode(node as Text, locale);
    else translateElement(node as Element, locale);
    node = walker.nextNode();
  }
}

export function LanguageBoundary({
  locale,
  children,
}: PropsWithChildren<{ locale: Locale }>) {
  useLayoutEffect(() => {
    document.documentElement.lang = locale;
    translateTree(document.body, locale);
    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.type === "characterData") {
          translateTextNode(mutation.target as Text, locale);
        }
        for (const node of mutation.addedNodes) translateTree(node, locale);
      }
    });
    observer.observe(document.body, {
      characterData: true,
      childList: true,
      subtree: true,
    });
    return () => observer.disconnect();
  }, [locale]);

  return (
    <InterfaceLocaleContext.Provider value={locale}>
      {children}
    </InterfaceLocaleContext.Provider>
  );
}
