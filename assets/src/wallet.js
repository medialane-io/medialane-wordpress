import { getStarknet } from "get-starknet-core";
import { RpcProvider, WalletAccount, stark, hash } from "starknet";
import { rpcConfig } from "./api.js";

export function createProvider() {
  return new RpcProvider(rpcConfig());
}

export async function connectWallet() {
  const wallets = await getStarknet().getAvailableWallets();
  if (!wallets || wallets.length === 0) {
    throw new Error("No Starknet wallet found. Install Ready or Braavos.");
  }
  const account = await WalletAccount.connect(createProvider(), wallets[0]);
  if (!account?.address) {
    throw new Error("Wallet did not return an address.");
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
    throw new Error(receipt.value?.revert_reason || "Transaction reverted");
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

export async function executeCalls(account, calls) {
  if (!calls || calls.length === 0) {
    throw new Error("No calls to execute.");
  }
  const { transaction_hash } = await account.execute(calls);
  await waitForConfirmation(transaction_hash, account.channel?.provider);
  return transaction_hash;
}
