import { describe, it, expect } from "vitest";
import { sameAddress, assertSiteWallet } from "../../assets/src/site-wallet.js";

describe("sameAddress", () => {
  it("ignores case and leading zeros", () => {
    expect(sameAddress("0x0abc", "0xABC")).toBe(true);
    expect(sameAddress("0x00000000000000000000000000000000000000000000000000000000000abc", "0xabc")).toBe(true);
  });

  it("tells different addresses apart", () => {
    expect(sameAddress("0xabc", "0xabd")).toBe(false);
  });
});

describe("assertSiteWallet", () => {
  it("passes when the connected wallet is the saved one in a different format", () => {
    expect(() => assertSiteWallet("0xabc", "0x00ABC")).not.toThrow();
  });

  it("passes when no wallet is saved yet", () => {
    expect(() => assertSiteWallet("0xabc", "")).not.toThrow();
    expect(() => assertSiteWallet("0xabc", undefined)).not.toThrow();
  });

  it("throws when the connected wallet is not the saved one", () => {
    expect(() => assertSiteWallet("0xabc", "0xdef")).toThrow("not the wallet saved for this site");
  });
});
