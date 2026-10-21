import { describe, it, expect, vi } from "vitest";
import { prepareMint, executeMintBatch, resumeMint } from "../../assets/src/mint-flow.js";

vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
  buildSponsoredDeploy: vi.fn().mockResolvedValue({ data: { typedData: {}, deployment: {}, calls: [] } }),
  provisionRecipientWallet: vi.fn().mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } }),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  generateInterimKeypair: vi.fn().mockReturnValue({ privateKey: "0xpriv", publicKey: "0xpub", address: "0xinterim" }),
  signDeploymentWithInterimKey: vi.fn().mockResolvedValue(["0xdsig"]),
  mintedTokenIdsFromReceipt: vi.fn().mockReturnValue(["101", "102"]),
}));

describe("prepareMint", () => {
  it("provisions the author's wallet by email and mints to it, not to the caller's own address", async () => {
    const { createMintIntent, provisionRecipientWallet } = await import("../../assets/src/api.js");

    const result = await prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA",
      address: "0xowner", collectionContract: "0xcol", authorEmail: "author@example.com",
    });

    expect(provisionRecipientWallet).toHaveBeenCalledWith(expect.objectContaining({
      recipientScheme: "email", recipientValue: "author@example.com",
    }));
    expect(createMintIntent).toHaveBeenCalledWith(expect.objectContaining({
      owner: "0xowner", recipient: "0xauthorwallet",
    }));
    expect(result.postId).toBe(42);
  });

  it("uploads the license as structured attributes, not a flat string, and never as its own top-level field", async () => {
    const { uploadJson } = await import("../../assets/src/api.js");
    uploadJson.mockClear();

    await prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA", aiPolicy: "Training Only",
      address: "0xowner", collectionContract: "0xcol", authorEmail: "author@example.com",
    });

    const payload = uploadJson.mock.calls[0][0];
    expect(payload.license).toBeUndefined();
    expect(payload.attributes).toEqual(expect.arrayContaining([
      { trait_type: "License", value: "CC BY-SA" },
      { trait_type: "AI Policy", value: "Training Only" },
    ]));
  });

  it("refuses to mint when the post's author has no registered email", async () => {
    await expect(prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA",
      address: "0xowner", collectionContract: "0xcol", authorEmail: "",
    })).rejects.toThrow("no registered email");
  });
});

describe("executeMintBatch", () => {
  it("throws without a collection, before marking anything", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    await expect(
      executeMintBatch({ restUrl: "/wp-json/tokenize-content/v1", nonce: "abc", account: {}, address: "0xowner", collectionContract: "", entries: [] })
    ).rejects.toThrow("No collection configured");
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it("signs and executes once for the whole batch, then marks every post minted with the shared tx hash", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    const entries = [
      { postId: 1, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
      { postId: 2, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
    ];
    const results = await executeMintBatch({
      restUrl: "/wp-json/tokenize-content/v1", nonce: "abc", account: {}, address: "0xowner",
      collectionContract: "0xcol", entries,
    });
    expect(results).toEqual([
      { postId: 1, txHash: "0xtx" },
      { postId: 2, txHash: "0xtx" },
    ]);

    const mintedCalls = global.fetch.mock.calls.filter(([url]) => url.endsWith("/minted"));
    const bodies = mintedCalls.map(([, opts]) => JSON.parse(opts.body));
    expect(bodies).toEqual([
      expect.objectContaining({ tokenId: "101" }),
      expect.objectContaining({ tokenId: "102" }),
    ]);
  });

  it("marks every post errored, not minted, when the transaction reverts on chain", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    const { waitForConfirmation } = await import("../../assets/src/wallet.js");
    waitForConfirmation.mockRejectedValueOnce(new Error("execution failed"));

    const entries = [{ postId: 1, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] }];
    const results = await executeMintBatch({
      restUrl: "/wp-json/tokenize-content/v1", nonce: "abc", account: {}, address: "0xowner",
      collectionContract: "0xcol", entries,
    });

    expect(results).toEqual([{ postId: 1, error: "execution failed" }]);
    const paths = global.fetch.mock.calls.map((call) => call[0]);
    expect(paths).not.toContain("/wp-json/tokenize-content/v1/posts/1/minted");
    expect(paths).toContain("/wp-json/tokenize-content/v1/posts/1/error");
  });
});

describe("broadcast reference and resume", () => {
  const base = { restUrl: "/wp-json/tokenize-content/v1", nonce: "abc" };

  it("records the transaction reference for every post before waiting for confirmation", async () => {
    const order = [];
    global.fetch = vi.fn().mockImplementation(async (url, opts) => {
      if (url.endsWith("/broadcast")) order.push({ url, body: JSON.parse(opts.body) });
      return { ok: true, json: async () => ({}) };
    });
    const { waitForConfirmation } = await import("../../assets/src/wallet.js");
    waitForConfirmation.mockImplementationOnce(async () => {
      order.push("confirm");
      return { isReverted: () => false };
    });
    const call = { contractAddress: "0xc", entrypoint: "mint", calldata: [] };

    await executeMintBatch({
      ...base, account: {}, address: "0xowner", collectionContract: "0xcol",
      entries: [{ postId: 1, license: "CC BY-SA", calls: [call] }, { postId: 2, license: "CC BY-SA", calls: [call] }],
    });

    expect(order).toEqual([
      { url: "/wp-json/tokenize-content/v1/posts/1/broadcast", body: { txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 0 } },
      { url: "/wp-json/tokenize-content/v1/posts/2/broadcast", body: { txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 1 } },
      "confirm",
    ]);
  });

  it("resumes a recorded transaction and marks the post minted with its own token id", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });

    const result = await resumeMint({ ...base, postId: 2, txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 1 });

    expect(result).toEqual({ postId: 2, txHash: "0xtx" });
    const [, opts] = global.fetch.mock.calls.find(([url]) => url.endsWith("/posts/2/minted"));
    expect(JSON.parse(opts.body)).toEqual({ tokenId: "102", txHash: "0xtx", contract: "0xcol", license: "CC BY-SA" });
  });

  it("marks the post errored when the recorded transaction failed", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    const { waitForConfirmation } = await import("../../assets/src/wallet.js");
    waitForConfirmation.mockRejectedValueOnce(new Error("Transaction reverted"));

    const result = await resumeMint({ ...base, postId: 2, txHash: "0xtx", contract: "0xcol", license: "CC BY-SA", index: 1 });

    expect(result).toEqual({ postId: 2, error: "Transaction reverted" });
    const paths = global.fetch.mock.calls.map((call) => call[0]);
    expect(paths).toContain("/wp-json/tokenize-content/v1/posts/2/error");
    expect(paths).not.toContain("/wp-json/tokenize-content/v1/posts/2/minted");
  });
});
