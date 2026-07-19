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
export interface User {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  roles: string[];
  status: string;
  organization: Organization;
  lastLoginAt: string | null;
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
