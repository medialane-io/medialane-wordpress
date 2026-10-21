import { signTypedData, waitForConfirmation, mintedTokenIdsFromReceipt } from "./wallet.js";
import {
  uploadJson, uploadFeaturedImage, createMintIntent, provisionRecipientWallet,
  buildSponsoredInvoke, executeSponsoredInvoke,
  markMinting, markBroadcast, markMinted, markError,
} from "./api.js";
import { ensureSession, forgetSession } from "./session.js";
import { buildLicenseAttributes } from "./license.js";
import { __, sprintf } from "@wordpress/i18n";

const SIGN_IN_REQUIRED = "invalid_request_auth";
const saveFailed = () => __("Minted on chain, but this site could not save it. Use Check status on the post.", "tokenize-content");
const wentWrong = () => __("Something went wrong", "tokenize-content");

export { markError };

export async function prepareMint({ postId, title, body, hasImage, license, licenses, aiPolicy, address, collectionId, authorEmail }) {
  if (!authorEmail) {
    throw new Error(sprintf(__("Post %d's author has no registered email. Assign a valid author before tokenizing.", "tokenize-content"), postId));
  }
  const provisioned = await provisionRecipientWallet({ email: authorEmail });
  const recipient = provisioned.data.walletAddress;

  const image = hasImage ? (await uploadFeaturedImage(postId)).data.url : null;
  const metadata = await uploadJson({
    name: title,
    description: body,
    image: image || undefined,
    attributes: buildLicenseAttributes(license, aiPolicy, licenses),
  });

  const intent = await createMintIntent({
    owner: address,
    recipient,
    collectionId,
    tokenUri: metadata.data.url,
    royaltyBps: 0,
  });
  return { postId, license, intentId: intent.data.id };
}

async function sponsor(account, address, intentIds) {
  const attempt = async () => {
    await ensureSession(account, address);
    const built = await buildSponsoredInvoke({ intentIds });
    const signature = await signTypedData(account, built.typedData);
    return executeSponsoredInvoke({ intentIds, typedData: built.typedData, signature });
  };
  try {
    return await attempt();
  } catch (err) {
    if (err.code !== SIGN_IN_REQUIRED) throw err;
    forgetSession();
    return attempt();
  }
}

async function failAll(entries, message) {
  await Promise.all(entries.map((e) => markError(e.postId, message)));
  return entries.map((e) => ({ postId: e.postId, error: message }));
}

export async function executeMintBatch({ account, address, collectionContract, entries }) {
  if (!collectionContract) {
    throw new Error(__("No collection configured. Connect a wallet in Medialane Settings first.", "tokenize-content"));
  }
  await Promise.all(entries.map((e) => markMinting(e.postId)));

  let txHash;
  try {
    const executed = await sponsor(account, address, entries.map((e) => e.intentId));
    txHash = executed.transactionHash;
  } catch (err) {
    return failAll(entries, err.message || wentWrong());
  }

  await Promise.allSettled(entries.map((e, i) =>
    markBroadcast(e.postId, { txHash, contract: collectionContract, license: e.license, index: i })
  ));

  let tokenIds;
  try {
    const receipt = await waitForConfirmation(txHash);
    tokenIds = mintedTokenIdsFromReceipt(receipt, collectionContract);
  } catch (err) {
    return failAll(entries, err.message || wentWrong());
  }

  const saved = await Promise.allSettled(entries.map((e, i) =>
    markMinted(e.postId, { tokenId: tokenIds[i] || "", txHash, contract: collectionContract, license: e.license })
  ));
  return entries.map((e, i) =>
    saved[i].status === "fulfilled" ? { postId: e.postId, txHash } : { postId: e.postId, txHash, error: saveFailed() }
  );
}

export async function resumeMint({ postId, txHash, contract, license, index }) {
  let tokenIds;
  try {
    const receipt = await waitForConfirmation(txHash);
    tokenIds = mintedTokenIdsFromReceipt(receipt, contract);
  } catch (err) {
    const message = err.message || wentWrong();
    await markError(postId, message);
    return { postId, error: message };
  }
  try {
    await markMinted(postId, { tokenId: tokenIds[index] || "", txHash, contract, license });
  } catch {
    return { postId, txHash, error: saveFailed() };
  }
  return { postId, txHash };
}
