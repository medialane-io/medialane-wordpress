import { buildAssetMetadata } from "@medialane/sdk";

export function buildPostMetadata({ title, body, content, author, publisher, url, imageUri, license, licenses, aiPolicy }) {
  const terms = (licenses && licenses[license]) || {};
  const templateTraits = [];
  if (publisher) templateTraits.push({ traitType: "Publisher", value: publisher });
  if (content) templateTraits.push({ traitType: "Content", value: content });
  return buildAssetMetadata({
    name: title,
    description: body,
    externalUrl: url,
    imageUri,
    creator: author,
    ipType: "Publications",
    licenseType: license,
    commercialUse: terms.commercialUse,
    derivatives: terms.derivatives,
    attribution: terms.attribution,
    geographicScope: "Worldwide",
    aiPolicy,
    templateTraits,
  });
}
