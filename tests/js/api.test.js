import { describe, it, expect, vi, beforeEach } from "vitest";
import * as api from "../../assets/src/api.js";

function respond(body, init = {}) {
  global.fetch = vi.fn().mockResolvedValue({ ok: init.ok ?? true, status: init.status ?? 200, json: async () => body });
  return global.fetch;
}

function lastCall(fetchMock) {
  const [url, opts] = fetchMock.mock.calls.at(-1);
  return { url, opts, body: opts.body ? JSON.parse(opts.body) : undefined };
}

describe("api client", () => {
  beforeEach(() => {
    global.window = { tokenizeContentData: { restUrl: "/wp-json/tokenize-content/v1", nonce: "abc" } };
    api.setSession("");
  });

  it("sends the WordPress nonce and returns the parsed body", async () => {
    const fetchMock = respond({ data: { id: "i1", calls: [{ contractAddress: "0x1" }] } });

    const result = await api.createMintIntent({ owner: "0x1", recipient: "0x2", collectionId: "7", tokenUri: "ipfs://x", royaltyBps: 0 });

    const { url, opts, body } = lastCall(fetchMock);
    expect(url).toBe("/wp-json/tokenize-content/v1/intents/mint");
    expect(opts.method).toBe("POST");
    expect(opts.headers["X-WP-Nonce"]).toBe("abc");
    expect(body).toEqual({ owner: "0x1", recipient: "0x2", collectionId: "7", tokenUri: "ipfs://x", royaltyBps: 0 });
    expect(result.data.id).toBe("i1");
  });

  it("sends the session token once one is set, and stops when it is cleared", async () => {
    const fetchMock = respond({});
    api.setSession("tok123");
    await api.buildSponsoredInvoke({ intentIds: ["i1"] });
    expect(lastCall(fetchMock).opts.headers["X-Tokenize-Session"]).toBe("tok123");

    api.setSession("");
    await api.buildSponsoredInvoke({ intentIds: ["i1"] });
    expect(lastCall(fetchMock).opts.headers).not.toHaveProperty("X-Tokenize-Session");
  });

  it("throws an ApiError that carries the status and the backend's code", async () => {
    respond({ code: "may_have_broadcast", message: "It may have been submitted", data: { status: 502 } }, { ok: false, status: 502 });

    const error = await api.executeSponsoredInvoke({ intentIds: ["i1"], typedData: {}, signature: ["0x1"] }).catch((e) => e);

    expect(error).toBeInstanceOf(api.ApiError);
    expect(error.message).toBe("It may have been submitted");
    expect(error.status).toBe(502);
    expect(error.code).toBe("may_have_broadcast");
  });

  it("falls back to a generic message when the error body has none", async () => {
    respond({}, { ok: false, status: 500 });
    await expect(api.getTokenizeData([1])).rejects.toThrow("Request failed (500)");
  });

  it.each([
    ["siwsNonce", () => api.siwsNonce("0xabc"), "/auth/siws/nonce", { walletAddress: "0xabc" }],
    ["siwsVerify", () => api.siwsVerify({ walletAddress: "0xabc", nonce: "n", signature: ["0x1"] }), "/auth/siws/verify", { walletAddress: "0xabc", nonce: "n", signature: ["0x1"] }],
    ["buildSponsoredInvoke", () => api.buildSponsoredInvoke({ intentIds: ["i1"] }), "/paymaster/invoke/build", { intentIds: ["i1"] }],
    ["executeSponsoredInvoke", () => api.executeSponsoredInvoke({ intentIds: ["i1"], typedData: { a: 1 }, signature: ["0x1"] }), "/paymaster/invoke/execute", { intentIds: ["i1"], typedData: { a: 1 }, signature: ["0x1"] }],
    ["provisionRecipientWallet", () => api.provisionRecipientWallet({ email: "a@example.org" }), "/business/provisioning", { email: "a@example.org" }],
    ["getTokenizeData", () => api.getTokenizeData(["1", "2"]), "/tokenize-data", { ids: ["1", "2"] }],
    ["saveCategoryMap", () => api.saveCategoryMap({ 2: "0xa" }), "/settings/category-map", { map: { 2: "0xa" } }],
    ["saveWalletAddress", () => api.saveWalletAddress("0xabc"), "/settings/wallet", { address: "0xabc" }],
    ["saveCollectionEntry", () => api.saveCollectionEntry({ contract: "0xa", label: "News" }), "/settings/collections", { contract: "0xa", label: "News" }],
    ["syncCollectionTx", () => api.syncCollectionTx("0xhash"), "/collections/sync-tx", { txHash: "0xhash" }],
    ["createCollectionIntent", () => api.createCollectionIntent({ owner: "0x1", name: "News", symbol: "POST" }), "/intents/create-collection", { owner: "0x1", name: "News", symbol: "POST" }],
  ])("%s posts to the right route with the right body", async (_name, call, path, expected) => {
    const fetchMock = respond({});
    await call();
    const { url, opts, body } = lastCall(fetchMock);
    expect(url).toBe(`/wp-json/tokenize-content/v1${path}`);
    expect(opts.method).toBe("POST");
    expect(body).toEqual(expected);
  });

  it("records post state through the post routes", async () => {
    const fetchMock = respond({ status: "minting" });

    await api.markMinting(5);
    expect(lastCall(fetchMock).url).toBe("/wp-json/tokenize-content/v1/posts/5/minting");

    await api.markBroadcast(5, { txHash: "0xt", contract: "0xc", license: "CC BY", index: 1 });
    expect(lastCall(fetchMock)).toMatchObject({ url: "/wp-json/tokenize-content/v1/posts/5/broadcast", body: { txHash: "0xt", contract: "0xc", license: "CC BY", index: 1 } });

    await api.markMinted(5, { tokenId: "9", txHash: "0xt", contract: "0xc", license: "CC BY" });
    expect(lastCall(fetchMock).url).toBe("/wp-json/tokenize-content/v1/posts/5/minted");

    await api.markError(5, "boom");
    expect(lastCall(fetchMock)).toMatchObject({ url: "/wp-json/tokenize-content/v1/posts/5/error", body: { message: "boom" } });
  });

  it("surfaces a failed post-state write instead of ignoring it", async () => {
    respond({ message: "Not allowed" }, { ok: false, status: 403 });
    await expect(api.markMinted(5, { tokenId: "9", txHash: "0xt", contract: "0xc", license: "CC BY" })).rejects.toThrow("Not allowed");
  });

  it("uploads the featured image by post id, not by URL", async () => {
    const fetchMock = respond({ data: { url: "ipfs://img" } });
    const result = await api.uploadFeaturedImage(5);
    expect(lastCall(fetchMock).url).toBe("/wp-json/tokenize-content/v1/posts/5/featured-image");
    expect(result.data.url).toBe("ipfs://img");
  });

  it("looks up collections by owner", async () => {
    const fetchMock = respond({ data: [] });
    await api.getCollectionsByOwner("0xabc");
    expect(lastCall(fetchMock)).toMatchObject({ url: "/wp-json/tokenize-content/v1/collections?owner=0xabc" });
    expect(lastCall(fetchMock).opts.method).toBe("GET");
  });

  it("points the RPC provider at the site's own proxy", () => {
    expect(api.rpcConfig()).toEqual({ nodeUrl: "/wp-json/tokenize-content/v1/rpc", headers: { "X-WP-Nonce": "abc" } });
  });

  it("exports nothing the plugin does not call", () => {
    for (const removed of ["getToken", "saveCollectionContract", "buildSponsoredDeploy", "uploadFile"]) {
      expect(api[removed]).toBeUndefined();
    }
  });
});
