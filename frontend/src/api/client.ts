import axios from "axios";

export const TOKEN_STORAGE_KEY = "riskpilot.accessToken";
let sessionRevision = 0;
let refresh: { revision: number; promise: Promise<string> } | null = null;

export function endSession() {
  sessionRevision += 1;
  sessionStorage.removeItem(TOKEN_STORAGE_KEY);
}
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "/api",
  headers: { "Content-Type": "application/json" },
  withCredentials: true,
  timeout: 30_000,
});

api.interceptors.request.use((config) => {
  const url = String(config.url ?? "");
  if (
    !url.startsWith("/") ||
    url.startsWith("//") ||
    url.includes("\\") ||
    Array.from(url).some((character) => character.charCodeAt(0) < 32)
  ) {
    throw new Error("Destination API non autorisée");
  }
  config.baseURL = api.defaults.baseURL;
  (config as typeof config & { _sessionRevision?: number })._sessionRevision =
    sessionRevision;
  const token = sessionStorage.getItem(TOKEN_STORAGE_KEY);
  const publicAuthRequest = [
    "/auth/login",
    "/auth/refresh",
    "/auth/logout",
    "/auth/forgot-password",
    "/auth/reset-password",
  ].includes(url.split("?")[0]);
  config.headers.delete("Authorization");
  if (token && !publicAuthRequest)
    config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const request = error.config as
      | (typeof error.config & {
          _refreshAttempted?: boolean;
          _sessionRevision?: number;
        })
      | undefined;
    const url = String(request?.url ?? "");
    if (
      error.response?.status === 401 &&
      request &&
      !request._refreshAttempted &&
      !url.startsWith("/auth/") &&
      !request.signal?.aborted &&
      request._sessionRevision === sessionRevision &&
      sessionStorage.getItem(TOKEN_STORAGE_KEY)
    ) {
      request._refreshAttempted = true;
      const revision = sessionRevision;
      const token = sessionStorage.getItem(TOKEN_STORAGE_KEY);
      try {
        if (request.headers.Authorization !== `Bearer ${token}`)
          return api(request);
        if (!refresh || refresh.revision !== revision) {
          const promise = api
            .post<{ token: string }>("/auth/refresh")
            .then(({ data }) => {
              if (
                typeof data.token !== "string" ||
                !data.token ||
                data.token.length > 16_384
              )
                throw new Error("Jeton de session invalide");
              if (
                sessionRevision !== revision ||
                sessionStorage.getItem(TOKEN_STORAGE_KEY) !== token
              )
                throw new Error("Session modifiée");
              sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
              return data.token;
            })
            .finally(() => {
              if (refresh?.revision === revision) refresh = null;
            });
          refresh = { revision, promise };
        }
        const nextToken = await refresh.promise;
        if (sessionRevision !== revision || request.signal?.aborted)
          throw new Error("Session ou requête annulée");
        request.headers.Authorization = `Bearer ${nextToken}`;
        return api(request);
      } catch {
        if (
          sessionRevision === revision &&
          sessionStorage.getItem(TOKEN_STORAGE_KEY) === token
        ) {
          endSession();
          window.dispatchEvent(new Event("riskpilot:session-expired"));
        }
      }
    }
    return Promise.reject(error);
  },
);
