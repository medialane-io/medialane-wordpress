import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizePost } from "../../assets/src/metabox.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  mintedTokenIdsFromReceipt: vi.fn().mockReturnValue(["1"]),
  generateInterimKeypair: vi.fn().mockReturnValue({ privateKey: "0xpriv", publicKey: "0xpub", address: "0xinterim" }),
  signDeploymentWithInterimKey: vi.fn().mockResolvedValue(["0xdsig"]),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
  buildSponsoredDeploy: vi.fn().mockResolvedValue({ data: { typedData: {}, deployment: {}, calls: [] } }),
  provisionRecipientWallet: vi.fn().mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } }),
  getTokenizeData: vi.fn(),
}));

async function serverPost(post) {
  const { getTokenizeData } = await import("../../assets/src/api.js");
  getTokenizeData.mockResolvedValue({ posts: { 1: post } });
  return getTokenizeData;
}

describe("tokenizePost", () => {
  beforeEach(async () => {
    await serverPost({
      title: "Hello", description: "World", image: "",
      authorEmail: "author@example.com", collectionContract: "0xcol",
    });
    global.window = {
      tokenizeContentData: {
        collectionContract: "0xcol",
        restUrl: "/wp-json/tokenize-content/v1",
        nonce: "abc",
      },
    };
    global.document = {
      getElementById: (id) => {
        if (id === "tokenize-content-license") return { value: "All Rights Reserved" };
        if (id === "tokenize-content-license-custom") return { value: "" };
        return null;
      },
    };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
  });

  it("throws when no collection is configured", async () => {
    global.window.tokenizeContentData.collectionContract = "";
    await serverPost({
      title: "Hello", description: "World", image: "",
      authorEmail: "author@example.com", collectionContract: "",
    });
    await expect(tokenizePost(1)).rejects.toThrow("No collection configured");
  });

  it("refuses to tokenize when the connected wallet is not the site's saved wallet", async () => {
    global.window.tokenizeContentData.walletAddress = "0xdef";
    const getTokenizeData = await serverPost({ title: "t", description: "e", image: "", authorEmail: "a@example.com", collectionContract: "0xcol" });
    getTokenizeData.mockClear();

    await expect(tokenizePost(1)).rejects.toThrow("not the wallet saved for this site");
    expect(getTokenizeData).not.toHaveBeenCalled();
  });

  it("reads the post's data from the server at click time, not from the page", async () => {
    const getTokenizeData = await serverPost({
      title: "Fresh title", description: "e", image: "",
      authorEmail: "author@example.com", collectionContract: "0xcol",
    });
    const { uploadJson } = await import("../../assets/src/api.js");
    uploadJson.mockClear();

    await tokenizePost(1);

    expect(getTokenizeData).toHaveBeenCalledWith([1]);
    expect(uploadJson).toHaveBeenCalledWith(expect.objectContaining({ name: "Fresh title" }));
  });

  it("throws the server's message for an ineligible post", async () => {
    await serverPost({ error: "Only published posts without a password can be tokenized." });
    await expect(tokenizePost(1)).rejects.toThrow("Only published posts without a password can be tokenized.");
  });

  it("uploads metadata, mints, and marks the post minted", async () => {
    const txHash = await tokenizePost(1);
    expect(txHash).toBe("0xtx");
    expect(global.fetch).toHaveBeenCalledWith(
      "/wp-json/tokenize-content/v1/posts/1/minted",
      expect.objectContaining({ method: "POST" })
    );
  });
});
