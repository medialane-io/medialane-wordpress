import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizeBulk, buildStatusPanel } from "../../assets/src/bulk-action.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  mintedTokenIdsFromReceipt: vi.fn().mockReturnValue(Array.from({ length: 30 }, (_, i) => String(i + 1))),
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

async function serverPosts(posts) {
  const { getTokenizeData } = await import("../../assets/src/api.js");
  getTokenizeData.mockResolvedValue({ posts });
  return getTokenizeData;
}

describe("tokenizeBulk", () => {
  beforeEach(async () => {
    global.window = {
      tokenizeContentData: {
        collectionContract: "0xcol",
        contentScope: "excerpt",
        licenseDefault: "CC BY-SA",
        restUrl: "/wp-json/tokenize-content/v1",
        nonce: "abc",
      },
    };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    await serverPosts({
      1: { title: "A", excerpt: "a", content: "aa", image: "", authorEmail: "a@example.com" },
      2: { title: "B", excerpt: "b", content: "bb", image: "", authorEmail: "b@example.com" },
    });
  });

  it("fetches the selected posts' data from the server once, by id", async () => {
    const getTokenizeData = await serverPosts({
      1: { title: "A", excerpt: "a", content: "aa", image: "", authorEmail: "a@example.com" },
      2: { title: "B", excerpt: "b", content: "bb", image: "", authorEmail: "b@example.com" },
    });
    getTokenizeData.mockClear();

    await tokenizeBulk(["1", "2"], () => {});

    expect(getTokenizeData).toHaveBeenCalledTimes(1);
    expect(getTokenizeData).toHaveBeenCalledWith(["1", "2"]);
  });

  it("prepares every post, then mints the whole batch in one sponsored transaction", async () => {
    const progress = [];
    await tokenizeBulk(["1", "2"], (id, status) => progress.push(`${id}:${status}`));
    expect(progress).toEqual([
      "1:preparing", "2:preparing",
      "1:minting", "2:minting",
      "1:minted", "2:minted",
    ]);
  });

  it("uses the site's configured default license instead of a hardcoded one", async () => {
    const { createMintIntent } = await import("../../assets/src/api.js");
    createMintIntent.mockClear();
    global.window.tokenizeContentData.licenseDefault = "CC BY-SA";

    await tokenizeBulk(["1"], () => {});

    const { uploadJson } = await import("../../assets/src/api.js");
    expect(uploadJson).toHaveBeenCalledWith(expect.objectContaining({
      attributes: expect.arrayContaining([{ trait_type: "License", value: "CC BY-SA" }]),
    }));
  });

  it("groups posts by their resolved collection into separate batches", async () => {
    await serverPosts({
      1: { title: "A", excerpt: "a", content: "aa", image: "", authorEmail: "a@example.com", collectionContract: "0xnews" },
      2: { title: "B", excerpt: "b", content: "bb", image: "", authorEmail: "b@example.com", collectionContract: "0xsports" },
    });
    const { executeSponsoredInvoke, createMintIntent } = await import("../../assets/src/api.js");
    executeSponsoredInvoke.mockClear();
    createMintIntent.mockClear();

    await tokenizeBulk(["1", "2"], () => {});

    expect(executeSponsoredInvoke).toHaveBeenCalledTimes(2);
    expect(createMintIntent).toHaveBeenCalledWith(expect.objectContaining({ collectionId: "0xnews" }));
    expect(createMintIntent).toHaveBeenCalledWith(expect.objectContaining({ collectionId: "0xsports" }));
  });

  it("throws when a post has no resolvable collection at all", async () => {
    global.window.tokenizeContentData.collectionContract = "";
    await serverPosts({
      1: { title: "A", excerpt: "a", content: "aa", image: "", authorEmail: "a@example.com", collectionContract: "" },
    });
    await expect(tokenizeBulk(["1"], () => {})).rejects.toThrow("No collection configured");
  });

  it("splits more than 25 posts into multiple batches", async () => {
    const postIds = Array.from({ length: 30 }, (_, i) => String(i + 1));
    await serverPosts(Object.fromEntries(
      postIds.map((id) => [id, { title: "t", excerpt: "e", content: "c", image: "", authorEmail: `author${id}@example.com` }])
    ));

    const { executeSponsoredInvoke } = await import("../../assets/src/api.js");
    executeSponsoredInvoke.mockClear();

    await tokenizeBulk(postIds, () => {});

    expect(executeSponsoredInvoke).toHaveBeenCalledTimes(2);
  });
});

describe("buildStatusPanel", () => {
  it("lists every post and updates its row as progress comes in", () => {
    const panel = buildStatusPanel(["1", "2"], { 1: "A", 2: "B" });

    const rows = panel.element.querySelectorAll("li");
    expect(rows[0].textContent).toBe("A — Waiting…");
    expect(rows[1].textContent).toBe("B — Waiting…");

    panel.update("1", "minting");
    expect(rows[0].textContent).toBe("A — Signing & minting…");

    panel.update("2", "error", "Something went wrong");
    expect(rows[1].textContent).toBe("B — Failed: Something went wrong");
  });
});
