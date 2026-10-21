import { describe, it, expect, beforeEach } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { sprintf as stubSprintf, __ as stubGettext, _n as stubPlural } from "../stubs/wp-i18n.js";

const bundles = ["settings", "metabox", "bulk-action"];

describe("built scripts", () => {
  beforeEach(() => {
    window.wp = { i18n: { __: stubGettext, _n: stubPlural, sprintf: stubSprintf } };
    window.tokenizeContentData = { restUrl: "/wp-json/tokenize-content/v1", nonce: "n", walletAddress: "", licenses: {} };
  });

  it.each(bundles)("%s.js runs on a page that provides wp.i18n and the plugin data", (name) => {
    const code = readFileSync(join(import.meta.dirname, "../../assets/dist", `${name}.js`), "utf8");

    expect(() => new Function(code)()).not.toThrow();
  });

  it.each(bundles)("%s.js takes its translator from wp.i18n instead of bundling one", (name) => {
    const code = readFileSync(join(import.meta.dirname, "../../assets/dist", `${name}.js`), "utf8");

    expect(code).toContain("window.wp.i18n");
    expect(code).not.toContain("@wordpress/i18n");
  });
});
