export interface Organization {
  id: number;
  name: string;
  description: string | null;
  status: string;
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
