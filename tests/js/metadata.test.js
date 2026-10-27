import { describe, it, expect } from "vitest";
import { buildPostMetadata } from "../../assets/src/metadata.js";

const licenses = {
  "CC BY-SA": { commercialUse: "Yes", derivatives: "Share-Alike", attribution: "Required" },
  "All Rights Reserved": { commercialUse: "No", derivatives: "Not Allowed", attribution: "Required" },
  CC0: { commercialUse: "Yes", derivatives: "Allowed", attribution: "Not Required" },
};

const post = { title: "A Post", body: "Summary", license: "CC BY-SA", licenses, aiPolicy: "Allowed" };

const traits = (metadata) => Object.fromEntries(metadata.attributes.map((a) => [a.trait_type, a.value]));

describe("buildPostMetadata", () => {
  it("uses the standard fields for the title, the summary, the image and the post address", () => {
    const metadata = buildPostMetadata({ ...post, imageUri: "ipfs://img", url: "https://ambrosia.com.br/post" });
    expect(metadata).toMatchObject({ name: "A Post", description: "Summary", image: "ipfs://img", external_url: "https://ambrosia.com.br/post" });
  });

  it("describes the post as a publication with its creator and publisher", () => {
    const attrs = traits(buildPostMetadata({ ...post, author: "Ana", publisher: "Ambrosia" }));
    expect(attrs).toMatchObject({ "IP Type": "Publications", Creator: "Ana", Publisher: "Ambrosia" });
  });

  it("carries the full text, whatever its length, in the Content attribute", () => {
    const content = "# Title\n\n" + "word ".repeat(50_000).trim();
    expect(traits(buildPostMetadata({ ...post, content })).Content).toBe(content);
  });

  it("leaves out what the post does not have", () => {
    const attrs = traits(buildPostMetadata(post));
    expect(attrs).not.toHaveProperty("Content");
    expect(attrs).not.toHaveProperty("Creator");
    expect(attrs).not.toHaveProperty("Publisher");
  });

  it("has no Registration attribute", () => {
    expect(traits(buildPostMetadata(post))).not.toHaveProperty("Registration");
  });

  it("expands the CC BY-SA preset into the platform's canonical trait set", () => {
    expect(traits(buildPostMetadata(post))).toMatchObject({
      License: "CC BY-SA",
      "Commercial Use": "Yes",
      Derivatives: "Share-Alike",
      Attribution: "Required",
      Territory: "Worldwide",
      "AI Policy": "Allowed",
    });
  });

  it("expands All Rights Reserved to no commercial use, no derivatives", () => {
    expect(traits(buildPostMetadata({ ...post, license: "All Rights Reserved", aiPolicy: "Not Allowed" }))).toMatchObject({
      License: "All Rights Reserved",
      "Commercial Use": "No",
      Derivatives: "Not Allowed",
      "AI Policy": "Not Allowed",
    });
  });

  it("expands CC0 to no attribution required", () => {
    expect(traits(buildPostMetadata({ ...post, license: "CC0" })).Attribution).toBe("Not Required");
  });

  it("does not fabricate terms for a Custom license or a preset the page did not define", () => {
    for (const license of ["Custom", "Unknown"]) {
      const attrs = traits(buildPostMetadata({ ...post, license }));
      expect(attrs.License).toBe(license);
      expect(attrs).not.toHaveProperty("Commercial Use");
      expect(attrs).not.toHaveProperty("Derivatives");
      expect(attrs).not.toHaveProperty("Attribution");
    }
  });

  it("omits the AI policy trait when none is given", () => {
    expect(traits(buildPostMetadata({ ...post, aiPolicy: undefined }))).not.toHaveProperty("AI Policy");
  });
});
