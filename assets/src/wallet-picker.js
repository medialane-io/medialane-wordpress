import { __ } from "@wordpress/i18n";

const overlayStyle = "position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5)";
const boxStyle = "background:#fff;padding:20px 24px;border-radius:4px;min-width:260px;display:flex;flex-direction:column;gap:8px";

function button(label, onClick) {
  const element = document.createElement("button");
  element.type = "button";
  element.className = "button";
  element.textContent = label;
  element.addEventListener("click", onClick);
  return element;
}

export function chooseWallet(wallets) {
  if (wallets.length === 1) return Promise.resolve(wallets[0]);

  return new Promise((resolve, reject) => {
    const overlay = document.createElement("div");
    overlay.setAttribute("data-tokenize-content-wallet-picker", "");
    overlay.style.cssText = overlayStyle;
    const box = document.createElement("div");
    box.style.cssText = boxStyle;
    const title = document.createElement("strong");
    title.textContent = __("Choose a wallet", "tokenize-content");
    box.appendChild(title);

    const close = () => {
      document.removeEventListener("keydown", onKey);
      overlay.remove();
    };
    const cancel = () => {
      close();
      reject(new Error(__("No wallet selected.", "tokenize-content")));
    };
    const onKey = (event) => {
      if (event.key === "Escape") cancel();
    };

    for (const wallet of wallets) {
      box.appendChild(button(wallet.name || wallet.id, () => {
        close();
        resolve(wallet);
      }));
    }
    box.appendChild(button(__("Cancel", "tokenize-content"), cancel));
    overlay.appendChild(box);
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) cancel();
    });
    document.addEventListener("keydown", onKey);
    document.body.appendChild(overlay);
    box.querySelector("button").focus();
  });
}
