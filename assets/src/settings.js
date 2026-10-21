import { connectWallet, executeCalls } from "./wallet.js";
import { assertSiteWallet, sameAddress } from "./site-wallet.js";
import { __ } from "@wordpress/i18n";
import {
  createCollectionIntent, syncCollectionTx, getCollectionsByOwner,
  saveCollectionEntry, saveCategoryMap, saveWalletAddress,
} from "./api.js";

export async function pollForNewCollection(owner, known, { attempts = 10, intervalMs = 3000 } = {}) {
  for (let i = 0; i < attempts; i++) {
    const body = await getCollectionsByOwner(owner).catch(() => null);
    const fresh = ((body && body.data) || []).find((c) => !known.some((k) => sameAddress(k, c.contractAddress)));
    if (fresh) return fresh.contractAddress;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

export async function createAndRegisterCollection(address, account, label, polling) {
  const existing = ((await getCollectionsByOwner(address)).data || []).map((c) => c.contractAddress);

  const intentRes = await createCollectionIntent({ owner: address, name: label, symbol: "POST" });
  const txHash = await executeCalls(account, intentRes.data && intentRes.data.calls);
  await syncCollectionTx(txHash).catch((err) => console.warn("Medialane: eager tx sync failed", err));

  const contract = await pollForNewCollection(address, existing, polling);
  if (contract) {
    await saveCollectionEntry({ contract, label });
  }
  return contract;
}

export function collectCategoryMap(rows) {
  const map = {};
  for (const row of rows) {
    if (row.value) map[row.categoryId] = row.value;
  }
  return map;
}

document.addEventListener("DOMContentLoaded", () => {
  const button = document.getElementById("tokenize-content-connect-wallet");
  if (!button) return;
  button.addEventListener("click", async () => {
    button.disabled = true;
    button.textContent = __("Connecting…", "tokenize-content");
    try {
      const { address } = await connectWallet();
      await saveWalletAddress(address);
      location.reload();
    } catch (err) {
      button.disabled = false;
      button.textContent = __("Connect Wallet", "tokenize-content");
      alert(err.message || __("Failed to connect wallet.", "tokenize-content"));
    }
  });

  const createCollectionBtn = document.getElementById("tokenize-content-create-collection");
  const newCollectionLabel = document.getElementById("tokenize-content-new-collection-label");
  if (createCollectionBtn && newCollectionLabel) {
    createCollectionBtn.addEventListener("click", async () => {
      const label = newCollectionLabel.value.trim();
      if (!label) {
        alert(__("Give the new collection a name first.", "tokenize-content"));
        return;
      }
      createCollectionBtn.disabled = true;
      createCollectionBtn.textContent = __("Creating…", "tokenize-content");
      try {
        const { address, account } = await connectWallet();
        assertSiteWallet(address, window.tokenizeContentData.walletAddress);
        await createAndRegisterCollection(address, account, label);
        location.reload();
      } catch (err) {
        createCollectionBtn.disabled = false;
        createCollectionBtn.textContent = __("Create Collection", "tokenize-content");
        alert(err.message || __("Failed to create collection.", "tokenize-content"));
      }
    });
  }

  const saveMapBtn = document.getElementById("tokenize-content-save-category-map");
  if (saveMapBtn) {
    saveMapBtn.addEventListener("click", async () => {
      const rows = Array.from(document.querySelectorAll(".tokenize-content-category-collection-select")).map((el) => ({
        categoryId: el.dataset.categoryId,
        value: el.value,
      }));
      const saved = __("Saved", "tokenize-content");
      saveMapBtn.disabled = true;
      saveMapBtn.textContent = __("Saving…", "tokenize-content");
      try {
        await saveCategoryMap(collectCategoryMap(rows));
        saveMapBtn.textContent = saved;
      } catch (err) {
        alert(err.message || __("Failed to save the category mapping.", "tokenize-content"));
      } finally {
        saveMapBtn.disabled = false;
        if (saveMapBtn.textContent !== saved) saveMapBtn.textContent = __("Save Mapping", "tokenize-content");
      }
    });
  }
});
