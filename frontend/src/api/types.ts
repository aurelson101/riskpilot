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
