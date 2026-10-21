import { describe, it, expect, beforeEach } from "vitest";
import { chooseWallet } from "../../assets/src/wallet-picker.js";

const wallets = [
  { id: "argentX", name: "Ready" },
  { id: "braavos", name: "Braavos" },
];

const buttons = () => Array.from(document.querySelectorAll("[data-tokenize-content-wallet-picker] button"));

describe("chooseWallet", () => {
  beforeEach(() => {
    document.body.innerHTML = "";
  });

  it("returns the only wallet without asking", async () => {
    await expect(chooseWallet([wallets[0]])).resolves.toBe(wallets[0]);
    expect(buttons()).toHaveLength(0);
  });

  it("offers every installed wallet by name when there are several", () => {
    chooseWallet(wallets).catch(() => {});

    expect(buttons().map((b) => b.textContent)).toEqual(["Ready", "Braavos", "Cancel"]);
  });

  it("resolves the wallet that was clicked and closes the chooser", async () => {
    const choice = chooseWallet(wallets);

    buttons()[1].click();

    await expect(choice).resolves.toBe(wallets[1]);
    expect(document.querySelector("[data-tokenize-content-wallet-picker]")).toBeNull();
  });

  it("rejects when cancelled and closes the chooser", async () => {
    const choice = chooseWallet(wallets);

    buttons().at(-1).click();

    await expect(choice).rejects.toThrow("No wallet selected.");
    expect(document.querySelector("[data-tokenize-content-wallet-picker]")).toBeNull();
  });

  it("rejects when Escape is pressed", async () => {
    const choice = chooseWallet(wallets);

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

    await expect(choice).rejects.toThrow("No wallet selected.");
  });

  it("falls back to the wallet's id when it has no name", () => {
    chooseWallet([{ id: "one" }, { id: "two", name: "Two" }]).catch(() => {});

    expect(buttons().map((b) => b.textContent).slice(0, 2)).toEqual(["one", "Two"]);
  });
});
