import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

const dir = join(import.meta.dirname, "../../assets/src");
const files = readdirSync(dir).filter((name) => name.endsWith(".js"));

const untranslated = [
  [/new (?:Api)?Error\(\s*["'`]/, "an error message"],
  [/\b(?:alert|confirm)\(\s*["'`]/, "an alert or confirm text"],
  [/\.textContent\s*=\s*["'`]/, "button or label text"],
  [/\.innerHTML\s*=/, "markup built from text"],
  [/throw new Error\(`/, "an error message"],
];

describe("user-facing text", () => {
  it.each(files)("%s passes every message through the translator", (file) => {
    const source = readFileSync(join(dir, file), "utf8");
    for (const [pattern, what] of untranslated) {
      expect(pattern.test(source), `${file} has ${what} that is not translated`).toBe(false);
    }
  });
});
