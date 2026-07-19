export interface Organization {
  id: number;
  name: string;
  description: string | null;
  status: string;
  riskThresholds: {
    lowMax: number;
    moderateMax: number;
    highMax: number;
    criticalMax: number;
  };
}

export type RiskLevel = "LOW" | "MODERATE" | "HIGH" | "CRITICAL";

export interface SecurityControl {
  id: number;
  name: string;
  description: string | null;
  category: string;
  effectiveness: number;
  implementationStatus: string;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName"> | null;
}

export interface RiskScenario {
  id: number;
  title: string;
  description: string | null;
  family: string;
  analysisMethod: "SIMPLIFIED" | "ISO_27005" | "EBIOS_RM";
  strategic: boolean;
  methodData: Record<string, string>;
  scope: { id: number; name: string };
  asset: { id: number; name: string };
  threat: { id: number; name: string };
  vulnerabilities: Array<{ id: number; name: string }>;
  currentControls: Array<{ id: number; name: string; effectiveness: number }>;
  riskOwner: Pick<User, "id" | "email" | "firstName" | "lastName">;
  likelihood: number;
  impact: number;
  grossRiskScore: number;
  currentLikelihood: number;
  currentImpact: number;
  currentRiskScore: number;
  residualLikelihood: number;
  residualImpact: number;
  residualRiskScore: number;
  treatmentDecision: string;
  status: string;
  reviewDate: string | null;
}

export interface RiskGovernancePolicy {
  id: number;
  domain: string;
  family: string;
  appetiteScore: number;
  toleranceScore: number;
  capacityScore: number;
  method: "SIMPLIFIED" | "ISO_27005" | "EBIOS_RM";
  rationale: string | null;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName">;
  updatedAt: string;
}

export interface RiskRecommendation {
  riskId: number;
  title: string;
  family: string;
  strategic: boolean;
  method: string;
  residualScore: number;
  position: string;
  recommendedDecision: string;
  treatment: {
    estimatedCost: number;
    estimatedEffortDays: number;
    expectedReduction: number;
    coverageGap: number;
    reductionPerThousand: number | null;
  };
  priority: number;
}

export interface RiskAcceptance {
  id: number;
  risk: { id: number; title: string; residualScore: number };
  requestedBy: { id: number; name: string };
  decidedBy: { id: number; name: string } | null;
  justification: string;
  authority: string;
  status: string;
  expiresAt: string;
  decisionComment: string | null;
  evidenceReference: string | null;
}

export interface RiskReviewCampaign {
  id: number;
  title: string;
  description: string | null;
  status: string;
  startsAt: string;
  dueAt: string;
  progress: { completed: number; total: number };
  reviews: Array<{
    id: number;
    risk: { id: number; title: string };
    reviewer: { id: number; name: string };
    status: string;
    baselineScore: number;
    reviewedScore: number | null;
    delta: number | null;
    comment: string | null;
  }>;
}

export interface RiskMatrixCell {
  likelihood: number;
  impact: number;
  score: number;
  level: RiskLevel;
  count: number;
  risks: Array<{ id: number; title: string; status: string }>;
}

export interface RiskMatrix {
  scoreType: "gross" | "current" | "residual";
  thresholds: Organization["riskThresholds"];
  cells: RiskMatrixCell[];
}

export interface ActionPlan {
  id: number;
  title: string;
  description: string | null;
  relatedRisk: { id: number; title: string };
  relatedControl: { id: number; name: string } | null;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName">;
  priority: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
  status:
    | "OPEN"
    | "PLANNED"
    | "IN_PROGRESS"
    | "BLOCKED"
    | "COMPLETED"
    | "CANCELLED"
    | "OVERDUE";
  startDate: string | null;
  dueDate: string;
  completionDate: string | null;
  progress: number;
  estimatedCost: string | null;
  estimatedEffortDays: string | null;
  actualCost: string | null;
  expectedRiskReduction: number | null;
  evidence: string[];
}

export interface Notification {
  id: number;
  type: string;
  title: string;
  message: string;
  link: string | null;
  isRead: boolean;
  createdAt: string;
}

export interface Framework {
  id: number;
  name: string;
  version: string;
  description: string | null;
  publisher: string | null;
  status: string;
  requirementCount: number;
}

export interface Requirement {
  id: number;
  frameworkId: number;
  reference: string;
  title: string;
  description: string | null;
  category: string;
  parentRequirementId: number | null;
  status: string;
}

export interface ComplianceAssessment {
  id: number;
  framework: Pick<Framework, "id" | "name" | "version">;
  scope: { id: number; name: string };
  assessor: Pick<User, "id" | "email" | "firstName" | "lastName">;
  assessmentDate: string;
  status: "DRAFT" | "IN_PROGRESS" | "COMPLETED" | "ARCHIVED";
  globalScore: number;
  resultCount: number;
}

export interface ComplianceResult {
  id: number;
  assessmentId: number;
  requirement: Requirement;
  maturityLevel: number;
  complianceStatus:
    | "COMPLIANT"
    | "PARTIAL"
    | "NON_COMPLIANT"
    | "NOT_APPLICABLE"
    | "NOT_ASSESSED";
  comment: string | null;
  evidence: string[];
  remediationAction: { id: number; title: string } | null;
}

export interface StatementOfApplicability {
  id: number;
  title: string;
  version: number;
  status: "DRAFT" | "IN_REVIEW" | "APPROVED" | "SUPERSEDED";
  framework: { id: number; name: string; version: string };
  scope: { id: number; name: string };
  owner: { id: number; name: string };
  approvedBy: { id: number; name: string } | null;
  approvedAt: string | null;
  itemCount: number;
  createdAt: string;
}

export interface SecurityControlTest {
  id: number;
  control: { id: number; name: string };
  tester: { id: number; name: string };
  type: "DESIGN" | "OPERATING_EFFECTIVENESS";
  frequency: string;
  result: "EFFECTIVE" | "PARTIALLY_EFFECTIVE" | "INEFFECTIVE" | "NOT_TESTED";
  procedure: string;
  sampleDescription: string | null;
  sampleSize: number | null;
  conclusion: string | null;
  evidence: string[];
  performedAt: string;
  nextReviewAt: string;
}

export interface RequirementMapping {
  id: number;
  source: { id: number; reference: string; framework: string };
  target: { id: number; reference: string; framework: string };
  coveragePercent: number;
  inheritEvidence: boolean;
  rationale: string | null;
}

export interface Dashboard {
  summary: {
    totalRisks: number;
    criticalRisks: number;
    highRisks: number;
    overdueActions: number;
    dueActions: number;
    globalCompliance: number;
  };
  riskLevels: Record<RiskLevel, number>;
  actionStatuses: Record<string, number>;
  complianceByFramework: Record<string, number>;
  topRisks: Array<{ id: number; title: string; score: number; status: string }>;
  dueActions: Array<{
    id: number;
    title: string;
    dueDate: string;
    status: string;
    priority: string;
  }>;
}
export interface User {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  roles: string[];
  status: string;
  organization: Organization;
  lastLoginAt: string | null;
  mfaEnabled: boolean;
}

export interface Scope {
  id: number;
  name: string;
  description: string | null;
  type: string;
  parentScopeId: number | null;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName"> | null;
  status: string;
}
export interface Asset {
  id: number;
  name: string;
  description: string | null;
  type: string;
  criticality: number;
  confidentiality: number;
  integrity: number;
  availability: number;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName"> | null;
  scope: { id: number; name: string };
  relatedAssets: Array<{ id: number; name: string }>;
  status: string;
}
export interface Threat {
  id: number;
  name: string;
  description: string | null;
  category: string;
  source: string | null;
  status: string;
}
export interface Vulnerability {
  id: number;
  name: string;
  description: string | null;
  category: string;
  severity: string;
  affectedAssets: Array<{ id: number; name: string }>;
  status: string;
}

export interface IsmsDocumentVersion {
  id: number;
  versionNumber: number;
  comment: string | null;
  fileName: string | null;
  fileChecksum: string | null;
  author: Pick<User, "id" | "email" | "firstName" | "lastName">;
  createdAt: string;
}

export interface IsmsDocumentAcl {
  id: number;
  permission: "READ" | "EDIT" | "MANAGE";
  user: Pick<User, "id" | "email" | "firstName" | "lastName">;
}

export interface IsmsDocumentShare {
  id: number;
  enabled: boolean;
  available: boolean;
  expired: boolean;
  hasPassword: boolean;
  expiresAt: string | null;
  accessCount: number;
  createdAt: string;
}

export interface IsmsDocument {
  id: number;
  title: string;
  category: string;
  status: "DRAFT" | "IN_REVIEW" | "APPROVED" | "ARCHIVED";
  classification: "PUBLIC" | "INTERNAL" | "CONFIDENTIAL" | "RESTRICTED";
  visibility: "ORGANIZATION" | "RESTRICTED";
  content?: string;
  excerpt: string;
  owner: Pick<User, "id" | "email" | "firstName" | "lastName">;
  approval: {
    approvedBy: Pick<User, "id" | "email" | "firstName" | "lastName"> | null;
    approvedAt: string | null;
    nextReviewAt: string | null;
    reviewOverdue: boolean;
  };
  currentVersion: number;
  file: {
    name: string;
    mimeType: string;
    size: number;
    checksum: string;
  } | null;
  createdAt: string;
  updatedAt: string;
  permissions: { read: boolean; edit: boolean; manage: boolean };
  versions?: IsmsDocumentVersion[];
  acl?: IsmsDocumentAcl[];
  shares?: IsmsDocumentShare[];
}
