import { __ } from "@wordpress/i18n";

const { registerBlockType } = window.wp.blocks;
const { createElement } = window.wp.element;
const { useBlockProps } = window.wp.blockEditor;
const ServerSideRender = window.wp.serverSideRender;

registerBlockType("tokenize-content/badge", {
  edit({ context }) {
    const placeholder = () => createElement("p", null, __("The asset badge appears here once this post is tokenized.", "tokenize-content"));
    return createElement(
      "div",
      useBlockProps(),
      createElement(ServerSideRender, {
        block: "tokenize-content/badge",
        urlQueryArgs: { post_id: context.postId },
        EmptyResponsePlaceholder: placeholder,
      }),
    );
  },
  save: () => null,
});
