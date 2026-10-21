import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch, markError } from "./mint-flow.js";
import { getTokenizeData } from "./api.js";
import { assertSiteWallet } from "./site-wallet.js";

const MAX_BATCH_SIZE = 25;

function chunk(array, size) {
  const out = [];
  for (let i = 0; i < array.length; i += size) out.push(array.slice(i, i + size));
  return out;
}

export async function tokenizeBulk(postIds, onProgress) {
  const data = window.tokenizeContentData;
  const { address, account } = await connectWallet();
  assertSiteWallet(address, data.walletAddress);
  const { posts } = await getTokenizeData(postIds);
  const report = (postId, status, message) => onProgress && onProgress(postId, status, message);
  let failed = 0;
  let minted = 0;

  const fail = async (postId, message) => {
    failed += 1;
    report(postId, "error", message);
    try {
      await markError(postId, message);
    } catch {
      return;
    }
  };

  const groups = new Map();
  for (const postId of postIds) {
    const post = posts[postId];
    if (!post) {
      failed += 1;
      report(postId, "error", "No data was returned for this post.");
      continue;
    }
    if (post.error) {
      failed += 1;
      report(postId, "error", post.error);
      continue;
    }
    report(postId, "preparing");
    try {
      if (!post.collectionId) {
        throw new Error("No collection configured.");
      }
      const entry = await prepareMint({
        postId, title: post.title, body: post.description, hasImage: post.hasImage,
        license: data.licenseDefault, licenses: data.licenses, aiPolicy: data.aiPolicyDefault,
        address, collectionId: post.collectionId, authorEmail: post.authorEmail,
      });
      if (!groups.has(post.collectionId)) groups.set(post.collectionId, { contract: post.collectionContract, entries: [] });
      groups.get(post.collectionId).entries.push(entry);
    } catch (err) {
      await fail(postId, err.message || "Something went wrong");
    }
  }

  for (const { contract, entries } of groups.values()) {
    for (const group of chunk(entries, MAX_BATCH_SIZE)) {
      group.forEach((e) => report(e.postId, "minting"));
      const results = await executeMintBatch({ account, address, collectionContract: contract, entries: group });
      results.forEach((r) => {
        if (r.error) failed += 1; else minted += 1;
        report(r.postId, r.error ? "error" : "minted", r.error);
      });
    }
  }
  return { minted, failed };
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
    finish(text) {
      const line = document.createElement("p");
      line.textContent = text;
      panel.appendChild(line);
    },
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

  const titles = Object.fromEntries(ids.map((id) => {
    const link = document.querySelector(`#post-${id} .row-title`);
    return [id, link ? link.textContent : ""];
  }));
  const status = buildStatusPanel(ids, titles);
  const heading = document.querySelector(".wp-heading-inline") || document.querySelector(".wrap h1");
  if (heading && heading.parentNode) {
    heading.parentNode.insertBefore(status.element, heading.nextSibling);
  }

  tokenizeBulk(ids, (id, s, message) => status.update(id, s, message))
    .then(({ minted, failed }) => {
      if (failed === 0) {
        location.reload();
        return;
      }
      status.finish(`${minted} minted, ${failed} failed.`);
    })
    .catch((err) => status.finish(err.message || "Something went wrong"));
});
