import { __, sprintf } from "@wordpress/i18n";

let session = "";

export function setSession(token) {
  session = token || "";
}

export class ApiError extends Error {
  constructor(message, { status, code } = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = code;
  }
}

function base() {
  return (window.tokenizeContentData && window.tokenizeContentData.restUrl) || "/wp-json/tokenize-content/v1";
}

function nonce() {
  return (window.tokenizeContentData && window.tokenizeContentData.nonce) || "";
}

export function rpcConfig() {
  return { nodeUrl: `${base()}/rpc`, headers: { "X-WP-Nonce": nonce() } };
}

async function request(path, opts = {}) {
  const res = await fetch(`${base()}${path}`, {
    ...opts,
    headers: {
      "X-WP-Nonce": nonce(),
      ...(session ? { "X-Tokenize-Session": session } : {}),
      ...(opts.body ? { "Content-Type": "application/json" } : {}),
      ...(opts.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new ApiError(body.message || body.error || sprintf(__("Request failed (%d)", "tokenize-content"), res.status), { status: res.status, code: body.code });
  }
  return body;
}

function post(path, payload = {}) {
  return request(path, { method: "POST", body: JSON.stringify(payload) });
}

export const siwsNonce = (walletAddress) => post("/auth/siws/nonce", { walletAddress });
export const siwsVerify = ({ walletAddress, nonce: value, signature }) => post("/auth/siws/verify", { walletAddress, nonce: value, signature });

export const getTokenizeData = (ids) => post("/tokenize-data", { ids });
export const uploadJson = (payload) => post("/metadata/upload", payload);
export const uploadFeaturedImage = (postId) => post(`/posts/${postId}/featured-image`);
export const provisionRecipientWallet = ({ email }) => post("/business/provisioning", { email });
export const createMintIntent = (params) => post("/intents/mint", params);
export const buildSponsoredInvoke = ({ intentIds }) => post("/paymaster/invoke/build", { intentIds });
export const executeSponsoredInvoke = ({ intentIds, typedData, signature }) => post("/paymaster/invoke/execute", { intentIds, typedData, signature });

export const createCollectionIntent = (params) => post("/intents/create-collection", params);
export const syncCollectionTx = (txHash) => post("/collections/sync-tx", { txHash });
export const getCollectionsByOwner = (owner) => request(`/collections?owner=${encodeURIComponent(owner)}`, { method: "GET" });
export const saveCollectionEntry = ({ contract, label }) => post("/settings/collections", { contract, label });
export const saveCategoryMap = (map) => post("/settings/category-map", { map });
export const saveWalletAddress = (address) => post("/settings/wallet", { address });

export const markMinting = (postId) => post(`/posts/${postId}/minting`);
export const markBroadcast = (postId, data) => post(`/posts/${postId}/broadcast`, data);
export const markMinted = (postId, data) => post(`/posts/${postId}/minted`, data);
export const markError = (postId, message) => post(`/posts/${postId}/error`, { message });
