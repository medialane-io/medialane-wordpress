# Tokenize, Protect & License Your Content

Turn WordPress posts into protected, licensed IP assets on [Medialane](https://medialane.io),
a Starknet-based protocol for programmable intellectual property. Only the site
admin connects a wallet. Authors are identified by their WordPress email and
never need one.

Status: the JS suite (`npm test`) and the PHP suite (`phpunit`) pass. The wallet
connect and signing flow has not been verified in a real browser with a real wallet.

## What it does

- Connect a Starknet wallet (Ready, Braavos) from the WordPress admin. This is
  the one wallet that signs every tokenize action on the site.
- Choose a license (Creative Commons preset, all rights reserved, or custom
  terms) and an AI training policy. Both are expanded into the trait set
  other platforms read, not stored as a plain string.
- From any post's editor, or in bulk from the Posts list, tokenize the post:
  title, body/excerpt, and featured image are pinned to IPFS, and the license
  is attached in the token metadata.
- Tokenized posts go to a wallet tied to the post author's WordPress email,
  set up automatically the first time one of their posts is tokenized. The
  author never connects anything themselves.
- Support multiple collections, with WordPress categories routed to a
  specific collection for tokenization.
- Tokenizing several posts at once from the Posts list signs one transaction
  for the whole batch, up to 25 posts. Larger selections are chunked into
  sequential batches of 25.
- A public badge appears on a tokenized post's own page, linking to its
  on-chain record so readers can verify it themselves.

## How it works

Every mint is signed by the connected wallet in the browser. The plugin
itself has no way to sign on anyone's behalf.

- **PHP** (`includes/`) stores the API key server-side, in `wp_options` or in
  a `TOKENIZE_CONTENT_API_KEY` constant in `wp-config.php`, and exposes a
  WordPress REST proxy (`/wp-json/tokenize-content/v1/*`) that forwards
  allowlisted requests to the Medialane backend with that key attached. The
  key stays on the server. Each route checks the
  `tokenize_content_tokenize_posts` capability for tokenize actions,
  `manage_options` for settings, and an `edit_post` check on any route that
  targets a specific post.
- **JavaScript** (`assets/src/`, bundled to `assets/dist/`) runs in the admin
  UI. It connects the wallet, calls the WordPress proxy to upload metadata
  and build a mint transaction, then asks the wallet to sign it. It talks to
  the proxy only, never to the Medialane backend directly.
- **Post meta** (`_tokenize_content_status`, `_tokenize_content_tx_hash`,
  `_tokenize_content_contract`, `_tokenize_content_token_id`,
  `_tokenize_content_license`) is written only by PHP. This keeps mint state
  tied to a value the server actually recorded.
- Which collections exist is never trusted from local storage. The plugin
  asks `medialane-backend`'s indexer fresh every time; WordPress only stores
  a label per contract.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Starknet wallet browser extension (Ready or Braavos)
- A Medialane API key ([portal.medialane.io/account](https://portal.medialane.io/account))

## Installation

1. Download or clone this repository.
2. `npm install && npm run build` to produce `assets/dist/`.
3. Zip the plugin directory and upload it via **Plugins → Add New → Upload Plugin**,
   or place the directory in `wp-content/plugins/`.
4. Activate the plugin.
5. Go to **Settings → Tokenize & Protect**, paste an API key, and save it.
6. Connect the wallet that will manage tokenization for this site.
7. Open any post and use the **Tokenize & Protect** panel in the editor
   sidebar, or select posts from the Posts list and use the bulk action.

## Development

```bash
npm install
npm run build      # bundles assets/src -> assets/dist with esbuild
npm test           # runs the JS test suite (vitest)
phpunit -c phpunit.xml.dist   # runs the PHP test suite (requires the WP core test library)
```

To run `phpunit` locally, `WP_TESTS_DIR` needs to point at a real WordPress core
test-library checkout. `bin/install-wp-tests.sh <db-name> <db-user> <db-pass>
[db-host] [wp-version]` sets one up. It needs `svn` and a reachable MySQL server. The
test library also needs `yoast/phpunit-polyfills`; point `WP_TESTS_PHPUNIT_POLYFILLS_PATH` at it.
`.github/workflows/ci.yml` runs the JS suite and the PHP suite on every push and PR.

## Security

- The API key is stored in `wp_options` and read only server-side, by the
  PHP REST proxy.
- The connected wallet's private key stays in the browser. The plugin only
  ever requests a signature for a transaction it shows first.
- All uploads and mint transactions go through the site's own REST proxy,
  which forwards to the Medialane backend under the site's own API key.

Found a security issue? Please report it privately rather than opening a
public issue. See the Medialane project for current contact details.

## License

GPL-2.0-or-later. See [`readme.txt`](readme.txt).
