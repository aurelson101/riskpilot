import { describe, expect, it } from "vitest";
import type { ComplianceResult } from "../api/types";
import { buildComplianceSummary } from "./complianceSummary";

function result(
  reference: string,
  complianceStatus: ComplianceResult["complianceStatus"],
  maturityLevel: number,
): ComplianceResult {
  return {
    id: Number(reference.replace(/\D/g, "")),
    complianceStatus,
    maturityLevel,
    requirement: { reference, title: reference },
  } as ComplianceResult;
}

describe("buildComplianceSummary", () => {
  it("ne transforme pas les exigences non évaluées en scores nuls", () => {
    const summary = buildComplianceSummary([
      result("ART-1", "COMPLIANT", 4),
      result("ART-2", "PARTIAL", 2),
      result("ART-3", "NOT_ASSESSED", 0),
      result("ART-4", "NOT_APPLICABLE", 0),
    ]);

    expect(summary.radar.map((item) => item.requirement)).toEqual([
      "ART-1",
      "ART-2",
    ]);
    expect(summary.average).toBe(3);
    expect(summary.weak).toHaveLength(1);
    expect(summary.strong).toHaveLength(1);
    expect(summary.remaining).toHaveLength(1);
    expect(summary.notApplicable).toHaveLength(1);
  });
});
