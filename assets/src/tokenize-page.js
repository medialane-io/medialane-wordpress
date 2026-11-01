import { tokenizeBulk, buildStatusPanel, summaryLine, confirmMessage } from "./bulk-tokenize.js";
import { __ } from "@wordpress/i18n";

const boxes = () => Array.from(document.querySelectorAll('#tokenize-content-page-rows input[type="checkbox"]'));

document.addEventListener("DOMContentLoaded", () => {
  const all = document.getElementById("tokenize-content-page-all");
  const run = document.getElementById("tokenize-content-page-run");
  if (!all || !run) return;

  all.addEventListener("change", () => {
    boxes().forEach((box) => {
      if (!box.disabled) box.checked = all.checked;
    });
  });

  run.addEventListener("click", () => {
    const chosen = boxes().filter((box) => box.checked);
    if (chosen.length === 0) return;
    if (!confirm(confirmMessage(chosen.length))) return;

    const ids = chosen.map((box) => box.value);
    const titles = Object.fromEntries(chosen.map((box) => [box.value, box.dataset.title]));
    const status = buildStatusPanel(ids, titles);
    const slot = document.getElementById("tokenize-content-page-status");
    slot.replaceChildren(status.element);
    run.disabled = true;

    tokenizeBulk(ids, (id, s, message) => status.update(id, s, message))
      .then(({ minted, failed }) => {
        if (failed === 0) {
          location.reload();
          return;
        }
        run.disabled = false;
        status.finish(summaryLine(minted, failed));
      })
      .catch((err) => {
        run.disabled = false;
        status.finish(err.message || __("Something went wrong", "tokenize-content"));
      });
  });
});
