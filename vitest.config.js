import { defineConfig } from "vitest/config";
import { fileURLToPath } from "node:url";

export default defineConfig({
  resolve: {
    alias: {
      "@wordpress/i18n": fileURLToPath(new URL("./tests/stubs/wp-i18n.js", import.meta.url)),
    },
  },
  test: {
    environment: "jsdom",
  },
});
