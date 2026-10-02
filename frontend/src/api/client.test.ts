import {
  AxiosError,
  AxiosHeaders,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from "axios";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api, endSession, TOKEN_STORAGE_KEY } from "./client";

const originalAdapter = api.defaults.adapter;
afterEach(() => {
  api.defaults.adapter = originalAdapter;
  endSession();
  vi.restoreAllMocks();
});
function response(
  config: InternalAxiosRequestConfig,
  data: unknown,
  status = 200,
): AxiosResponse {
  return {
    config,
    data,
    status,
    statusText: String(status),
    headers: new AxiosHeaders(),
  };
}
function unauthorized(config: InternalAxiosRequestConfig): never {
  throw new AxiosError(
    "Unauthorized",
    "ERR_BAD_REQUEST",
    config,
    undefined,
    response(config, {}, 401),
  );
}

describe("API session safety", () => {
  it.each([
    "https://example.test/api",
    "//example.test/api",
    "/\\example.test",
    "/risks\n",
    "javascript:alert(1)",
  ])("rejects unsafe endpoint %s", async (url) => {
    const adapter = vi.fn();
    api.defaults.adapter = adapter;
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    await expect(api.get(url)).rejects.toThrow("Destination API");
    expect(adapter).not.toHaveBeenCalled();
  });
  it("does not attach authorization to public authentication requests", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    api.defaults.adapter = async (config) => {
      expect(config.headers.Authorization).toBeUndefined();
      return response(config, {});
    };
    await api.post(
      "/auth/login",
      {},
      { headers: { Authorization: "Bearer unwanted" } },
    );
  });
  it("shares one refresh across simultaneous unauthorized requests", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    let release = () => {};
    const barrier = new Promise<void>((resolve) => {
      release = resolve;
    });
    let refreshCount = 0;
    api.defaults.adapter = async (config) => {
      if (config.url === "/auth/refresh") {
        refreshCount++;
        await barrier;
        return response(config, { token: "new.token.value" });
      }
      if (config.headers.Authorization === "Bearer old.token.value")
        unauthorized(config);
      return response(config, { ok: true });
    };
    const first = api.get("/risks");
    const second = api.get("/actions");
    await vi.waitFor(() => expect(refreshCount).toBe(1));
    release();
    await expect(Promise.all([first, second])).resolves.toHaveLength(2);
    expect(refreshCount).toBe(1);
    expect(sessionStorage.getItem(TOKEN_STORAGE_KEY)).toBe("new.token.value");
  });
  it("never restores a session ended during refresh", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    let release = () => {};
    let refreshing = false;
    const barrier = new Promise<void>((resolve) => {
      release = resolve;
    });
    api.defaults.adapter = async (config) => {
      if (config.url === "/auth/refresh") {
        refreshing = true;
        await barrier;
        return response(config, { token: "new.token.value" });
      }
      unauthorized(config);
    };
    const pending = api.get("/risks").catch((error) => error);
    await vi.waitFor(() => expect(refreshing).toBe(true));
    endSession();
    release();
    await pending;
    expect(sessionStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });
  it("does not rotate again for a late unauthorized response from an old token", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    let late = () => {};
    let refreshCount = 0;
    const barrier = new Promise<void>((resolve) => {
      late = resolve;
    });
    api.defaults.adapter = async (config) => {
      if (config.url === "/auth/refresh") {
        refreshCount++;
        return response(config, { token: "new.token.value" });
      }
      if (config.headers.Authorization === "Bearer old.token.value") {
        if (config.url === "/actions") await barrier;
        unauthorized(config);
      }
      return response(config, {});
    };
    const first = api.get("/risks");
    const second = api.get("/actions");
    await first;
    late();
    await second;
    expect(refreshCount).toBe(1);
  });
  it("rejects malformed refresh payloads and clears the failed session", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
    api.defaults.adapter = async (config) => {
      if (config.url === "/auth/refresh") return response(config, { token: 7 });
      unauthorized(config);
    };
    await expect(api.get("/risks")).rejects.toThrow();
    expect(sessionStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });
});
