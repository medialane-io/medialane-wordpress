import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch, markError } from "./mint-flow.js";
import { getTokenizeData } from "./api.js";
import { assertSiteWallet } from "./site-wallet.js";
import { __, _n, sprintf } from "@wordpress/i18n";

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
      report(postId, "error", __("No data was returned for this post.", "tokenize-content"));
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
        throw new Error(__("No collection configured.", "tokenize-content"));
      }
      const entry = await prepareMint({
        postId, title: post.title, body: post.description, hasImage: post.hasImage,
        license: data.licenseDefault, licenses: data.licenses, aiPolicy: data.aiPolicyDefault,
        address, collectionId: post.collectionId, authorEmail: post.authorEmail,
      });
      if (!groups.has(post.collectionId)) groups.set(post.collectionId, { contract: post.collectionContract, entries: [] });
      groups.get(post.collectionId).entries.push(entry);
    } catch (err) {
      await fail(postId, err.message || __("Something went wrong", "tokenize-content"));
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

const statusLabel = (status) => ({
  preparing: __("Preparing…", "tokenize-content"),
  minting: __("Signing & minting…", "tokenize-content"),
  minted: __("Minted", "tokenize-content"),
  error: __("Failed", "tokenize-content"),
}[status] || status);

function rowText(title, label, message) {
  return `${title} — ${label}${message ? `: ${message}` : ""}`;
}

export function summaryLine(minted, failed) {
  return sprintf(__("%1$d minted, %2$d failed.", "tokenize-content"), minted, failed);
}

export function confirmMessage(count) {
  return sprintf(
    _n(
      "Tokenize %d post on Medialane? This mints it on-chain and can't be undone.",
      "Tokenize %d posts on Medialane? This mints them on-chain and can't be undone.",
      count,
      "tokenize-content",
    ),
    count,
  );
}

export function buildStatusPanel(postIds, titles) {
  const panel = document.createElement("div");
  panel.id = "tokenize-content-bulk-status";
  panel.className = "notice notice-info";
  panel.style.padding = "12px 16px";

  const heading = document.createElement("p");
  const title = document.createElement("strong");
  title.textContent = sprintf(
    _n("Tokenizing %d post with Medialane…", "Tokenizing %d posts with Medialane…", postIds.length, "tokenize-content"),
    postIds.length,
  );
  heading.appendChild(title);
  heading.appendChild(document.createTextNode(" " + __("You'll sign once for the whole batch.", "tokenize-content")));
  panel.appendChild(heading);

  const list = document.createElement("ul");
  list.style.margin = "8px 0 0";
  const rows = {};
  for (const postId of postIds) {
    const row = document.createElement("li");
    row.textContent = rowText(titles[postId] || `#${postId}`, __("Waiting…", "tokenize-content"));
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
      const label = statusLabel(status);
      row.textContent = rowText(title, label, message);
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
  if (!confirm(confirmMessage(ids.length))) {
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
      status.finish(summaryLine(minted, failed));
    })
    .catch((err) => status.finish(err.message || __("Something went wrong", "tokenize-content")));
});
