import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch, resumeMint } from "./mint-flow.js";
import { getTokenizeData } from "./api.js";
import { assertSiteWallet } from "./site-wallet.js";
import { __ } from "@wordpress/i18n";

export async function tokenizePost(postId) {
  const data = window.tokenizeContentData;

  const licenseSelect = document.getElementById("tokenize-content-license");
  const licenseCustom = document.getElementById("tokenize-content-license-custom");
  const license = licenseSelect.value === "Custom" ? licenseCustom.value : licenseSelect.value;

  const { address, account } = await connectWallet();
  assertSiteWallet(address, data.walletAddress);
  const { posts } = await getTokenizeData([postId]);
  const post = posts[postId];
  if (!post || post.error) {
    throw new Error(post ? post.error : __("No data was returned for this post.", "tokenize-content"));
  }

  const chosen = document.getElementById("tokenize-content-collection")?.selectedOptions[0];
  const collectionId = chosen ? chosen.dataset.collectionId : post.collectionId;
  const collectionContract = chosen ? chosen.value : post.collectionContract;
  if (!collectionId) {
    throw new Error(__("No collection configured.", "tokenize-content"));
  }

  const entry = await prepareMint({
    postId, title: post.title, body: post.description, hasImage: post.hasImage, license, licenses: data.licenses,
    aiPolicy: data.aiPolicyDefault, address, collectionId, authorEmail: post.authorEmail,
  });
  const [result] = await executeMintBatch({ account, address, collectionContract, entries: [entry] });
  if (result.error) {
    throw new Error(result.error);
  }
  return result.txHash;
}

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("tokenize-content-tokenize-btn");
  if (btn) {
    btn.addEventListener("click", async () => {
      const postId = document.getElementById("tokenize-content-metabox").dataset.postId;
      btn.disabled = true;
      btn.textContent = __("Tokenizing…", "tokenize-content");
      try {
        await tokenizePost(postId);
        location.reload();
      } catch (err) {
        btn.disabled = false;
        btn.textContent = __("Tokenize Post", "tokenize-content");
        alert(err.message || __("Something went wrong", "tokenize-content"));
      }
    });
  }

  const checkBtn = document.getElementById("tokenize-content-check-btn");
  if (checkBtn) {
    checkBtn.addEventListener("click", async () => {
      checkBtn.disabled = true;
      checkBtn.textContent = __("Checking…", "tokenize-content");
      await resumeMint({
        postId: document.getElementById("tokenize-content-metabox").dataset.postId,
        txHash: checkBtn.dataset.txHash,
        contract: checkBtn.dataset.contract,
        license: checkBtn.dataset.license,
        index: Number(checkBtn.dataset.index),
      });
      location.reload();
    });
  }

  const licenseSelect = document.getElementById("tokenize-content-license");
  const licenseCustom = document.getElementById("tokenize-content-license-custom");
  if (licenseSelect) {
    licenseSelect.addEventListener("change", () => {
      licenseCustom.style.display = licenseSelect.value === "Custom" ? "block" : "none";
    });
  }
});
