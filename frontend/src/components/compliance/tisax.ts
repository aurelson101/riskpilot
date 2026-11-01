import type { ComplianceAssessment } from "../../api/types";

export function isTisaxAssessment(assessment: ComplianceAssessment) {
  return /tisax/i.test(assessment.framework.name);
}
