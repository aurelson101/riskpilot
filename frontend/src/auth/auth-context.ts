import { createContext } from "react";
import type { User } from "../api/types";

export interface AuthContextValue {
  token: string | null;
  user: User | undefined;
  login: (
    email: string,
    password: string,
    mfaCode?: string,
  ) => Promise<boolean>;
  logout: () => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);
