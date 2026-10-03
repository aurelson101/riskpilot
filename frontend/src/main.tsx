import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.tsx";
import { AuthProvider } from "./auth/AuthContext.tsx";
import "./index.css";
import axios from "axios";
import { AccessibleFormTheme } from "./components/AccessibleFormTheme";

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) =>
        failureCount < 2 &&
        !axios.isCancel(error) &&
        (!axios.isAxiosError(error) ||
          !error.response ||
          error.response.status >= 500),
    },
    mutations: { retry: false },
  },
});
createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <AccessibleFormTheme>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
    </AccessibleFormTheme>
  </StrictMode>,
);
