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

const SSO_LOGOUT_KEY = "riskpilot.sso.logoutUrl";

export function AuthProvider({ children }: PropsWithChildren) {
  const queryClient = useQueryClient();
  const [token, setToken] = useState(() =>
    sessionStorage.getItem(TOKEN_STORAGE_KEY),
  );
  const endLocally = useCallback(() => {
    const done = api.post("/auth/logout").catch(() => undefined);
    sessionStorage.removeItem(SSO_LOGOUT_KEY);
    endSession();
    setToken(null);
    queryClient.clear();
    return done;
  }, [queryClient]);
  // Déconnexion volontaire : ferme aussi la session chez le fournisseur SSO lorsqu'il l'a permis.
  const logout = useCallback(() => {
    const providerUrl = sessionStorage.getItem(SSO_LOGOUT_KEY);
    void endLocally().then(() => {
      if (providerUrl?.startsWith("https://"))
        window.location.assign(providerUrl);
    });
  }, [endLocally]);
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
          void endLocally();
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
    const expire = () => void endLocally();
    window.addEventListener("riskpilot:session-expired", expire);
    return () =>
      window.removeEventListener("riskpilot:session-expired", expire);
  }, [endLocally]);
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
      loginSso: async (mfaCode?: string) => {
        const { data, status } = await api.post<{
          token?: string;
          mfaRequired?: boolean;
          sso?: { logoutUrl?: string };
        }>("/auth/sso/complete", { mfaCode });
        if (status === 202 || data.mfaRequired) return true;
        if (!data.token) throw new Error("Jeton de connexion manquant");
        endSession();
        queryClient.clear();
        sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
        if (data.sso?.logoutUrl)
          sessionStorage.setItem(SSO_LOGOUT_KEY, data.sso.logoutUrl);
        setToken(data.token);
        await queryClient.invalidateQueries({ queryKey: ["me"] });
        return false;
      },
      logout,
      profileUnavailable: profile.isError,
      retryProfile,
    }),
    [
      token,
      profile.data,
      profile.isError,
      retryProfile,
      login,
      logout,
      queryClient,
    ],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
