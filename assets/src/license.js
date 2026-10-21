export function buildLicenseAttributes(preset, aiPolicy, licenses, territory = "Worldwide") {
  const attributes = [{ trait_type: "License", value: preset }];
  const terms = licenses && licenses[preset];
  if (terms) {
    attributes.push(
      { trait_type: "Commercial Use", value: terms.commercialUse },
      { trait_type: "Derivatives", value: terms.derivatives },
      { trait_type: "Attribution", value: terms.attribution },
    );
  }
  attributes.push({ trait_type: "Territory", value: territory });
  if (aiPolicy) {
    attributes.push({ trait_type: "AI Policy", value: aiPolicy });
  }
  return attributes;
}

export function buildPublicationAttributes({ author, publisher, url }) {
  const attributes = [{ trait_type: "IP Type", value: "Publications" }];
  if (author) attributes.push({ trait_type: "Author", value: author });
  if (publisher) attributes.push({ trait_type: "Publisher", value: publisher });
  if (url) attributes.push({ trait_type: "Website", value: url });
  return attributes;
}
