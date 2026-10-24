import { waitForConfirmation, mintedTokenIdsFromReceipt } from "./wallet.js";
import {
  uploadJson, uploadFeaturedImage, createMintIntent, provisionRecipientWallet,
  markMinting, markMinted, markError,
} from "./api.js";
import { sponsor } from "./sponsor.js";
import { buildPostMetadata } from "./metadata.js";
import { uploadSizeError } from "@medialane/sdk";
import { __, sprintf } from "@wordpress/i18n";

const saveFailed = (txHash) => sprintf(__("Minted on chain, but this site could not save it. Transaction: %s", "tokenize-content"), txHash);
const notMinted = (txHash) => sprintf(__("Transaction %s did not mint this post.", "tokenize-content"), txHash);
const wentWrong = () => __("Something went wrong", "tokenize-content");

export { markError };

export async function prepareMint({ postId, title, body, content, author, publisher, url, hasImage, license, licenses, aiPolicy, address, collectionId, authorEmail }) {
  if (!authorEmail) {
    throw new Error(sprintf(__("Post %d's author has no registered email. Assign a valid author before tokenizing.", "tokenize-content"), postId));
  }
  const fields = { title, body, content, author, publisher, url, license, licenses, aiPolicy };
  const sizeError = uploadSizeError(new Blob([JSON.stringify(buildPostMetadata({ ...fields, imageUri: null }))]), "document");
  if (sizeError) throw new Error(sizeError);
  const provisioned = await provisionRecipientWallet({ email: authorEmail });
  const recipient = provisioned.data.walletAddress;

  const image = hasImage ? (await uploadFeaturedImage(postId)).data.url : null;
  const metadata = await uploadJson(buildPostMetadata({ ...fields, imageUri: image }));

  const intent = await createMintIntent({
    owner: address,
    recipient,
    collectionId,
    tokenUri: metadata.data.url,
    royaltyBps: 0,
  });
  return { postId, license, intentId: intent.data.id };
}

async function settle({ postId, license }, txHash, contract, tokenId) {
  if (!tokenId) {
    const message = notMinted(txHash);
    await markError(postId, message);
    return { postId, txHash, error: message };
  }
  try {
    await markMinted(postId, { tokenId, txHash, contract, license });
  } catch {
    return { postId, txHash, error: saveFailed(txHash) };
  }
  return { postId, txHash };
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
    const posts = entries.map((e) => ({ id: e.postId, contract: collectionContract, license: e.license }));
    const executed = await sponsor(account, address, entries.map((e) => e.intentId), posts);
    txHash = executed.transactionHash;
  } catch (err) {
    return failAll(entries, err.message || wentWrong());
  }

  let tokenIds;
  try {
    const receipt = await waitForConfirmation(txHash);
    tokenIds = mintedTokenIdsFromReceipt(receipt, collectionContract);
  } catch (err) {
    return failAll(entries, err.message || wentWrong());
  }

  return Promise.all(entries.map((e, i) => settle(e, txHash, collectionContract, tokenIds[i])));
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
  return settle({ postId, license }, txHash, contract, tokenIds[index]);
}
