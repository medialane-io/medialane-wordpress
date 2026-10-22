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
const { tokenizePost } = await import("../../assets/src/metabox.js");

const serverPost = (extra = {}) => ({
  title: "Hello", description: "World", hasImage: false, authorEmail: "author@example.com",
  collectionContract: "0xcol", collectionId: "7", ...extra,
});

function pageElements({ license = "All Rights Reserved", select = null } = {}) {
  global.document = {
    getElementById: (id) => {
      if (id === "tokenize-content-license") return { value: license };
      if (id === "tokenize-content-license-custom") return { value: "" };
      if (id === "tokenize-content-collection") return select;
      return null;
    },
  };
}

beforeEach(() => {
  vi.resetAllMocks();
  global.window = { tokenizeContentData: { aiPolicyDefault: "Allowed", walletAddress: "0xowner", licenses: {} } };
  pageElements();
  wallet.connectWallet.mockResolvedValue({ address: "0xowner", account: {} });
  wallet.signTypedData.mockResolvedValue(["0x1", "0x2"]);
  wallet.waitForConfirmation.mockResolvedValue({ isReverted: () => false });
  wallet.mintedTokenIdsFromReceipt.mockReturnValue(["1"]);
  api.getTokenizeData.mockResolvedValue({ posts: { 1: serverPost() } });
  api.provisionRecipientWallet.mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } });
  api.uploadJson.mockResolvedValue({ data: { url: "ipfs://meta" } });
  api.createMintIntent.mockResolvedValue({ data: { id: "intent-1", calls: [] } });
  api.buildSponsoredInvoke.mockResolvedValue({ typedData: { message: {} } });
  api.executeSponsoredInvoke.mockResolvedValue({ transactionHash: "0xtx" });
  for (const fn of ["markMinting", "markBroadcast", "markMinted", "markError"]) api[fn].mockResolvedValue({});
});

describe("tokenizePost", () => {
  it("reads the post's data from the server at click time, not from the page", async () => {
    api.getTokenizeData.mockResolvedValue({ posts: { 1: serverPost({ title: "Fresh title" }) } });

    await tokenizePost(1);

    expect(api.getTokenizeData).toHaveBeenCalledWith([1]);
    expect(api.uploadJson).toHaveBeenCalledWith(expect.objectContaining({ name: "Fresh title", description: "World" }));
  });

  it("mints into the post's resolved collection and returns the transaction hash", async () => {
    const txHash = await tokenizePost(1);

    expect(txHash).toBe("0xtx");
    expect(api.createMintIntent).toHaveBeenCalledWith(expect.objectContaining({ owner: "0xowner", collectionId: "7" }));
    expect(api.markMinted).toHaveBeenCalledWith(1, expect.objectContaining({ contract: "0xcol", txHash: "0xtx" }));
  });

  it("uses the collection chosen in the selector over the resolved one", async () => {
    pageElements({ select: { selectedOptions: [{ value: "0xother", dataset: { collectionId: "9" } }] } });

    await tokenizePost(1);

    expect(api.createMintIntent).toHaveBeenCalledWith(expect.objectContaining({ collectionId: "9" }));
    expect(api.markMinted).toHaveBeenCalledWith(1, expect.objectContaining({ contract: "0xother" }));
  });

  it("uses the typed terms when the license is Custom", async () => {
    global.document = {
      getElementById: (id) => ({
        "tokenize-content-license": { value: "Custom" },
        "tokenize-content-license-custom": { value: "Ask me first" },
      }[id] || null),
    };

    await tokenizePost(1);

    expect(api.uploadJson.mock.calls[0][0].attributes).toEqual(expect.arrayContaining([{ trait_type: "License", value: "Ask me first" }]));
  });

  it("falls back to the site's default license when Custom is chosen and the terms are left empty", async () => {
    global.window.tokenizeContentData.licenseDefault = "CC BY-SA";
    global.window.tokenizeContentData.licenses = { "CC BY-SA": { commercialUse: "Yes", derivatives: "Share-Alike", attribution: "Required" } };
    for (const typed of ["", "   "]) {
      api.uploadJson.mockClear();
      global.document = {
        getElementById: (id) => ({
          "tokenize-content-license": { value: "Custom" },
          "tokenize-content-license-custom": { value: typed },
        }[id] || null),
      };

      await tokenizePost(1);

      expect(api.uploadJson.mock.calls[0][0].attributes).toEqual(expect.arrayContaining([
        { trait_type: "License", value: "CC BY-SA" },
        { trait_type: "Derivatives", value: "Share-Alike" },
      ]));
    }
  });

  it("throws when no collection is configured", async () => {
    api.getTokenizeData.mockResolvedValue({ posts: { 1: serverPost({ collectionContract: "", collectionId: "" }) } });

    await expect(tokenizePost(1)).rejects.toThrow("No collection configured");
    expect(api.createMintIntent).not.toHaveBeenCalled();
  });

  it("throws the server's message for an ineligible post", async () => {
    api.getTokenizeData.mockResolvedValue({ posts: { 1: { error: "Only published posts without a password can be tokenized." } } });

    await expect(tokenizePost(1)).rejects.toThrow("Only published posts without a password can be tokenized.");
  });

  it("refuses to tokenize when the connected wallet is not the site's saved wallet", async () => {
    global.window.tokenizeContentData.walletAddress = "0xdef";

    await expect(tokenizePost(1)).rejects.toThrow("not the wallet saved for this site");
    expect(api.getTokenizeData).not.toHaveBeenCalled();
  });

  it("throws the batch's error when minting fails", async () => {
    api.executeSponsoredInvoke.mockRejectedValue(new Error("Gas sponsorship is temporarily unavailable"));

    await expect(tokenizePost(1)).rejects.toThrow("Gas sponsorship is temporarily unavailable");
  });
});
