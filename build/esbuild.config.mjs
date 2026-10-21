import * as esbuild from "esbuild";

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
  sourcemap: true,
  minify: true,
});
