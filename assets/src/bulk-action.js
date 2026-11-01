import { tokenizeBulk, buildStatusPanel, summaryLine, confirmMessage } from "./bulk-tokenize.js";
import { __ } from "@wordpress/i18n";

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
