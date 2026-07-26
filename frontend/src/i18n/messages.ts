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
  "actionFields.title": {
    fr: "Colonnes des actions",
    en: "Action columns",
  },
  "actionFields.description": {
    fr: "Définissez les champs spécifiques à l’organisation affichés dans les plans d’action.",
    en: "Define organization-specific fields shown in action plans.",
  },
  "actionFields.key": {
    fr: "Clé",
    en: "Key",
  },
  "actionFields.keyHelp": {
    fr: "1 à 80 caractères : lettres minuscules, chiffres et tirets bas",
    en: "1–80 characters: lowercase letters, numbers and underscores",
  },
  "actionFields.keyInvalid": {
    fr: "Commencez par une lettre minuscule, puis utilisez uniquement des lettres minuscules, chiffres ou tirets bas.",
    en: "Start with a lowercase letter, then use only lowercase letters, numbers or underscores.",
  },
  "actionFields.label": {
    fr: "Libellé",
    en: "Label",
  },
  "actionFields.type": {
    fr: "Type",
    en: "Type",
  },
  "actionFields.order": {
    fr: "Ordre d’affichage",
    en: "Display order",
  },
  "actionFields.visible": {
    fr: "Visible",
    en: "Visible",
  },
  "actionFields.required": {
    fr: "Obligatoire",
    en: "Required",
  },
  "actionFields.optional": {
    fr: "facultatif",
    en: "optional",
  },
  "actionFields.create": {
    fr: "Créer la colonne",
    en: "Create column",
  },
  "actionFields.creating": {
    fr: "Création…",
    en: "Creating…",
  },
  "actionFields.createError": {
    fr: "Le champ n’a pas pu être créé.",
    en: "The field could not be created.",
  },
  "actionFields.duplicateError": {
    fr: "Cette clé est déjà utilisée par une autre colonne.",
    en: "This key is already used by another column.",
  },
  "actionFields.validationError": {
    fr: "Vérifiez la clé, le libellé et le type du champ.",
    en: "Check the field key, label and type.",
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
