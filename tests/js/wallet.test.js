import { describe, it, expect, vi, beforeEach } from "vitest";

const getAvailableWallets = vi.fn();
const walletAccountConnect = vi.fn();

vi.mock("get-starknet-core", () => ({
  getStarknet: () => ({ getAvailableWallets }),
}));
const chooseWallet = vi.fn();
vi.mock("../../assets/src/wallet-picker.js", () => ({ chooseWallet: (...args) => chooseWallet(...args) }));
vi.mock("starknet", () => ({
  RpcProvider: class { constructor(options) { this.options = options; } },
  WalletAccount: { connect: (...args) => walletAccountConnect(...args) },
  stark: { signatureToHexArray: (sig) => sig },
  hash: { getSelectorFromName: (name) => (name === "Transfer" ? "0x1" : "0x2") },
}));

const {
  connectWallet, signTypedData, waitForConfirmation, mintedTokenIdsFromReceipt,
} = await import("../../assets/src/wallet.js");

describe("connectWallet", () => {
  beforeEach(() => {
    global.window = { tokenizeContentData: { restUrl: "/wp-json/tokenize-content/v1", nonce: "abc" } };
    getAvailableWallets.mockReset();
    walletAccountConnect.mockReset();
    chooseWallet.mockReset().mockImplementation(async (wallets) => wallets[0]);
  });

  it("connects the wallet the person chose when several are installed", async () => {
    const wallets = [{ id: "argentX" }, { id: "braavos" }];
    getAvailableWallets.mockResolvedValue(wallets);
    chooseWallet.mockResolvedValue(wallets[1]);
    walletAccountConnect.mockResolvedValue({ address: "0xabc" });

    await connectWallet();

    expect(chooseWallet).toHaveBeenCalledWith(wallets);
    expect(walletAccountConnect).toHaveBeenCalledWith(expect.anything(), wallets[1]);
  });

  it("connects nothing when the person cancels the choice", async () => {
    getAvailableWallets.mockResolvedValue([{ id: "a" }, { id: "b" }]);
    chooseWallet.mockRejectedValue(new Error("No wallet selected."));

    await expect(connectWallet()).rejects.toThrow("No wallet selected.");
    expect(walletAccountConnect).not.toHaveBeenCalled();
  });

  it("reads the chain through the site's own proxy, not a public node", async () => {
    getAvailableWallets.mockResolvedValue([{ id: "argentX" }]);
    walletAccountConnect.mockResolvedValue({ address: "0xabc" });

    await connectWallet();

    const [provider] = walletAccountConnect.mock.calls[0];
    expect(provider.options).toEqual({ nodeUrl: "/wp-json/tokenize-content/v1/rpc", headers: { "X-WP-Nonce": "abc" } });
  });

  it("throws when no wallet extension is available", async () => {
    getAvailableWallets.mockResolvedValue([]);
    await expect(connectWallet()).rejects.toThrow("No Starknet wallet found. Install Ready or Braavos.");
    expect(walletAccountConnect).not.toHaveBeenCalled();
  });

  it("connects the single available wallet via WalletAccount.connect", async () => {
    const wallet = { id: "argentX", name: "Ready" };
    getAvailableWallets.mockResolvedValue([wallet]);
    walletAccountConnect.mockResolvedValue({ address: "0xabc" });

    const result = await connectWallet();

    expect(walletAccountConnect).toHaveBeenCalledWith(expect.anything(), wallet);
    expect(result).toEqual({ address: "0xabc", account: { address: "0xabc" } });
  });

  it("throws when the connected account has no address", async () => {
    getAvailableWallets.mockResolvedValue([{ id: "argentX" }]);
    walletAccountConnect.mockResolvedValue({ address: undefined });
    await expect(connectWallet()).rejects.toThrow("Wallet did not return an address.");
  });
});

describe("signTypedData", () => {
  it("signs the typed data and normalizes the signature to a hex array", async () => {
    const signMessage = vi.fn().mockResolvedValue(["0x1", "0x2"]);
    const account = { signMessage };
    const typedData = { domain: {}, message: {} };

    const signature = await signTypedData(account, typedData);

    expect(signMessage).toHaveBeenCalledWith(typedData);
    expect(signature).toEqual(["0x1", "0x2"]);
  });
});

describe("waitForConfirmation", () => {
  it("returns the receipt when the transaction succeeds", async () => {
    const receipt = { isReverted: () => false };
    const provider = { waitForTransaction: vi.fn().mockResolvedValue(receipt) };
    await expect(waitForConfirmation("0x123", provider)).resolves.toBe(receipt);
  });

  it("throws when the transaction reverted, even though it has a tx hash", async () => {
    const receipt = { isReverted: () => true, value: { revert_reason: "insufficient balance" } };
    const provider = { waitForTransaction: vi.fn().mockResolvedValue(receipt) };
    await expect(waitForConfirmation("0x123", provider)).rejects.toThrow("insufficient balance");
  });
});

describe("mintedTokenIdsFromReceipt", () => {
  it("extracts one token id per Transfer(from=0) event on the given contract, in event order", () => {
    const receipt = {
      events: [
        { from_address: "0xc011", keys: ["0x1", "0x0", "0xabc", "0x5", "0x0"] },
        { from_address: "0xd0e1", keys: ["0x1", "0x0", "0xdef", "0x9", "0x0"] },
        { from_address: "0xc011", keys: ["0x1", "0x0", "0xabc", "0x6", "0x0"] },
      ],
    };
    expect(mintedTokenIdsFromReceipt(receipt, "0xc011")).toEqual(["5", "6"]);
  });

  it("ignores non-mint transfers (from a real address, not the zero address)", () => {
    const receipt = {
      events: [
        { from_address: "0xc011", keys: ["0x1", "0xbad0", "0xabc", "0x5", "0x0"] },
      ],
    };
    expect(mintedTokenIdsFromReceipt(receipt, "0xc011")).toEqual([]);
  });
});
