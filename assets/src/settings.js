import { connectWallet, executeCalls } from "./wallet.js";
import { assertSiteWallet, sameAddress } from "./site-wallet.js";
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
    button.textContent = "Connecting…";
    try {
      const { address } = await connectWallet();
      await saveWalletAddress(address);
      location.reload();
    } catch (err) {
      button.disabled = false;
      button.textContent = "Connect Wallet";
      alert(err.message || "Failed to connect wallet.");
    }
  });

  const createCollectionBtn = document.getElementById("tokenize-content-create-collection");
  const newCollectionLabel = document.getElementById("tokenize-content-new-collection-label");
  if (createCollectionBtn && newCollectionLabel) {
    createCollectionBtn.addEventListener("click", async () => {
      const label = newCollectionLabel.value.trim();
      if (!label) {
        alert("Give the new collection a name first.");
        return;
      }
      createCollectionBtn.disabled = true;
      createCollectionBtn.textContent = "Creating…";
      try {
        const { address, account } = await connectWallet();
        assertSiteWallet(address, window.tokenizeContentData.walletAddress);
        await createAndRegisterCollection(address, account, label);
        location.reload();
      } catch (err) {
        createCollectionBtn.disabled = false;
        createCollectionBtn.textContent = "Create Collection";
        alert(err.message || "Failed to create collection.");
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
      saveMapBtn.disabled = true;
      saveMapBtn.textContent = "Saving…";
      try {
        await saveCategoryMap(collectCategoryMap(rows));
        saveMapBtn.textContent = "Saved";
      } catch (err) {
        alert(err.message || "Failed to save the category mapping.");
      } finally {
        saveMapBtn.disabled = false;
        if (saveMapBtn.textContent !== "Saved") saveMapBtn.textContent = "Save Mapping";
      }
    });
  }
});
