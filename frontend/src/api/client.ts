import axios from "axios";

export const TOKEN_STORAGE_KEY = "riskpilot.accessToken";
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "/api",
  headers: { "Content-Type": "application/json" },
  withCredentials: true,
});

api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem(TOKEN_STORAGE_KEY);
  const publicAuthRequest = [
    "/auth/login",
    "/auth/refresh",
    "/auth/logout",
    "/auth/forgot-password",
    "/auth/reset-password",
  ].some((path) => String(config.url ?? "").startsWith(path));
  if (token && !publicAuthRequest)
    config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const request = error.config as
      (typeof error.config & { _refreshAttempted?: boolean }) | undefined;
    const url = String(request?.url ?? "");
    if (
      error.response?.status === 401 &&
      request &&
      !request._refreshAttempted &&
      !url.startsWith("/auth/")
    ) {
      request._refreshAttempted = true;
      try {
        const { data } = await api.post<{ token: string }>("/auth/refresh");
        sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
        request.headers.Authorization = `Bearer ${data.token}`;
        return api(request);
      } catch {
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
      }
    }
    return Promise.reject(error);
  },
);
