import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/api.js", () => ({ getAccount: vi.fn() }));

const api = await import("../../assets/src/api.js");
const { assertCredits } = await import("../../assets/src/credits.js");

class StatusError extends Error {
  constructor(status) {
    super(`status ${status}`);
    this.status = status;
  }
}

beforeEach(() => {
  vi.resetAllMocks();
});

describe("assertCredits", () => {
  it("lets the run start when the account has credits", async () => {
    api.getAccount.mockResolvedValue({ data: { creditBalance: 12 } });
    await expect(assertCredits()).resolves.toBeUndefined();
  });

  it("reads a balance sent as text", async () => {
    api.getAccount.mockResolvedValue({ data: { creditBalance: "3" } });
    await expect(assertCredits()).resolves.toBeUndefined();
  });

  it("stops before anything is uploaded when the balance is zero", async () => {
    api.getAccount.mockResolvedValue({ data: { creditBalance: 0 } });
    await expect(assertCredits()).rejects.toThrow("No credits left. Add credits at portal.medialane.io/account.");
  });

  it("stops when the backend says there are no credits", async () => {
    api.getAccount.mockRejectedValue(new StatusError(402));
    await expect(assertCredits()).rejects.toThrow("No credits left");
  });

  it("stops when the API key is not accepted", async () => {
    api.getAccount.mockRejectedValue(new StatusError(401));
    await expect(assertCredits()).rejects.toThrow("The API key was not accepted. Check it at portal.medialane.io/account.");
  });

  it("does not block on a failure it cannot interpret", async () => {
    api.getAccount.mockRejectedValue(new StatusError(503));
    await expect(assertCredits()).resolves.toBeUndefined();
  });

  it("does not block when the balance is not in the answer", async () => {
    api.getAccount.mockResolvedValue({ data: {} });
    await expect(assertCredits()).resolves.toBeUndefined();
  });
});
