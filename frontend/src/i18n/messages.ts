import type { Locale } from "./translations";

export const messages = {
  "common.cancel": {
    fr: "Annuler",
    en: "Cancel",
  },
  "common.confirm": {
    fr: "Confirmer",
    en: "Confirm",
  },
  "confirmation.title": {
    fr: "Confirmer l’action",
    en: "Confirm action",
  },
  "confirmation.archiveRisk": {
    fr: "Archiver « {name} » ?",
    en: "Archive “{name}”?",
  },
  "confirmation.cancelAction": {
    fr: "Annuler « {name} » ?",
    en: "Cancel “{name}”?",
  },
  "confirmation.deleteDocument": {
    fr: "Supprimer définitivement le document « {name} » ?",
    en: "Permanently delete document “{name}”?",
  },
  "confirmation.deleteNamed": {
    fr: "Supprimer « {name} » ?",
    en: "Delete “{name}”?",
  },
  "confirmation.deleteRecord": {
    fr: "Supprimer définitivement cet enregistrement ?",
    en: "Permanently delete this record?",
  },
  "confirmation.disableOrganization": {
    fr: "Désactiver l’organisation « {name} » ?",
    en: "Disable organization “{name}”?",
  },
  "confirmation.disableUser": {
    fr: "Désactiver l’utilisateur « {name} » ?",
    en: "Disable user “{name}”?",
  },
} as const;

export type MessageKey = keyof typeof messages;
export type MessageValues = Record<string, string | number>;

export function translate(
  locale: Locale,
  key: MessageKey,
  values: MessageValues = {},
): string {
  let result: string = messages[key][locale];
  for (const [name, value] of Object.entries(values)) {
    result = result.replaceAll(`{${name}}`, String(value));
  }
  return result;
}
