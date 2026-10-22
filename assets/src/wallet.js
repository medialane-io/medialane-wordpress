import { __ } from "@wordpress/i18n";
import { getStarknet } from "get-starknet-core";
import { RpcProvider, WalletAccount, stark, hash } from "starknet";
import { rpcConfig } from "./api.js";
import { chooseWallet } from "./wallet-picker.js";

export function createProvider() {
  return new RpcProvider(rpcConfig());
}

export async function connectWallet() {
  const wallets = await getStarknet().getAvailableWallets();
  if (!wallets || wallets.length === 0) {
    throw new Error(__("No Starknet wallet found. Install Ready or Braavos.", "tokenize-content"));
  }
  const wallet = await chooseWallet(wallets);
  const account = await WalletAccount.connect(createProvider(), wallet);
  if (!account?.address) {
    throw new Error(__("Wallet did not return an address.", "tokenize-content"));
  }
  return { address: account.address, account };
}

export async function signTypedData(account, typedData) {
  const signature = await account.signMessage(typedData);
  return stark.signatureToHexArray(signature);
}

export async function waitForConfirmation(txHash, provider = createProvider()) {
  const receipt = await provider.waitForTransaction(txHash);
  if (receipt.isReverted && receipt.isReverted()) {
    throw new Error(receipt.value?.revert_reason || __("Transaction reverted", "tokenize-content"));
  }
  return receipt;
}

const TRANSFER_SELECTOR = BigInt(hash.getSelectorFromName("Transfer"));

function sameFelt(a, b) {
  if (a === undefined) return false;
  try {
    return BigInt(a) === BigInt(b);
  } catch {
    return false;
  }
}

export function mintedTokenIdsFromReceipt(receipt, contract) {
  const tokenIds = [];
  for (const event of receipt.events ?? []) {
    if (!sameFelt(event.from_address, contract)) continue;
    const keys = event.keys ?? [];
    if (!sameFelt(keys[0], TRANSFER_SELECTOR)) continue;
    if (keys.length !== 5 || !sameFelt(keys[1], 0n)) continue;
    tokenIds.push((BigInt(keys[3]) + (BigInt(keys[4]) << 128n)).toString());
  }
  return tokenIds;
}
