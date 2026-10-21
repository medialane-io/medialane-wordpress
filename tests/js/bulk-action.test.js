import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn(),
  signTypedData: vi.fn(),
  waitForConfirmation: vi.fn(),
  mintedTokenIdsFromReceipt: vi.fn(),
}));
vi.mock("../../assets/src/session.js", () => ({
  ensureSession: vi.fn(),
  forgetSession: vi.fn(),
}));
vi.mock("../../assets/src/api.js", () => ({
  getTokenizeData: vi.fn(),
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

const api = await import("../../assets/src/api.js");
const wallet = await import("../../assets/src/wallet.js");
const { tokenizeBulk, buildStatusPanel } = await import("../../assets/src/bulk-action.js");

const post = (n, extra = {}) => ({
  title: `Post ${n}`, description: `body ${n}`, hasImage: false,
  authorEmail: `author${n}@example.com`, collectionContract: "0xcol", collectionId: "7", ...extra,
});

function serverPosts(posts) {
  api.getTokenizeData.mockResolvedValue({ posts });
}

beforeEach(() => {
  vi.resetAllMocks();
  global.window = {
    tokenizeContentData: { licenseDefault: "CC BY-SA", aiPolicyDefault: "Allowed", walletAddress: "0xowner" },
  };
  wallet.connectWallet.mockResolvedValue({ address: "0xowner", account: {} });
  wallet.signTypedData.mockResolvedValue(["0x1", "0x2"]);
  wallet.waitForConfirmation.mockResolvedValue({ isReverted: () => false });
  wallet.mintedTokenIdsFromReceipt.mockReturnValue(Array.from({ length: 30 }, (_, i) => String(i + 1)));
  api.provisionRecipientWallet.mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } });
  api.uploadJson.mockResolvedValue({ data: { url: "ipfs://meta" } });
  let n = 0;
  api.createMintIntent.mockImplementation(async () => ({ data: { id: `intent-${++n}`, calls: [] } }));
  api.buildSponsoredInvoke.mockResolvedValue({ typedData: { message: {} } });
  api.executeSponsoredInvoke.mockResolvedValue({ transactionHash: "0xtx" });
  for (const fn of ["markMinting", "markBroadcast", "markMinted", "markError"]) api[fn].mockResolvedValue({});
  serverPosts({ 1: post(1), 2: post(2) });
});

describe("tokenizeBulk", () => {
  it("fetches the selected posts' data from the server once, by id", async () => {
    await tokenizeBulk(["1", "2"], () => {});

    expect(api.getTokenizeData).toHaveBeenCalledTimes(1);
    expect(api.getTokenizeData).toHaveBeenCalledWith(["1", "2"]);
  });

  it("prepares every post, then mints the whole batch in one sponsored transaction", async () => {
    const progress = [];
    await tokenizeBulk(["1", "2"], (id, status) => progress.push(`${id}:${status}`));

    expect(progress).toEqual(["1:preparing", "2:preparing", "1:minting", "2:minting", "1:minted", "2:minted"]);
    expect(api.executeSponsoredInvoke).toHaveBeenCalledTimes(1);
    expect(api.buildSponsoredInvoke).toHaveBeenCalledWith({ intentIds: ["intent-1", "intent-2"] });
  });

  it("mints with each post's resolved numeric collection id and the site's default license", async () => {
    await tokenizeBulk(["1"], () => {});

    expect(api.createMintIntent).toHaveBeenCalledWith(expect.objectContaining({ owner: "0xowner", collectionId: "7" }));
    expect(api.uploadJson).toHaveBeenCalledWith(expect.objectContaining({
      name: "Post 1", description: "body 1",
      attributes: expect.arrayContaining([{ trait_type: "License", value: "CC BY-SA" }, { trait_type: "AI Policy", value: "Allowed" }]),
    }));
  });

  it("pins the featured image only for posts that have one", async () => {
    serverPosts({ 1: post(1, { hasImage: true }), 2: post(2) });
    api.uploadFeaturedImage.mockResolvedValue({ data: { url: "ipfs://img" } });

    await tokenizeBulk(["1", "2"], () => {});

    expect(api.uploadFeaturedImage).toHaveBeenCalledTimes(1);
    expect(api.uploadFeaturedImage).toHaveBeenCalledWith("1");
  });

  it("groups posts by collection into separate batches", async () => {
    serverPosts({
      1: post(1, { collectionContract: "0xnews", collectionId: "1" }),
      2: post(2, { collectionContract: "0xsports", collectionId: "2" }),
    });

    await tokenizeBulk(["1", "2"], () => {});

    expect(api.executeSponsoredInvoke).toHaveBeenCalledTimes(2);
    expect(wallet.mintedTokenIdsFromReceipt).toHaveBeenCalledWith(expect.anything(), "0xnews");
    expect(wallet.mintedTokenIdsFromReceipt).toHaveBeenCalledWith(expect.anything(), "0xsports");
  });

  it("splits more than 25 posts into multiple batches", async () => {
    const ids = Array.from({ length: 30 }, (_, i) => String(i + 1));
    serverPosts(Object.fromEntries(ids.map((id) => [id, post(id)])));

    await tokenizeBulk(ids, () => {});

    expect(api.executeSponsoredInvoke).toHaveBeenCalledTimes(2);
  });

  it("refuses to start when the connected wallet is not the site's saved wallet", async () => {
    global.window.tokenizeContentData.walletAddress = "0xdef";

    await expect(tokenizeBulk(["1"], () => {})).rejects.toThrow("not the wallet saved for this site");
    expect(api.getTokenizeData).not.toHaveBeenCalled();
  });

  it("marks a post errored, and mints nothing for it, when it has no resolvable collection", async () => {
    serverPosts({ 1: post(1, { collectionContract: "", collectionId: "" }) });
    const progress = [];

    const result = await tokenizeBulk(["1"], (id, status, message) => progress.push(`${id}:${status}:${message || ""}`));

    expect(progress).toContain("1:error:No collection configured.");
    expect(api.executeSponsoredInvoke).not.toHaveBeenCalled();
    expect(result).toEqual({ minted: 0, failed: 1 });
  });

  it("keeps going when one post fails to prepare, marking only that post errored", async () => {
    serverPosts({ 1: post(1), 2: post(2, { authorEmail: "" }), 3: post(3) });
    const progress = [];

    const result = await tokenizeBulk(["1", "2", "3"], (id, status) => progress.push(`${id}:${status}`));

    expect(progress).toEqual(expect.arrayContaining(["1:minted", "2:error", "3:minted"]));
    expect(api.executeSponsoredInvoke).toHaveBeenCalledTimes(1);
    expect(api.markError).toHaveBeenCalledWith("2", expect.stringContaining("no registered email"));
    expect(result).toEqual({ minted: 2, failed: 1 });
  });

  it("still finishes the run when recording a failure itself fails", async () => {
    serverPosts({ 1: post(1, { authorEmail: "" }), 2: post(2) });
    api.markError.mockRejectedValueOnce(new Error("network down"));

    const result = await tokenizeBulk(["1", "2"], () => {});

    expect(result).toEqual({ minted: 1, failed: 1 });
  });

  it("reports a selected post the server did not return instead of skipping it silently", async () => {
    const progress = [];

    const result = await tokenizeBulk(["1", "9"], (id, status, message) => progress.push(`${id}:${status}:${message || ""}`));

    expect(progress).toContain("9:error:No data was returned for this post.");
    expect(result).toEqual({ minted: 1, failed: 1 });
  });

  it("reports an ineligible post from the server's message without writing to it", async () => {
    serverPosts({ 1: { error: "Only published posts without a password can be tokenized." } });
    const progress = [];

    const result = await tokenizeBulk(["1"], (id, status, message) => progress.push(`${id}:${status}:${message || ""}`));

    expect(progress).toContain("1:error:Only published posts without a password can be tokenized.");
    expect(api.markError).not.toHaveBeenCalled();
    expect(result).toEqual({ minted: 0, failed: 1 });
  });

  it("counts a failed batch against every post in it", async () => {
    api.executeSponsoredInvoke.mockRejectedValue(new Error("Gas sponsorship is temporarily unavailable"));

    const result = await tokenizeBulk(["1", "2"], () => {});

    expect(result).toEqual({ minted: 0, failed: 2 });
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

  it("shows a closing summary line", () => {
    const panel = buildStatusPanel(["1"], { 1: "A" });

    panel.finish("1 minted, 0 failed.");

    expect(panel.element.textContent).toContain("1 minted, 0 failed.");
  });
});
