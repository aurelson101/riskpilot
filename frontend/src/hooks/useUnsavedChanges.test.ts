import { renderHook, cleanup } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { useUnsavedChanges } from "./useUnsavedChanges";

afterEach(cleanup);
it("warns only for dirty drafts and removes the listener after submission", () => {
  const { rerender, unmount } = renderHook(
    ({ dirty }) => useUnsavedChanges(dirty),
    { initialProps: { dirty: false } },
  );
  const attempt = () => {
    const event = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
  };
  expect(attempt()).toBe(false);
  rerender({ dirty: true });
  expect(attempt()).toBe(true);
  rerender({ dirty: false });
  expect(attempt()).toBe(false);
  unmount();
  expect(attempt()).toBe(false);
});
