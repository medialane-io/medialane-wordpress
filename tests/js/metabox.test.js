import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/wallet.js", () => ({ connectWallet: vi.fn() }));
vi.mock("../../assets/src/mint-flow.js", () => ({ prepareMint: vi.fn(), executeMintBatch: vi.fn(), resumeMint: vi.fn() }));
vi.mock("../../assets/src/api.js", () => ({ getTokenizeData: vi.fn() }));
vi.mock("../../assets/src/credits.js", () => ({ assertCredits: vi.fn() }));
vi.mock("../../assets/src/editor.js", () => ({ hasUnsavedChanges: vi.fn() }));

const wallet = await import("../../assets/src/wallet.js");
const flow = await import("../../assets/src/mint-flow.js");
const api = await import("../../assets/src/api.js");
const credits = await import("../../assets/src/credits.js");
const editor = await import("../../assets/src/editor.js");
const { tokenizePost, showError } = await import("../../assets/src/metabox.js");

function leave() {
  const event = new Event("beforeunload", { cancelable: true });
  window.dispatchEvent(event);
  return event.defaultPrevented;
}

beforeEach(() => {
  vi.resetAllMocks();
  document.body.innerHTML = `
    <select id="tokenize-content-license"><option value="CC BY">CC BY</option></select>
    <textarea id="tokenize-content-license-custom"></textarea>`;
  window.tokenizeContentData = { licenseDefault: "CC BY", aiPolicyDefault: "Allowed", walletAddress: "0xowner", licenses: {} };
  editor.hasUnsavedChanges.mockReturnValue(false);
  credits.assertCredits.mockResolvedValue(undefined);
  wallet.connectWallet.mockResolvedValue({ address: "0xowner", account: {} });
  api.getTokenizeData.mockResolvedValue({ posts: { 7: { title: "T", description: "d", collectionId: "7", collectionContract: "0xcol", authorEmail: "a@example.org" } } });
  flow.prepareMint.mockResolvedValue({ postId: 7, license: "CC BY", intentId: "i1" });
  flow.executeMintBatch.mockResolvedValue([{ postId: 7, txHash: "0xtx" }]);
});

describe("tokenizePost", () => {
  it("refuses while the editor has unsaved changes, before the wallet or the account is touched", async () => {
    editor.hasUnsavedChanges.mockReturnValue(true);

    await expect(tokenizePost("7")).rejects.toThrow("Save the post before tokenizing. The token is made from the saved version.");
    expect(credits.assertCredits).not.toHaveBeenCalled();
    expect(wallet.connectWallet).not.toHaveBeenCalled();
  });

  it("stops when there are no credits, before the wallet is asked", async () => {
    credits.assertCredits.mockRejectedValue(new Error("No credits left."));

    await expect(tokenizePost("7")).rejects.toThrow("No credits left.");
    expect(wallet.connectWallet).not.toHaveBeenCalled();
  });

  it("keeps the tab from closing while it runs and lets it go afterwards", async () => {
    let during;
    flow.executeMintBatch.mockImplementation(async () => {
      during = leave();
      return [{ postId: 7, txHash: "0xtx" }];
    });

    await expect(tokenizePost("7")).resolves.toBe("0xtx");

    expect(during).toBe(true);
    expect(leave()).toBe(false);
  });

  it("lets the tab go when the run fails", async () => {
    flow.executeMintBatch.mockResolvedValue([{ postId: 7, error: "reverted" }]);

    await expect(tokenizePost("7")).rejects.toThrow("reverted");
    expect(leave()).toBe(false);
  });
});

describe("showError", () => {
  beforeEach(() => {
    document.body.innerHTML = '<div class="tokenize-content-metabox-body"><div class="notice notice-error inline tokenize-content-error"><p>Old</p></div></div>';
  });

  it("shows the reason in the panel as text, in place of an earlier one", () => {
    showError("<b>Reverted</b>");

    const notices = document.querySelectorAll(".tokenize-content-error");
    expect(notices).toHaveLength(1);
    expect(notices[0].textContent).toBe("<b>Reverted</b>");
    expect(notices[0].querySelector("b")).toBeNull();
  });

  it("does nothing on a page without the panel", () => {
    document.body.innerHTML = "";

    expect(() => showError("Reverted")).not.toThrow();
  });
});
