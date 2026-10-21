import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  createMintIntent, buildSponsoredInvoke, executeSponsoredInvoke,
  buildSponsoredDeploy, provisionRecipientWallet, saveCollectionEntry, saveCategoryMap,
  saveWalletAddress,
} from "../../assets/src/api.js";

describe("api client", () => {
  beforeEach(() => {
    global.window = { tokenizeContentData: { restUrl: "/wp-json/tokenize-content/v1", nonce: "abc" } };
  });

  it("posts mint intent params and returns parsed body", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { calls: [{ contractAddress: "0x1" }] } }),
    });
    const result = await createMintIntent({ owner: "0x1", collectionId: "0x2", recipient: "0x1", tokenUri: "ipfs://x", royaltyBps: 0 });
    expect(result.data.calls).toHaveLength(1);
    expect(global.fetch).toHaveBeenCalledWith(
      "/wp-json/tokenize-content/v1/intents/mint",
      expect.objectContaining({ method: "POST" })
    );
  });

  it("exports nothing the plugin does not call", async () => {
    const api = await import("../../assets/src/api.js");
    expect(api.getToken).toBeUndefined();
    expect(api.saveCollectionContract).toBeUndefined();
  });

  it("buildSponsoredInvoke posts to /paymaster/invoke/build", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { typedData: {} } }) });
    await buildSponsoredInvoke({ userAddress: "0xabc", calls: [] });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/paymaster/invoke/build"),
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("executeSponsoredInvoke posts to /paymaster/invoke/execute", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { transactionHash: "0x1" } }) });
    const body = await executeSponsoredInvoke({ userAddress: "0xabc", typedData: {}, signature: ["0x1"], calls: [] });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/paymaster/invoke/execute"),
      expect.objectContaining({ method: "POST" }),
    );
    expect(body.data.transactionHash).toBe("0x1");
  });

  it("buildSponsoredDeploy posts to /paymaster/deploy/build", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { typedData: {}, deployment: {}, calls: [] } }) });
    await buildSponsoredDeploy({ ownerPubkey: "0xpub", ownerAddress: "0xaddr" });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/paymaster/deploy/build"),
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("provisionRecipientWallet posts to /business/provisioning", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { walletAddress: "0xnew" } }) });
    const body = await provisionRecipientWallet({
      recipientScheme: "email", recipientValue: "a@example.com",
      interimOwnerPubkey: "0xpub", derivationSalt: "abcdefghijklmnop",
      deployment: { typedData: {}, signature: ["0x1"], deployment: {} },
    });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/business/provisioning"),
      expect.objectContaining({ method: "POST" }),
    );
    expect(body.data.walletAddress).toBe("0xnew");
  });

  it("saveCollectionEntry posts to /settings/collections", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ collections: [{ contract: "0xa", label: "News" }] }) });
    const body = await saveCollectionEntry({ contract: "0xa", label: "News" });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/settings/collections"),
      expect.objectContaining({ method: "POST" }),
    );
    expect(body.collections).toHaveLength(1);
  });

  it("saveCategoryMap posts to /settings/category-map", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ map: { 2: "0xa" } }) });
    await saveCategoryMap({ 2: "0xa" });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/settings/category-map"),
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("saveWalletAddress posts to /settings/wallet", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ address: "0xabc" }) });
    const body = await saveWalletAddress("0xabc");
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/settings/wallet"),
      expect.objectContaining({ method: "POST" }),
    );
    expect(body.address).toBe("0xabc");
  });
});
