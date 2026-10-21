import { siwsNonce, siwsVerify, setSession } from "./api.js";
import { signTypedData } from "./wallet.js";
import { sameAddress } from "./site-wallet.js";

let current = { address: "", token: "" };

export function forgetSession() {
  current = { address: "", token: "" };
  setSession("");
}

export async function ensureSession(account, address) {
  if (current.token && sameAddress(current.address, address)) {
    setSession(current.token);
    return;
  }
  const { nonce, typedData } = await siwsNonce(address);
  const signature = await signTypedData(account, typedData);
  const { token } = await siwsVerify({ walletAddress: address, nonce, signature });
  current = { address, token };
  setSession(token);
}
