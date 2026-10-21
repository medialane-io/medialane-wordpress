import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn(),
  uploadFeaturedImage: vi.fn(),
  createMintIntent: vi.fn(),
  provisionRecipientWallet: vi.fn(),
  buildSponsoredInvoke: vi.fn(),
  executeSponsoredInvoke: vi.fn(),
  markMinting: vi.fn(),
  markBroadcast: vi.fn(),
  markMinted: vi.fn(),
  markError: vi.fn(),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn(),
  waitForConfirmation: vi.fn(),
  mintedTokenIdsFromReceipt: vi.fn(),
}));
vi.mock("../../assets/src/session.js", () => ({
  ensureSession: vi.fn(),
  forgetSession: vi.fn(),
}));

const api = await import("../../assets/src/api.js");
const wallet = await import("../../assets/src/wallet.js");
const session = await import("../../assets/src/session.js");
const { prepareMint, executeMintBatch, resumeMint } = await import("../../assets/src/mint-flow.js");

class FakeApiError extends Error {
  constructor(message, code) {
    super(message);
    this.code = code;
  }
}

beforeEach(() => {
  vi.resetAllMocks();
  api.provisionRecipientWallet.mockResolvedValue({ data: { chain: "STARKNET", walletAddress: "0xauthorwallet" } });
  api.uploadFeaturedImage.mockResolvedValue({ data: { url: "ipfs://img" } });
  api.uploadJson.mockResolvedValue({ data: { url: "ipfs://meta" } });
  api.createMintIntent.mockResolvedValue({ data: { id: "intent-1", requiresSignature: false, calls: [] } });
  api.buildSponsoredInvoke.mockResolvedValue({ typedData: { message: { Calls: [] } } });
  api.executeSponsoredInvoke.mockResolvedValue({ transactionHash: "0xtx" });
  api.markMinting.mockResolvedValue({});
  api.markBroadcast.mockResolvedValue({});
  api.markMinted.mockResolvedValue({});
  api.markError.mockResolvedValue({});
  wallet.signTypedData.mockResolvedValue(["0x1", "0x2"]);
  wallet.waitForConfirmation.mockResolvedValue({ isReverted: () => false });
  wallet.mintedTokenIdsFromReceipt.mockReturnValue(["101", "102"]);
  session.ensureSession.mockResolvedValue(undefined);
});

const base = {
  postId: 42, title: "A Post", body: "Body", hasImage: false, license: "CC BY-SA",
  address: "0xowner", collectionId: "7", authorEmail: "author@example.com",
};

describe("prepareMint", () => {
  it("provisions the author's wallet by email only and mints to it with the numeric collection id", async () => {
    await prepareMint(base);

    expect(api.provisionRecipientWallet).toHaveBeenCalledWith({ email: "author@example.com" });
    expect(api.createMintIntent).toHaveBeenCalledWith({
      owner: "0xowner", recipient: "0xauthorwallet", collectionId: "7", tokenUri: "ipfs://meta", royaltyBps: 0,
    });
  });

  it("returns the post, its license and the intent to sponsor", async () => {
    await expect(prepareMint(base)).resolves.toEqual({ postId: 42, license: "CC BY-SA", intentId: "intent-1" });
  });

  it("uploads the license as structured attributes, never as a flat field", async () => {
    await prepareMint({ ...base, aiPolicy: "Training Only" });

    const payload = api.uploadJson.mock.calls[0][0];
    expect(payload.license).toBeUndefined();
    expect(payload.attributes).toEqual(expect.arrayContaining([
      { trait_type: "License", value: "CC BY-SA" },
      { trait_type: "AI Policy", value: "Training Only" },
    ]));
  });

  it("pins the featured image and references it by its permanent address", async () => {
    await prepareMint({ ...base, hasImage: true });

    expect(api.uploadFeaturedImage).toHaveBeenCalledWith(42);
    expect(api.uploadJson).toHaveBeenCalledWith(expect.objectContaining({ image: "ipfs://img", name: "A Post", description: "Body" }));
  });

  it("leaves the image out when the post has none", async () => {
    await prepareMint(base);
    expect(api.uploadFeaturedImage).not.toHaveBeenCalled();
    expect(api.uploadJson.mock.calls[0][0].image).toBeUndefined();

    api.uploadJson.mockClear();
    api.uploadFeaturedImage.mockResolvedValue({ data: { url: null } });
    await prepareMint({ ...base, hasImage: true });
    expect(api.uploadJson.mock.calls[0][0].image).toBeUndefined();
  });

  it("refuses to mint when the post's author has no registered email, before any request", async () => {
    await expect(prepareMint({ ...base, authorEmail: "" })).rejects.toThrow("no registered email");
    expect(api.provisionRecipientWallet).not.toHaveBeenCalled();
  });

  it("uploads and mints nothing when the author's wallet cannot be set up", async () => {
    api.provisionRecipientWallet.mockRejectedValue(new Error("deploy_failed"));

    await expect(prepareMint(base)).rejects.toThrow("deploy_failed");
    expect(api.uploadJson).not.toHaveBeenCalled();
    expect(api.createMintIntent).not.toHaveBeenCalled();
  });
});

describe("executeMintBatch", () => {
  const entries = [
    { postId: 1, license: "CC BY-SA", intentId: "i1" },
    { postId: 2, license: "CC BY-SA", intentId: "i2" },
  ];
  const run = (overrides = {}) => executeMintBatch({ account: {}, address: "0xowner", collectionContract: "0xcol", entries, ...overrides });

  it("throws without a collection, before marking anything", async () => {
    await expect(run({ collectionContract: "" })).rejects.toThrow("No collection configured");
    expect(api.markMinting).not.toHaveBeenCalled();
  });

  it("signs in, sponsors every intent in one transaction, then marks each post minted with its own token id", async () => {
    const results = await run();

    expect(session.ensureSession).toHaveBeenCalledWith({}, "0xowner");
    expect(api.buildSponsoredInvoke).toHaveBeenCalledWith({ intentIds: ["i1", "i2"] });
    expect(wallet.signTypedData).toHaveBeenCalledWith({}, { message: { Calls: [] } });
    expect(api.executeSponsoredInvoke).toHaveBeenCalledWith({ intentIds: ["i1", "i2"], typedData: { message: { Calls: [] } }, signature: ["0x1", "0x2"] });
    expect(api.markMinted).toHaveBeenCalledWith(1, { tokenId: "101", txHash: "0xtx", contract: "0xcol", license: "CC BY-SA" });
    expect(api.markMinted).toHaveBeenCalledWith(2, { tokenId: "102", txHash: "0xtx", contract: "0xcol", license: "CC BY-SA" });
    expect(results).toEqual([{ postId: 1, txHash: "0xtx" }, { postId: 2, txHash: "0xtx" }]);
  });

  it("records the transaction for every post before waiting for it to confirm", async () => {
    const order = [];
    api.markBroadcast.mockImplementation(async (postId) => { order.push(`broadcast:${postId}`); });
    wallet.waitForConfirmation.mockImplementation(async () => { order.push("confirm"); return { isReverted: () => false }; });

    await run();

    expect(order).toEqual(["broadcast:1", "broadcast:2", "confirm"]);
    expect(api.markBroadcast).toHaveBeenCalledWith(1, { txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 0 });
    expect(api.markBroadcast).toHaveBeenCalledWith(2, { txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 1 });
  });

  it("marks every post errored, not minted, when the transaction reverts", async () => {
    wallet.waitForConfirmation.mockRejectedValue(new Error("execution failed"));

    const results = await run();

    expect(results).toEqual([{ postId: 1, error: "execution failed" }, { postId: 2, error: "execution failed" }]);
    expect(api.markError).toHaveBeenCalledWith(1, "execution failed");
    expect(api.markMinted).not.toHaveBeenCalled();
  });

  it("marks every post errored with the reason when sponsorship is refused, and never executes", async () => {
    api.buildSponsoredInvoke.mockRejectedValue(new FakeApiError("Gas sponsorship is temporarily unavailable", "sponsor_unavailable"));

    const results = await run();

    expect(results).toEqual([
      { postId: 1, error: "Gas sponsorship is temporarily unavailable" },
      { postId: 2, error: "Gas sponsorship is temporarily unavailable" },
    ]);
    expect(api.executeSponsoredInvoke).not.toHaveBeenCalled();
    expect(api.markBroadcast).not.toHaveBeenCalled();
  });

  it("keeps the backend's warning when the transaction may already have been submitted", async () => {
    api.executeSponsoredInvoke.mockRejectedValue(new FakeApiError("It may have been submitted. Check your activity before trying again.", "may_have_broadcast"));

    const results = await run();

    expect(results[0]).toEqual({ postId: 1, error: "It may have been submitted. Check your activity before trying again." });
    expect(api.markError).toHaveBeenCalledWith(1, "It may have been submitted. Check your activity before trying again.");
    expect(wallet.waitForConfirmation).not.toHaveBeenCalled();
  });

  it("signs in again once when the session was rejected, then carries on", async () => {
    api.buildSponsoredInvoke.mockRejectedValueOnce(new FakeApiError("Sign in to use gas sponsorship", "invalid_request_auth"));

    const results = await run();

    expect(session.forgetSession).toHaveBeenCalledTimes(1);
    expect(session.ensureSession).toHaveBeenCalledTimes(2);
    expect(results).toEqual([{ postId: 1, txHash: "0xtx" }, { postId: 2, txHash: "0xtx" }]);
  });

  it("does not retry the sign-in more than once", async () => {
    api.buildSponsoredInvoke.mockRejectedValue(new FakeApiError("Sign in to use gas sponsorship", "invalid_request_auth"));

    const results = await run();

    expect(session.ensureSession).toHaveBeenCalledTimes(2);
    expect(results[0].error).toBe("Sign in to use gas sponsorship");
  });

  it("still confirms and marks the posts minted when recording the broadcast fails", async () => {
    api.markBroadcast.mockRejectedValue(new Error("network down"));

    const results = await run();

    expect(api.markMinted).toHaveBeenCalledTimes(2);
    expect(results).toEqual([{ postId: 1, txHash: "0xtx" }, { postId: 2, txHash: "0xtx" }]);
  });

  it("does not flip a confirmed mint to errored when saving the result fails", async () => {
    api.markMinted.mockRejectedValueOnce(new Error("network down"));

    const results = await run();

    expect(api.markError).not.toHaveBeenCalled();
    expect(results[0]).toEqual({ postId: 1, txHash: "0xtx", error: "Minted on chain, but this site could not save it. Use Check status on the post." });
    expect(results[1]).toEqual({ postId: 2, txHash: "0xtx" });
  });
});

describe("resumeMint", () => {
  const job = { postId: 2, txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 1 };

  it("resumes a recorded transaction and marks the post minted with its own token id", async () => {
    await expect(resumeMint(job)).resolves.toEqual({ postId: 2, txHash: "0xtx" });
    expect(api.markMinted).toHaveBeenCalledWith(2, { tokenId: "102", txHash: "0xtx", contract: "0xcol", license: "CC BY-SA" });
  });

  it("marks the post errored when the recorded transaction failed", async () => {
    wallet.waitForConfirmation.mockRejectedValue(new Error("Transaction reverted"));

    await expect(resumeMint(job)).resolves.toEqual({ postId: 2, error: "Transaction reverted" });
    expect(api.markError).toHaveBeenCalledWith(2, "Transaction reverted");
    expect(api.markMinted).not.toHaveBeenCalled();
  });

  it("does not mark a confirmed mint errored when saving it fails", async () => {
    api.markMinted.mockRejectedValue(new Error("network down"));

    await expect(resumeMint(job)).resolves.toEqual({ postId: 2, txHash: "0xtx", error: "Minted on chain, but this site could not save it. Use Check status on the post." });
    expect(api.markError).not.toHaveBeenCalled();
  });
});
