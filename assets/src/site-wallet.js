function canonical(address) {
  const value = String(address || "").trim().toLowerCase();
  if (!value.startsWith("0x")) return value;
  const digits = value.slice(2).replace(/^0+/, "");
  return `0x${digits || "0"}`;
}

export function sameAddress(a, b) {
  return canonical(a) === canonical(b);
}

export function assertSiteWallet(connected, saved) {
  if (saved && !sameAddress(connected, saved)) {
    throw new Error("The connected wallet is not the wallet saved for this site. Switch to the saved wallet and try again.");
  }
}
