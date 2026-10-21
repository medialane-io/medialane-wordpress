function base() {
  return (window.tokenizeContentData && window.tokenizeContentData.restUrl) || "/wp-json/tokenize-content/v1";
}

function nonce() {
  return (window.tokenizeContentData && window.tokenizeContentData.nonce) || "";
}

async function request(path, opts = {}) {
  const res = await fetch(`${base()}${path}`, {
    ...opts,
    headers: {
      "X-WP-Nonce": nonce(),
      ...(opts.body && !(opts.body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
      ...(opts.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(body.message || body.error || `Medialane request failed (${res.status})`);
  }
  return body;
}

export function uploadJson(payload) {
  return request("/metadata/upload", { method: "POST", body: JSON.stringify(payload) });
}

export function uploadFile(file) {
  const form = new FormData();
  form.append("file", file, file.name);
  return request("/metadata/upload-file", { method: "POST", body: form });
}

export function getTokenizeData(ids) {
  return request("/tokenize-data", { method: "POST", body: JSON.stringify({ ids }) });
}

export function createCollectionIntent(params) {
  return request("/intents/create-collection", { method: "POST", body: JSON.stringify(params) });
}

export function createMintIntent(params) {
  return request("/intents/mint", { method: "POST", body: JSON.stringify(params) });
}

export function syncCollectionTx(txHash) {
  return request("/collections/sync-tx", { method: "POST", body: JSON.stringify({ txHash }) });
}

export function getCollectionsByOwner(owner) {
  return request(`/collections?owner=${encodeURIComponent(owner)}`, { method: "GET" });
}

export function saveCollectionEntry({ contract, label }) {
  return request("/settings/collections", { method: "POST", body: JSON.stringify({ contract, label }) });
}

export function saveCategoryMap(map) {
  return request("/settings/category-map", { method: "POST", body: JSON.stringify({ map }) });
}

export function saveWalletAddress(address) {
  return request("/settings/wallet", { method: "POST", body: JSON.stringify({ address }) });
}

export function buildSponsoredInvoke(params) {
  return request("/paymaster/invoke/build", { method: "POST", body: JSON.stringify(params) });
}

export function executeSponsoredInvoke(params) {
  return request("/paymaster/invoke/execute", { method: "POST", body: JSON.stringify(params) });
}

export function buildSponsoredDeploy(params) {
  return request("/paymaster/deploy/build", { method: "POST", body: JSON.stringify(params) });
}

export function provisionRecipientWallet(params) {
  return request("/business/provisioning", { method: "POST", body: JSON.stringify(params) });
}
