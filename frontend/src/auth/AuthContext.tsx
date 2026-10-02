import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type PropsWithChildren,
} from "react";
import { api, endSession, TOKEN_STORAGE_KEY } from "../api/client";
import axios from "axios";
import type { User } from "../api/types";
import { AuthContext } from "./auth-context";

export function AuthProvider({ children }: PropsWithChildren) {
  const queryClient = useQueryClient();
  const [token, setToken] = useState(() =>
    sessionStorage.getItem(TOKEN_STORAGE_KEY),
  );
  const logout = useCallback(() => {
    void api.post("/auth/logout").catch(() => undefined);
    endSession();
    setToken(null);
    queryClient.clear();
  }, [queryClient]);
  const profile = useQuery({
    queryKey: ["me"],
    queryFn: async ({ signal }) => {
      try {
        return (await api.get<User>("/me", { signal })).data;
      } catch (error) {
        if (
          sessionStorage.getItem(TOKEN_STORAGE_KEY) === token &&
          axios.isAxiosError(error) &&
          error.response?.status === 401
        )
          logout();
        throw error;
      }
    },
    enabled: Boolean(token),
    retry: false,
  });
  const { refetch } = profile;
  const retryProfile = useCallback(() => {
    void refetch();
  }, [refetch]);
  useEffect(() => {
    window.addEventListener("riskpilot:session-expired", logout);
    return () =>
      window.removeEventListener("riskpilot:session-expired", logout);
  }, [logout]);
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
      endSession();
      queryClient.clear();
      sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
      setToken(data.token);
      await queryClient.invalidateQueries({ queryKey: ["me"] });
      return false;
    },
    [queryClient],
  );
  const value = useMemo(
    () => ({
      token,
      user: profile.data,
      login,
      logout,
      profileUnavailable: profile.isError,
      retryProfile,
    }),
    [token, profile.data, profile.isError, retryProfile, login, logout],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
