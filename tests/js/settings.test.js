import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn(),
  executeCalls: vi.fn().mockResolvedValue("0xtx"),
}));
vi.mock("../../assets/src/api.js", () => ({
  createCollectionIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0xfactory" }] } }),
  syncCollectionTx: vi.fn().mockResolvedValue({}),
  getCollectionsByOwner: vi.fn().mockResolvedValue({ data: [{ contract: "0xnewcol" }] }),
  saveCollectionEntry: vi.fn().mockResolvedValue({ labels: {} }),
  saveCategoryMap: vi.fn().mockResolvedValue({ map: {} }),
  saveWalletAddress: vi.fn().mockResolvedValue({ address: "0xowner" }),
}));

const { pollForCollection, createAndRegisterCollection, collectCategoryMap } = await import("../../assets/src/settings.js");

describe("pollForCollection", () => {
  beforeEach(() => {
    global.window = { tokenizeContentData: { restUrl: "/wp-json/tokenize-content/v1", nonce: "abc" } };
  });

  it("returns the first collection's real contractAddress field once the list is non-empty", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner
      .mockReset()
      .mockResolvedValueOnce({ data: [] })
      .mockResolvedValueOnce({ data: [{ contractAddress: "0xcol" }] });

    const result = await pollForCollection("0xowner", 2);
    expect(result).toBe("0xcol");
  }, 10000);

  it("keeps polling past a transient error instead of aborting", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner
      .mockReset()
      .mockRejectedValueOnce(new Error("upstream down"))
      .mockResolvedValueOnce({ data: [{ contractAddress: "0xcol" }] });

    const result = await pollForCollection("0xowner", 2);
    expect(result).toBe("0xcol");
  }, 10000);
});

describe("createAndRegisterCollection", () => {
  it("creates the on-chain collection, waits for it to index, and registers it under the given label", async () => {
    const { executeCalls } = await import("../../assets/src/wallet.js");
    const { createCollectionIntent, saveCollectionEntry, getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner.mockReset().mockResolvedValue({ data: [{ contractAddress: "0xnewcol" }] });

    const contract = await createAndRegisterCollection("0xowner", {}, "Politics");

    expect(createCollectionIntent).toHaveBeenCalledWith(expect.objectContaining({ owner: "0xowner", name: "Politics" }));
    expect(executeCalls).toHaveBeenCalled();
    expect(saveCollectionEntry).toHaveBeenCalledWith({ contract: "0xnewcol", label: "Politics" });
    expect(contract).toBe("0xnewcol");
  });
});

describe("collectCategoryMap", () => {
  it("builds a category-id-to-contract map, skipping unselected rows", () => {
    const rows = [
      { categoryId: "3", value: "0xnews" },
      { categoryId: "4", value: "" },
      { categoryId: "5", value: "0xsports" },
    ];
    expect(collectCategoryMap(rows)).toEqual({ 3: "0xnews", 5: "0xsports" });
  });
});
