import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/api.js", () => ({
  siwsNonce: vi.fn(),
  siwsVerify: vi.fn(),
  setSession: vi.fn(),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn(),
}));

const api = await import("../../assets/src/api.js");
const wallet = await import("../../assets/src/wallet.js");
const { ensureSession, forgetSession } = await import("../../assets/src/session.js");

describe("ensureSession", () => {
  const account = { signMessage: vi.fn() };

  beforeEach(() => {
    vi.clearAllMocks();
    forgetSession();
    api.setSession.mockClear();
    api.siwsNonce.mockResolvedValue({ nonce: "n1", typedData: { message: { nonce: "n1" } } });
    wallet.signTypedData.mockResolvedValue(["0xs1", "0xs2"]);
    api.siwsVerify.mockResolvedValue({ token: "tok-1" });
  });

  it("signs the server's challenge with the wallet and keeps the token it gets back", async () => {
    await ensureSession(account, "0xabc");

    expect(api.siwsNonce).toHaveBeenCalledWith("0xabc");
    expect(wallet.signTypedData).toHaveBeenCalledWith(account, { message: { nonce: "n1" } });
    expect(api.siwsVerify).toHaveBeenCalledWith({ walletAddress: "0xabc", nonce: "n1", signature: ["0xs1", "0xs2"] });
    expect(api.setSession).toHaveBeenLastCalledWith("tok-1");
  });

  it("does not ask the wallet to sign again for the same address", async () => {
    await ensureSession(account, "0xabc");
    await ensureSession(account, "0x0ABC");

    expect(api.siwsNonce).toHaveBeenCalledTimes(1);
  });

  it("signs in again for a different address", async () => {
    await ensureSession(account, "0xabc");
    await ensureSession(account, "0xdef");

    expect(api.siwsNonce).toHaveBeenCalledTimes(2);
  });

  it("signs in again after the session was forgotten", async () => {
    await ensureSession(account, "0xabc");
    forgetSession();
    await ensureSession(account, "0xabc");

    expect(api.siwsNonce).toHaveBeenCalledTimes(2);
    expect(api.setSession).toHaveBeenCalledWith("");
  });

  it("does not keep a session when signing fails", async () => {
    wallet.signTypedData.mockRejectedValueOnce(new Error("User rejected"));

    await expect(ensureSession(account, "0xabc")).rejects.toThrow("User rejected");
    await ensureSession(account, "0xabc");

    expect(api.siwsNonce).toHaveBeenCalledTimes(2);
  });
});
