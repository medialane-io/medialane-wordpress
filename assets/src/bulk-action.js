import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch } from "./mint-flow.js";
import { getTokenizeData } from "./api.js";

const MAX_BATCH_SIZE = 25;

function chunk(array, size) {
  const out = [];
  for (let i = 0; i < array.length; i += size) out.push(array.slice(i, i + size));
  return out;
}

// Posts can be routed to different collections (by category mapping in
// Settings), so a batch can't just be one collectionContract anymore —
// entries are grouped by their resolved collection first, then each
// group is chunked and executed as its own batch.
export async function tokenizeBulk(postIds, onProgress) {
  const data = window.tokenizeContentData;
  const { address, account } = await connectWallet();
  const { posts } = await getTokenizeData(postIds);

  const entriesByCollection = new Map();
  for (const postId of postIds) {
    const post = posts[postId];
    if (!post) continue;
    const collectionContract = post.collectionContract || data.collectionContract;
    if (!collectionContract) {
      throw new Error("No collection configured.");
    }
    onProgress && onProgress(postId, "preparing");
    const body = data.contentScope === "full" ? post.content : post.excerpt;
    const entry = await prepareMint({
      postId, title: post.title, body, image: post.image, license: data.licenseDefault, address,
      collectionContract, authorEmail: post.authorEmail,
      aiPolicy: data.aiPolicyDefault,
    });
    if (!entriesByCollection.has(collectionContract)) entriesByCollection.set(collectionContract, []);
    entriesByCollection.get(collectionContract).push(entry);
  }

  for (const [collectionContract, entries] of entriesByCollection) {
    for (const group of chunk(entries, MAX_BATCH_SIZE)) {
      group.forEach((e) => onProgress && onProgress(e.postId, "minting"));
      const results = await executeMintBatch({
        restUrl: data.restUrl, nonce: data.nonce, account, address,
        collectionContract, entries: group,
      });
      results.forEach((r) => onProgress && onProgress(r.postId, r.error ? "error" : "minted", r.error));
    }
  }
}

const STATUS_LABEL = {
  preparing: "Preparing…",
  minting: "Signing & minting…",
  minted: "Minted",
  error: "Failed",
};

export function buildStatusPanel(postIds, titles) {
  const panel = document.createElement("div");
  panel.id = "tokenize-content-bulk-status";
  panel.className = "notice notice-info";
  panel.style.padding = "12px 16px";

  const heading = document.createElement("p");
  heading.innerHTML = `<strong>Tokenizing ${postIds.length} post${postIds.length === 1 ? "" : "s"} with Medialane…</strong> You'll sign once for the whole batch.`;
  panel.appendChild(heading);

  const list = document.createElement("ul");
  list.style.margin = "8px 0 0";
  const rows = {};
  for (const postId of postIds) {
    const row = document.createElement("li");
    row.textContent = `${titles[postId] || `#${postId}`} — Waiting…`;
    list.appendChild(row);
    rows[postId] = row;
  }
  panel.appendChild(list);

  return {
    element: panel,
    update(postId, status, message) {
      const row = rows[postId];
      if (!row) return;
      const title = titles[postId] || `#${postId}`;
      const label = STATUS_LABEL[status] || status;
      row.textContent = `${title} — ${label}${message ? `: ${message}` : ""}`;
    },
  };
}

document.addEventListener("submit", (e) => {
  const form = e.target.closest && e.target.closest("#posts-filter");
  if (!form) return;
  const select = form.querySelector('select[name="action"], select[name="action2"]');
  if (!select || select.value !== "tokenize_content_tokenize") return;
  e.preventDefault();

  const ids = Array.from(form.querySelectorAll('input[name="post[]"]:checked')).map((el) => el.value);
  if (ids.length === 0) return;
  if (!confirm(`Tokenize ${ids.length} post${ids.length === 1 ? "" : "s"} on Medialane? This mints them on-chain and can't be undone.`)) {
    return;
  }

  const data = window.tokenizeContentData;
  const titles = Object.fromEntries(ids.map((id) => {
    const link = document.querySelector(`#post-${id} .row-title`);
    return [id, link ? link.textContent : ""];
  }));
  const status = buildStatusPanel(ids, titles);
  const heading = document.querySelector(".wp-heading-inline") || document.querySelector(".wrap h1");
  if (heading && heading.parentNode) {
    heading.parentNode.insertBefore(status.element, heading.nextSibling);
  }

  tokenizeBulk(ids, (id, s, message) => status.update(id, s, message)).then(() => location.reload());
});
