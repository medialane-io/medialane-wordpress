// The platform's canonical license presets, expanded into the canonical trait
// set — third parties (and the remix/marketplace flows on medialane-backend)
// read License terms from these exact attributes, never from a free-form string.
const LICENSE_PRESETS = {
  "CC BY-SA": { commercialUse: "Yes", derivatives: "Share-Alike", attribution: "Required" },
  "CC BY": { commercialUse: "Yes", derivatives: "Allowed", attribution: "Required" },
  "CC BY-NC": { commercialUse: "No", derivatives: "Allowed", attribution: "Required" },
  "CC BY-ND": { commercialUse: "Yes", derivatives: "Not Allowed", attribution: "Required" },
  "CC BY-NC-SA": { commercialUse: "No", derivatives: "Share-Alike", attribution: "Required" },
  "CC BY-NC-ND": { commercialUse: "No", derivatives: "Not Allowed", attribution: "Required" },
  CC0: { commercialUse: "Yes", derivatives: "Allowed", attribution: "Not Required" },
  MIT: { commercialUse: "Yes", derivatives: "Allowed", attribution: "Required" },
  "Apache 2.0": { commercialUse: "Yes", derivatives: "Allowed", attribution: "Required" },
  "All Rights Reserved": { commercialUse: "No", derivatives: "Not Allowed", attribution: "Required" },
};

export const LICENSE_PRESET_IDS = [...Object.keys(LICENSE_PRESETS), "Custom"];

// Custom has no expansion: its Commercial Use/Derivatives/Attribution are
// author-set, and we don't collect per-trait overrides, so we don't
// fabricate values for traits nobody actually chose.
export function buildLicenseAttributes(preset, aiPolicy, territory = "Worldwide") {
  const attributes = [{ trait_type: "License", value: preset }];
  const expansion = LICENSE_PRESETS[preset];
  if (expansion) {
    attributes.push(
      { trait_type: "Commercial Use", value: expansion.commercialUse },
      { trait_type: "Derivatives", value: expansion.derivatives },
      { trait_type: "Attribution", value: expansion.attribution },
    );
  }
  attributes.push({ trait_type: "Territory", value: territory });
  if (aiPolicy) {
    attributes.push({ trait_type: "AI Policy", value: aiPolicy });
  }
  return attributes;
}
