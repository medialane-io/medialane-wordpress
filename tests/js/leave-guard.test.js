import { describe, it, expect } from "vitest";
import { guardLeaving } from "../../assets/src/leave-guard.js";

function leave() {
  const event = new Event("beforeunload", { cancelable: true });
  window.dispatchEvent(event);
  return event.defaultPrevented;
}

describe("guardLeaving", () => {
  it("asks the browser to confirm before the tab is closed", () => {
    const release = guardLeaving();
    expect(leave()).toBe(true);
    release();
  });

  it("lets the tab go once released", () => {
    guardLeaving()();
    expect(leave()).toBe(false);
  });

  it("releases only its own guard", () => {
    const first = guardLeaving();
    const second = guardLeaving();
    second();
    expect(leave()).toBe(true);
    first();
    expect(leave()).toBe(false);
  });
});
