import {
  signTypedData, waitForConfirmation, generateInterimKeypair,
  signDeploymentWithInterimKey, mintedTokenIdsFromReceipt,
} from "./wallet.js";
import {
  uploadJson, createMintIntent, buildSponsoredInvoke, executeSponsoredInvoke,
  buildSponsoredDeploy, provisionRecipientWallet,
} from "./api.js";
import { buildLicenseAttributes } from "./license.js";

async function postJson(restUrl, nonce, path, body) {
  await fetch(`${restUrl}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
    body: JSON.stringify(body),
  });
}

function markMinting(restUrl, nonce, postId) {
  return postJson(restUrl, nonce, `/posts/${postId}/minting`, {});
}

function markBroadcast(restUrl, nonce, postId, data) {
  return postJson(restUrl, nonce, `/posts/${postId}/broadcast`, data);
}

function markMinted(restUrl, nonce, postId, data) {
  return postJson(restUrl, nonce, `/posts/${postId}/minted`, data);
}

function markError(restUrl, nonce, postId, message) {
  return postJson(restUrl, nonce, `/posts/${postId}/error`, { message });
}

function randomSalt() {
  return crypto.getRandomValues(new Uint8Array(16)).reduce((s, b) => s + b.toString(16).padStart(2, "0"), "");
}

// Deploys (or reuses, per business-provisioning's own lookup) a wallet tied
// to the post author's registered email, so the minted asset lands there
// instead of in whichever wallet the site admin happens to have connected.
async function resolveAuthorWallet(authorEmail) {
  const { address, privateKey, publicKey } = generateInterimKeypair();
  const buildRes = await buildSponsoredDeploy({ ownerPubkey: publicKey, ownerAddress: address });
  const signature = await signDeploymentWithInterimKey(privateKey, address, buildRes.data.typedData);
  const provisionRes = await provisionRecipientWallet({
    recipientScheme: "email",
    recipientValue: authorEmail,
    interimOwnerPubkey: publicKey,
    derivationSalt: randomSalt(),
    deployment: { typedData: buildRes.data.typedData, signature, deployment: buildRes.data.deployment },
  });
  return provisionRes.data.walletAddress;
}

// Does not touch the chain or post meta — callers batch these together before executing.
export async function prepareMint({ postId, title, body, image, license, aiPolicy, address, collectionContract, authorEmail }) {
  if (!authorEmail) {
    throw new Error(`Post ${postId}'s author has no registered email. Assign a valid author before tokenizing.`);
  }
  const recipient = await resolveAuthorWallet(authorEmail);
  const metaRes = await uploadJson({
    name: title, description: body, image: image || undefined,
    attributes: buildLicenseAttributes(license, aiPolicy),
  });
  const intentRes = await createMintIntent({
    owner: address,
    collectionId: collectionContract,
    recipient,
    tokenUri: metaRes.data.url,
    royaltyBps: 0,
  });
  return { postId, license, calls: intentRes.data.calls };
}

// One on-chain transaction covers every entry, so they succeed or fail together.
export async function executeMintBatch({ restUrl, nonce, account, address, collectionContract, entries }) {
  if (!collectionContract) {
    throw new Error("No collection configured. Connect a wallet in Medialane Settings first.");
  }
  await Promise.all(entries.map((e) => markMinting(restUrl, nonce, e.postId)));

  const calls = entries.flatMap((e) => e.calls);
  try {
    const buildRes = await buildSponsoredInvoke({ userAddress: address, calls });
    const signature = await signTypedData(account, buildRes.data.typedData);
    const execRes = await executeSponsoredInvoke({ userAddress: address, typedData: buildRes.data.typedData, signature, calls });
    const txHash = execRes.data.transactionHash;

    await Promise.all(entries.map((e, i) =>
      markBroadcast(restUrl, nonce, e.postId, { txHash, contract: collectionContract, license: e.license, index: i })
    ));

    const receipt = await waitForConfirmation(txHash);
    const tokenIds = mintedTokenIdsFromReceipt(receipt, collectionContract);

    await Promise.all(entries.map((e, i) =>
      markMinted(restUrl, nonce, e.postId, { tokenId: tokenIds[i] || "", txHash, contract: collectionContract, license: e.license })
    ));
    return entries.map((e) => ({ postId: e.postId, txHash }));
  } catch (err) {
    const message = err.message || "Something went wrong";
    await Promise.all(entries.map((e) => markError(restUrl, nonce, e.postId, message)));
    return entries.map((e) => ({ postId: e.postId, error: message }));
  }
}

export async function resumeMint({ restUrl, nonce, postId, txHash, contract, license, index }) {
  try {
    const receipt = await waitForConfirmation(txHash);
    const tokenIds = mintedTokenIdsFromReceipt(receipt, contract);
    await markMinted(restUrl, nonce, postId, { tokenId: tokenIds[index] || "", txHash, contract, license });
    return { postId, txHash };
  } catch (err) {
    const message = err.message || "Something went wrong";
    await markError(restUrl, nonce, postId, message);
    return { postId, error: message };
  }
}
