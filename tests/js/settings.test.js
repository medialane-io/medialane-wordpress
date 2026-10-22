import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn(),
  waitForConfirmation: vi.fn().mockResolvedValue({}),
}));
vi.mock("../../assets/src/sponsor.js", () => ({
  sponsor: vi.fn().mockResolvedValue({ transactionHash: "0xtx" }),
}));
vi.mock("../../assets/src/api.js", () => ({
  createCollectionIntent: vi.fn().mockResolvedValue({ data: { id: "intent-9", requiresSignature: false, calls: [{ contractAddress: "0xfactory" }] } }),
  syncCollectionTx: vi.fn().mockResolvedValue({}),
  getCollectionsByOwner: vi.fn(),
  saveCollectionEntry: vi.fn().mockResolvedValue({ labels: {} }),
  saveCategoryMap: vi.fn().mockResolvedValue({ map: {} }),
  saveWalletAddress: vi.fn().mockResolvedValue({ address: "0xowner" }),
}));

const { pollForNewCollection, createAndRegisterCollection, collectCategoryMap } = await import("../../assets/src/settings.js");

describe("pollForNewCollection", () => {
  const listing = (...contracts) => ({ data: contracts.map((contractAddress, i) => ({ contractAddress, collectionId: String(i + 1) })) });

  it("returns the collection that was not there before, even when older ones are listed", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner
      .mockReset()
      .mockResolvedValueOnce(listing("0xold"))
      .mockResolvedValueOnce(listing("0xnew", "0xold"));

    const result = await pollForNewCollection("0xowner", ["0xold"], { attempts: 3, intervalMs: 0 });

    expect(result).toBe("0xnew");
  });

  it("matches known collections regardless of address formatting", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner.mockReset().mockResolvedValue(listing("0x000OLD"));

    const result = await pollForNewCollection("0xowner", ["0xold"], { attempts: 2, intervalMs: 0 });

    expect(result).toBeNull();
  });

  it("keeps polling past a transient error instead of aborting", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner
      .mockReset()
      .mockRejectedValueOnce(new Error("upstream down"))
      .mockResolvedValueOnce(listing("0xnew"));

    const result = await pollForNewCollection("0xowner", [], { attempts: 2, intervalMs: 0 });

    expect(result).toBe("0xnew");
  });

  it("gives up with null when nothing new appears", async () => {
    const { getCollectionsByOwner } = await import("../../assets/src/api.js");
    getCollectionsByOwner.mockReset().mockResolvedValue(listing("0xold"));

    expect(await pollForNewCollection("0xowner", ["0xold"], { attempts: 3, intervalMs: 0 })).toBeNull();
    expect(getCollectionsByOwner).toHaveBeenCalledTimes(3);
  });
});

describe("createAndRegisterCollection", () => {
  beforeEach(async () => {
    const { getCollectionsByOwner, createCollectionIntent, saveCollectionEntry } = await import("../../assets/src/api.js");
    const { sponsor } = await import("../../assets/src/sponsor.js");
    getCollectionsByOwner.mockReset();
    createCollectionIntent.mockClear();
    saveCollectionEntry.mockClear();
    sponsor.mockClear();
  });

  it("labels the newly created collection, not an older one that is listed first", async () => {
    const { getCollectionsByOwner, createCollectionIntent, saveCollectionEntry } = await import("../../assets/src/api.js");
    const { sponsor } = await import("../../assets/src/sponsor.js");
    getCollectionsByOwner
      .mockResolvedValueOnce({ data: [{ contractAddress: "0xold", collectionId: "1" }] })
      .mockResolvedValueOnce({ data: [{ contractAddress: "0xold", collectionId: "1" }] })
      .mockResolvedValue({ data: [{ contractAddress: "0xnew", collectionId: "2" }, { contractAddress: "0xold", collectionId: "1" }] });

    const contract = await createAndRegisterCollection("0xowner", {}, "Politics", { intervalMs: 0 });

    expect(createCollectionIntent).toHaveBeenCalledWith(expect.objectContaining({ owner: "0xowner", name: "Politics" }));
    expect(sponsor).toHaveBeenCalledWith({}, "0xowner", ["intent-9"]);
    expect(saveCollectionEntry).toHaveBeenCalledWith({ contract: "0xnew", label: "Politics" });
    expect(contract).toBe("0xnew");
  });

  it("waits for the sponsored transaction to confirm and syncs it before looking for the collection", async () => {
    const { getCollectionsByOwner, syncCollectionTx } = await import("../../assets/src/api.js");
    const { waitForConfirmation } = await import("../../assets/src/wallet.js");
    getCollectionsByOwner.mockResolvedValue({ data: [{ contractAddress: "0xnew", collectionId: "2" }] });
    getCollectionsByOwner.mockResolvedValueOnce({ data: [] });

    await createAndRegisterCollection("0xowner", {}, "Politics", { intervalMs: 0 });

    expect(waitForConfirmation).toHaveBeenCalledWith("0xtx");
    expect(syncCollectionTx).toHaveBeenCalledWith("0xtx");
  });

  it("does not spend anything on chain when the existing collections cannot be read", async () => {
    const { getCollectionsByOwner, createCollectionIntent } = await import("../../assets/src/api.js");
    const { sponsor } = await import("../../assets/src/sponsor.js");
    getCollectionsByOwner.mockRejectedValue(new Error("upstream down"));

    await expect(createAndRegisterCollection("0xowner", {}, "Politics", { intervalMs: 0 })).rejects.toThrow("upstream down");
    expect(createCollectionIntent).not.toHaveBeenCalled();
    expect(sponsor).not.toHaveBeenCalled();
  });

  it("saves no label when the new collection never shows up", async () => {
    const { getCollectionsByOwner, saveCollectionEntry } = await import("../../assets/src/api.js");
    getCollectionsByOwner.mockResolvedValue({ data: [{ contractAddress: "0xold", collectionId: "1" }] });

    const contract = await createAndRegisterCollection("0xowner", {}, "Politics", { attempts: 2, intervalMs: 0 });

    expect(contract).toBeNull();
    expect(saveCollectionEntry).not.toHaveBeenCalled();
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
