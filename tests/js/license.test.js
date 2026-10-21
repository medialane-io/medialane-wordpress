import { describe, it, expect } from "vitest";
import { buildLicenseAttributes, buildPublicationAttributes } from "../../assets/src/license.js";

const licenses = {
  "CC BY-SA": { commercialUse: "Yes", derivatives: "Share-Alike", attribution: "Required" },
  "All Rights Reserved": { commercialUse: "No", derivatives: "Not Allowed", attribution: "Required" },
  CC0: { commercialUse: "Yes", derivatives: "Allowed", attribution: "Not Required" },
};

describe("buildLicenseAttributes", () => {
  it("expands the CC BY-SA preset into the platform's canonical trait set", () => {
    expect(buildLicenseAttributes("CC BY-SA", "Allowed", licenses)).toEqual([
      { trait_type: "License", value: "CC BY-SA" },
      { trait_type: "Commercial Use", value: "Yes" },
      { trait_type: "Derivatives", value: "Share-Alike" },
      { trait_type: "Attribution", value: "Required" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Allowed" },
    ]);
  });

  it("expands All Rights Reserved to no commercial use, no derivatives", () => {
    expect(buildLicenseAttributes("All Rights Reserved", "Not Allowed", licenses)).toEqual([
      { trait_type: "License", value: "All Rights Reserved" },
      { trait_type: "Commercial Use", value: "No" },
      { trait_type: "Derivatives", value: "Not Allowed" },
      { trait_type: "Attribution", value: "Required" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Not Allowed" },
    ]);
  });

  it("expands CC0 to no attribution required", () => {
    const attrs = buildLicenseAttributes("CC0", "Allowed", licenses);
    expect(attrs).toContainEqual({ trait_type: "Attribution", value: "Not Required" });
  });

  it("does not fabricate Commercial Use/Derivatives/Attribution for a Custom license", () => {
    const attrs = buildLicenseAttributes("Custom", "Allowed", licenses);
    expect(attrs).toEqual([
      { trait_type: "License", value: "Custom" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Allowed" },
    ]);
  });

  it("keeps a preset the page did not define as a bare license rather than inventing terms", () => {
    expect(buildLicenseAttributes("Unknown", "Allowed", licenses)).toEqual([
      { trait_type: "License", value: "Unknown" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Allowed" },
    ]);
  });

  it("omits the AI policy trait when none is given", () => {
    expect(buildLicenseAttributes("CC0", undefined, licenses).some((a) => a.trait_type === "AI Policy")).toBe(false);
  });
});

describe("buildPublicationAttributes", () => {
  it("marks the post as a publication with its author, publisher and address", () => {
    expect(buildPublicationAttributes({ author: "Ana", publisher: "Ambrosia", url: "https://ambrosia.com.br/post" })).toEqual([
      { trait_type: "IP Type", value: "Publications" },
      { trait_type: "Author", value: "Ana" },
      { trait_type: "Publisher", value: "Ambrosia" },
      { trait_type: "Website", value: "https://ambrosia.com.br/post" },
    ]);
  });

  it("leaves out what the post does not have", () => {
    expect(buildPublicationAttributes({})).toEqual([{ trait_type: "IP Type", value: "Publications" }]);
  });
});
