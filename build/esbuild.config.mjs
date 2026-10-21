import * as esbuild from "esbuild";

const wordpressI18n = {
  name: "wordpress-i18n",
  setup(build) {
    build.onResolve({ filter: /^@wordpress\/i18n$/ }, () => ({ path: "@wordpress/i18n", namespace: "wp-global" }));
    build.onLoad({ filter: /.*/, namespace: "wp-global" }, () => ({ contents: "module.exports = window.wp.i18n;", loader: "js" }));
  },
};

await esbuild.build({
  entryPoints: {
    settings: "assets/src/settings.js",
    metabox: "assets/src/metabox.js",
    "bulk-action": "assets/src/bulk-action.js",
  },
  bundle: true,
  outdir: "assets/dist",
  format: "iife",
  target: "es2020",
  sourcemap: false,
  minify: true,
  plugins: [wordpressI18n],
});
