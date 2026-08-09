import type { ComplianceResult } from "../api/types";

export function buildComplianceSummary(items: ComplianceResult[]) {
  const assessed = items.filter(
    (item) =>
      item.complianceStatus !== "NOT_ASSESSED" &&
      item.complianceStatus !== "NOT_APPLICABLE",
  );
  const average = assessed.length
    ? assessed.reduce((sum, item) => sum + item.maturityLevel, 0) /
      assessed.length
    : null;

  return {
    radar: assessed.map((item) => ({
      requirement: item.requirement.reference,
      title: item.requirement.title,
      maturity: item.maturityLevel,
      fullMark: 5,
    })),
    average,
    weak: assessed.filter((item) => item.maturityLevel <= 2),
    strong: assessed.filter((item) => item.maturityLevel >= 4),
    remaining: items.filter((item) => item.complianceStatus === "NOT_ASSESSED"),
    notApplicable: items.filter(
      (item) => item.complianceStatus === "NOT_APPLICABLE",
    ),
  };
}
