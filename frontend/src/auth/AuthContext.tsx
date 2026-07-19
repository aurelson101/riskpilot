import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type PropsWithChildren,
} from "react";
import { api, TOKEN_STORAGE_KEY } from "../api/client";
import type { User } from "../api/types";
import { AuthContext } from "./auth-context";

export function AuthProvider({ children }: PropsWithChildren) {
  const queryClient = useQueryClient();
  const [token, setToken] = useState(() =>
    sessionStorage.getItem(TOKEN_STORAGE_KEY),
  );
  const profile = useQuery({
    queryKey: ["me"],
    queryFn: async () => (await api.get<User>("/me")).data,
    enabled: Boolean(token),
    retry: false,
  });
  const logout = useCallback(() => {
    void api.post("/auth/logout").catch(() => undefined);
    sessionStorage.removeItem(TOKEN_STORAGE_KEY);
    setToken(null);
    queryClient.clear();
  }, [queryClient]);
  useEffect(() => {
    if (token && profile.isError) logout();
  }, [token, profile.isError, logout]);
  const login = useCallback(
    async (email: string, password: string, mfaCode?: string) => {
      const { data, status } = await api.post<{
        token?: string;
        mfaRequired?: boolean;
      }>("/auth/login", {
        email,
        password,
        mfaCode,
      });
      if (status === 202 || data.mfaRequired) return true;
      if (!data.token) throw new Error("Jeton de connexion manquant");
      sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
      setToken(data.token);
      await queryClient.invalidateQueries({ queryKey: ["me"] });
      return false;
    },
    [queryClient],
  );
  const value = useMemo(
    () => ({ token, user: profile.data, login, logout }),
    [token, profile.data, login, logout],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
