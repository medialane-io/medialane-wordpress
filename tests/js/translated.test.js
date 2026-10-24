import { describe, it, expect, vi } from "vitest";

vi.mock("@wordpress/i18n", () => ({
  __: (text) => `«${text}»`,
  _n: (single, plural, number) => `«${number === 1 ? single : plural}»`,
  sprintf: (format, ...args) => {
    let next = 0;
    return format.replace(/%(?:(\d+)\$)?[sd]/g, (_m, position) => String(args[position ? Number(position) - 1 : next++]));
  },
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn(), uploadFeaturedImage: vi.fn(), createMintIntent: vi.fn(), provisionRecipientWallet: vi.fn(),
  buildSponsoredInvoke: vi.fn(), executeSponsoredInvoke: vi.fn(), getTokenizeData: vi.fn(),
  markMinting: vi.fn().mockResolvedValue({}), markMinted: vi.fn(), markError: vi.fn(),
  rpcConfig: () => ({}),
}));
vi.mock("../../assets/src/session.js", () => ({ ensureSession: vi.fn(), forgetSession: vi.fn() }));
vi.mock("get-starknet-core", () => ({ getStarknet: () => ({ getAvailableWallets: async () => [] }) }));
vi.mock("starknet", () => ({ RpcProvider: class {}, WalletAccount: {}, stark: {}, hash: { getSelectorFromName: () => "0x1" } }));

const { assertSiteWallet } = await import("../../assets/src/site-wallet.js");
const { connectWallet } = await import("../../assets/src/wallet.js");
const { prepareMint, executeMintBatch } = await import("../../assets/src/mint-flow.js");
const { buildStatusPanel, summaryLine, confirmMessage } = await import("../../assets/src/bulk-action.js");

describe("messages shown to people go through the translator", () => {
  it("wallet mismatch", () => {
    expect(() => assertSiteWallet("0xabc", "0xdef")).toThrow(/^«The connected wallet is not the wallet saved for this site/);
  });

  it("no wallet extension", async () => {
    await expect(connectWallet()).rejects.toThrow(/^«No Starknet wallet found/);
  });

  it("author without an email, with the post number filled in", async () => {
    await expect(prepareMint({ postId: 42, authorEmail: "" })).rejects.toThrow("«Post 42's author has no registered email. Assign a valid author before tokenizing.»");
  });

  it("no collection configured", async () => {
    await expect(executeMintBatch({ account: {}, address: "0x1", collectionContract: "", entries: [] })).rejects.toThrow(/^«No collection configured/);
  });

  it("progress rows", () => {
    const panel = buildStatusPanel(["1"], { 1: "A" });
    const row = panel.element.querySelector("li");
    expect(row.textContent).toBe("A — «Waiting…»");
    panel.update("1", "minted");
    expect(row.textContent).toBe("A — «Minted»");
    panel.update("1", "error", "Boom");
    expect(row.textContent).toBe("A — «Failed»: Boom");
  });

  it("the heading counts posts in the right plural", () => {
    expect(buildStatusPanel(["1"], {}).element.textContent).toContain("«Tokenizing 1 post with Medialane…»");
    expect(buildStatusPanel(["1", "2"], {}).element.textContent).toContain("«Tokenizing 2 posts with Medialane…»");
  });

  it("closing summary and confirmation", () => {
    expect(summaryLine(2, 1)).toBe("«2 minted, 1 failed.»");
    expect(confirmMessage(1)).toBe("«Tokenize 1 post on Medialane? This mints it on-chain and can't be undone.»");
    expect(confirmMessage(3)).toBe("«Tokenize 3 posts on Medialane? This mints them on-chain and can't be undone.»");
  });
});
