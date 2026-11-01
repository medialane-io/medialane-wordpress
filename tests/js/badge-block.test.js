import { describe, it, expect, vi, beforeEach } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { __ as stubGettext, _n as stubPlural, sprintf as stubSprintf } from "../stubs/wp-i18n.js";

const code = readFileSync(join(import.meta.dirname, "../../assets/dist/badge-block.js"), "utf8");

describe("the badge block script", () => {
  let registered;

  beforeEach(() => {
    registered = {};
    window.wp = {
      i18n: { __: stubGettext, _n: stubPlural, sprintf: stubSprintf },
      blocks: { registerBlockType: vi.fn((name, settings) => { registered[name] = settings; }) },
      element: { createElement: (type, props, ...children) => ({ type, props, children }) },
      blockEditor: { useBlockProps: () => ({ className: "wp-block" }) },
      serverSideRender: "ServerSideRender",
    };
    new Function(code)();
  });

  it("registers the badge block with no saved markup", () => {
    expect(Object.keys(registered)).toEqual(["tokenize-content/badge"]);
    expect(registered["tokenize-content/badge"].save()).toBeNull();
  });

  it("renders the post's badge from the server, for the post being edited", () => {
    const tree = registered["tokenize-content/badge"].edit({ context: { postId: 42 } });
    const [renderer] = tree.children;

    expect(renderer.type).toBe("ServerSideRender");
    expect(renderer.props.block).toBe("tokenize-content/badge");
    expect(renderer.props.urlQueryArgs).toEqual({ post_id: 42 });
  });

  it("says where the badge will appear when the post is not tokenized yet", () => {
    const tree = registered["tokenize-content/badge"].edit({ context: { postId: 42 } });
    const placeholder = tree.children[0].props.EmptyResponsePlaceholder();

    expect(placeholder.children[0]).toBe("The asset badge appears here once this post is tokenized.");
  });
});
