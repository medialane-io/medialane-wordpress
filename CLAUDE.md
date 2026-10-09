# CLAUDE.md

WordPress plugin. Connects a Starknet wallet and tokenizes posts as `mip-erc721` IP assets.

## Commands

```
npm install
npm run build      # esbuild bundle assets/src -> assets/dist (commit the output)
npm test           # vitest
phpunit -c phpunit.xml.dist   # requires WP_TESTS_DIR (WP core test lib)
```

## Architecture

- PHP holds the Medialane API key. `Settings::get_api_key()` reads a
  `TOKENIZE_CONTENT_API_KEY` `wp-config.php` constant if defined, or falls back to
  `wp_options`. `includes/class-rest-proxy.php` forwards allowlisted calls to
  `medialane-backend` with that key attached; the key stays on the server.
  Every backend-forwarding route binds its target path via a closure argument
  to `forward_json()`, so the path is fixed in code rather than taken from a
  client-suppliable REST param.
- JS (`assets/src`, bundled to `assets/dist`) handles wallet connect and
  signing (`starknet` + `get-starknet-core`) and calls the WP REST proxy. It
  talks to the backend only through that proxy. `get-starknet-core` v4's
  `StarknetWindowObject` exposes a raw `request()` method rather than an
  `.account`, so `wallet.js`'s `connectWallet()` bridges it into a signing
  `Account` via starknet.js's `WalletAccount.connect()`.
- Minting goes through `medialane-backend`'s paymaster
  (`/v1/paymaster/invoke/{build,execute}`, proxied at
  `/tokenize-content/v1/paymaster/invoke/{build,execute}`). The caller signs with
  their own wallet, using `wallet.js`'s `signTypedData()`, which wraps
  `WalletAccount.signMessage`. The paymaster covers the network fee.
- A site can hold multiple `mip-erc721` collections, all owned by the same
  connected wallet (the chain only enforces single ownership, not which of
  an owner's collections a mint targets). **Which collections exist is never
  decided locally.** `Settings::fetch_live_collections()` asks
  `medialane-backend`'s indexer (`GET /v1/collections?owner=`) fresh, every
  time it's called — that's the only authority on what actually exists:
  the smart contract is the only truth, never a second source. `wp_options`
  (`tokenize_content_collection_labels`) stores *only* a friendly label per
  contract — a name has no on-chain meaning, so that part is legitimate
  local data, same category as a slug. A stored label whose contract isn't
  in the live list doesn't render. `Settings::resolve_collection_for_post()`
  takes the live list as a required argument and validates against it:
  a category-mapped or stored-default contract that isn't actually live
  falls through to the first real collection, never to stale local data.
  Callers that need this for many posts in one request (`BulkAction::enqueue()`)
  fetch once and pass the list in, rather than one backend call per post.
  `assets/src/settings.js`'s `createAndRegisterCollection()` creates a
  collection on-chain, polls `GET /v1/collections?owner=` until the indexer
  confirms it (reading the real `contractAddress` field — a prior version
  of this code read `contract`/`address`, which don't exist on the real
  response and always returned `undefined`), then saves its label via
  `POST /settings/collections`. The metabox exposes a manual override
  `<select>` when more than one live collection exists; the bulk action
  groups posts by resolved collection and runs one batch transaction per
  group, since `executeMintBatch()` still only ever targets one collection
  per call.
- The Settings page is gated, in order: API Key → Connect Wallet → everything
  else. Nothing past a gate renders until that step is real (`Settings::get_api_key()`
  non-empty, then `Settings::get_wallet_address()` non-empty) — no collection
  list, no category mapping, nothing that would only work once connected.
  Connecting a wallet saves immediately via `POST /settings/wallet`
  (`Settings::save_wallet_address()`), not deferred to the page's own "Save
  Changes" submit — a prior version only staged the address into a hidden
  form field, so a wallet could successfully connect and create collections
  (which do save immediately) while still showing "Not connected" on the
  next load, since nobody had separately submitted the form.
- Post state (`none|minting|minted|error`) lives in post meta, written only
  by PHP (`includes/class-post-meta.php`) through the `/posts/{id}/minting`,
  `/posts/{id}/minted`, and `/posts/{id}/error` REST routes. JS reads this
  state but writes it only through those routes.
- The mint sequence is split across two functions in `assets/src/mint-flow.js`,
  shared by the per-post metabox and the Posts-list bulk action.
  `prepareMint()` uploads metadata and builds one post's mint calls, without
  touching the chain or post meta. `executeMintBatch()` takes one or more
  `prepareMint()` results, signs and executes their combined calls as a
  single transaction, then marks every post in the batch minted with the
  shared tx hash. If the transaction fails, every post in that batch is
  marked errored, since one on-chain transaction succeeds or fails as a
  unit. The bulk action caps batches at 25 posts, sending larger selections
  as sequential batches.
- `prepareMint()` mints to a wallet tied to the post author's registered
  WordPress email, not to the connected wallet's own address. It generates
  a one-time interim keypair (`wallet.js`'s `generateInterimKeypair()`,
  never persisted), uses it to sign that wallet's deployment typed data
  (`signDeploymentWithInterimKey()`), and hands the signed deployment to
  `medialane-backend`'s business-provisioning endpoint
  (`/v1/business/provisioning`, proxied at `/tokenize-content/v1/business/provisioning`)
  along with `recipientScheme: "email"` and the author's address. The
  backend deploys the wallet on first use and reuses it on later posts from
  the same author. The mint intent's `owner` stays the connected wallet's
  address, since that's what the chain checks for collection ownership;
  only `recipient` becomes the author's provisioned wallet.
- Tokenize routes check a dedicated `tokenize_content_tokenize_posts` capability
  (`Settings::CAP_TOKENIZE`) rather than `manage_options`, granted only to
  Administrator on activation. `RestProxy::check_tokenize_permission()` also
  requires `edit_post` on any route that targets a specific post id.
  Settings and collection-management routes check `manage_options` through
  `check_admin_permission()`. There's deliberately no way to extend this
  capability to other roles from the UI: the site has exactly one connected
  wallet (whoever holds it, on Settings), and that's the only address the
  chain will accept as `owner` for a mint on this site's collection — see
  the "one signer" note below.
- Every admin screen that enqueues plugin JS localizes the same global name,
  `tokenizeContentData` (see the `wp_localize_script` calls in `class-settings.php`,
  `class-metabox.php`, and `class-bulk-action.php`). This works because each
  screen enqueues its own script on its own admin page: settings page, post
  editor, or Posts list.
- After a batch mint confirms, `mintedTokenIdsFromReceipt()` in `wallet.js`
  reads each entry's real on-chain token id off the confirmed receipt's
  `Transfer(from=0)` events, in call order, and `executeMintBatch()` saves
  one per post. Post meta held an empty token id before this existed.
- `includes/class-asset-badge.php` appends a public "IP Protected &
  Tokenized" card to a minted post's own single-post page (`the_content`
  filter, gated to `is_singular('post') && in_the_loop() && is_main_query()`),
  linking to that token's asset page on `medialane.io`. It reads straight
  from post meta (`PostMeta::get_contract()`/`get_token_id()`/`get_license()`)
  — no new backend call, since everything it needs was already saved at mint
  time.
- `BulkAction::render_pending_notice()` shows an admin notice on the Posts
  list screen when published posts exist that haven't been tokenized yet,
  pointing at the same bulk action. It shares `get_pending_posts()` with
  `enqueue()` rather than querying twice.
- One signer for the whole site, always. `medialane-backend`'s
  `buildMintIntent` checks on-chain that the mint's `owner` is the
  collection's registered owner; there's no multi-owner or authorized-signer
  concept in the protocol. So the only wallet that can ever complete a mint
  on this site is the one connected on the Settings page — every tokenize
  action, whoever triggers it, needs that same wallet connected in the same
  browser. There's no per-editor signing path, and none is planned: the
  plugin's whole premise is that nobody but the person managing the site's
  Medialane account ever needs a Starknet wallet at all.
- `Settings::get_license_default()` (set on the Settings page) is what
  `bulk-action.js` uses instead of a hardcoded license, and what the metabox's
  license `<select>` pre-selects for a single post.
- License is programmable, not a string. `assets/src/license.js`'s
  `buildLicenseAttributes(preset, aiPolicy)` expands a chosen preset (`CC
  BY-SA`, `MIT`, `All Rights Reserved`, etc.) into the canonical trait set
  — `Commercial
  Use`, `Derivatives`, `Attribution`, `Territory`, `AI Policy` — and
  `prepareMint()` uploads it as the metadata's `attributes` array, never as
  a flat `license` field. That's the same encoding `medialane-backend`'s
  remix-offers flow reads (`attrs.find(a => a.trait_type === "License")`),
  so assets minted through this plugin are legible to the rest of the
  platform, not just to this plugin's own post meta. `Custom` intentionally
  has no expansion — its Commercial Use/Derivatives/Attribution are
  author-set, and the plugin doesn't collect per-trait overrides, so it
  doesn't fabricate values nobody chose. `Settings::get_ai_policy_default()`
  is the site-wide default; there's no per-post override yet.

## Common pitfalls

- Route a raw `fetch` to the `medialane-backend` URL from JS through
  `/wp-json/tokenize-content/v1/*` instead, so the API key stays server-side.
- `assets/dist/*.js` is checked in. There's no build step on activation.
  Run `npm run build` and commit the output after any `assets/src` change.
- `phpunit -c phpunit.xml.dist` needs `WP_TESTS_DIR` pointed at a real WP
  core test-library checkout. `bin/install-wp-tests.sh` sets one up. WP
  core's own `wp-tests-config-sample.php` hardcodes `ABSPATH` to
  `dirname(__FILE__) . '/src/'`, so WP core has to live inside
  `$WP_TESTS_DIR/src`.
- A REST `permission_callback` returning bare `false` becomes 403 for any
  logged-in user. WordPress's `rest_authorization_required_code()` reserves
  401 for anonymous requests only. Easy to get backwards in tests.
