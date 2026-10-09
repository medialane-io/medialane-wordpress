# Author Wallet Provisioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mint tokenized posts to a wallet tied to the post author's registered WordPress email, instead of to whichever wallet happens to be connected in the browser when someone clicks "Tokenize." The site admin's own wallet still signs and pays (via the site's Medialane credits); the resulting asset belongs to the author's own identity, deployed fully on-chain.

**Architecture:** `medialane-backend`'s `/v1/business/provisioning` already supports this exactly: an API caller (here, the WP admin's browser) generates a one-time interim keypair, signs that wallet's deployment, and hands it to the backend, which deploys it on-chain (or reuses an existing wallet for that email if one already exists) and links it to an `Identity{scheme:"email"}`. Verified against the real backend source this session, not assumed: `buildMintIntent` (`medialane-backend/src/orchestrator/intent/collection.ts:79-140`) checks the `owner` field on-chain for collection ownership but places `recipient` directly into the mint calldata with no signature requirement on it at all, so the interim key is only ever needed for the one-time deploy step. No backend changes are needed. This plan is entirely within `medialane-wordpress`.

**Tech Stack:** `starknet` v10 (already a dependency), `@medialane/sdk` (new dependency, for `computeAccountAddress`/`ownerConstructorCalldata` — hand-duplicating protocol-specific address derivation is a correctness risk this plan avoids), PHP REST proxy routes following the existing `forward_json()` pattern.

**Spec:** This document. Rationale is drawn from this session's conversation: the "backend is not the authority" principle (verify on-chain, not database/API assertions), the "no crypto jargon in consumer copy" rule, and the verified `buildMintIntent` recipient mechanics above.

## Global Constraints

- PHP: `Requires PHP: 7.4` — no PHP 8-only syntax.
- JS: `assets/dist/*.js` must be rebuilt (`npm run build`) and committed after every `assets/src` change.
- No new REST route may accept a client-suppliable backend path; every `forward_json()` call binds its path via a closure argument, matching the existing fix in `class-rest-proxy.php`.
- No "gas"/"sponsor"/"paymaster" language in anything user-facing (UI strings, `readme.txt`, `README.md`'s summary). Technical accuracy in `CLAUDE.md` is fine, since it's a developer-only doc.
- Every post-meta write reflecting mint status must follow a real on-chain confirmation, per the `waitForConfirmation()` fix already in `mint-flow.js` — this plan must not reintroduce a hash-without-confirmation gap.
- An interim private key must never be persisted anywhere (not in `wp_options`, not in post meta, not in a JS variable that outlives the mint call). Generate it, use it once to sign the deployment, let it go out of scope.

---

## File Structure

| File | Change |
|---|---|
| `package.json` | Add `@medialane/sdk` dependency |
| `includes/class-rest-proxy.php` | Add two forwarding routes: `/paymaster/deploy/build` and `/business/provisioning` |
| `assets/src/wallet.js` | Add `generateInterimKeypair()` and `signDeploymentWithInterimKey()` |
| `assets/src/api.js` | Add `buildSponsoredDeploy()` and `provisionRecipientWallet()` |
| `assets/src/mint-flow.js` | `prepareMint()` resolves the author's wallet via provisioning before building the mint intent, instead of using the connected wallet's own address as `recipient` |
| `includes/class-metabox.php` | Pass the post author's email into `medialaneData` |
| `includes/class-bulk-action.php` | Pass each post's author email into `medialaneData.posts[id]` |
| `tests/js/wallet.test.js`, `tests/js/api.test.js`, `tests/js/mint-flow.test.js` | New coverage for the above |
| `tests/php/test-rest-proxy.php` | Coverage for the two new routes |

---

## Task 1: SDK dependency and interim keypair helpers

**Files:**
- Modify: `package.json`
- Modify: `assets/src/wallet.js`
- Test: `tests/js/wallet.test.js`

**Interfaces:**
- Produces: `generateInterimKeypair(): { privateKey: string, publicKey: string, address: string }` and `signDeploymentWithInterimKey(privateKey: string, address: string, typedData: unknown): Promise<string[]>`, both used only by `prepareMint()` (Task 4).

- [x] **Step 1: Add the dependency**

```bash
npm install @medialane/sdk
```

Run: `grep '"@medialane/sdk"' package.json` to confirm it landed in `dependencies`, not `devDependencies` — this code runs in the browser bundle, not just at build time.

- [x] **Step 2: Write the failing test**

```js
// tests/js/wallet.test.js — add to the existing vi.mock("starknet", ...) the pieces this needs:
// ec: a minimal stand-in for starknet's ec.starkCurve, and Account for signing.
```

Add near the top of `tests/js/wallet.test.js`, inside the existing `vi.mock("starknet", ...)` factory:

```js
vi.mock("starknet", () => ({
  RpcProvider: class {},
  WalletAccount: { connect: (...args) => walletAccountConnect(...args) },
  stark: { signatureToHexArray: (sig) => sig },
  ec: { starkCurve: {
    utils: { randomPrivateKey: () => new Uint8Array([1, 2, 3, 4]) },
    getStarkKey: (priv) => "0xpub" + Array.from(priv).join(""),
  } },
  Account: class {
    constructor(_provider, address, _privateKey) { this.address = address; }
    async signMessage(typedData) { return ["0xsig1", "0xsig2"]; }
  },
}));
vi.mock("@medialane/sdk/starknet", () => ({
  computeAccountAddress: (pubkey) => `0xaddr-for-${pubkey}`,
}));
```

Add to the imports line and a new `describe` block:

```js
const { connectWallet, executeCalls, signTypedData, waitForConfirmation, generateInterimKeypair, signDeploymentWithInterimKey } = await import("../../assets/src/wallet.js");

describe("generateInterimKeypair", () => {
  it("derives a public key and address from a fresh random private key", () => {
    const keypair = generateInterimKeypair();
    expect(keypair.privateKey).toBeTruthy();
    expect(keypair.publicKey).toBe("0xpub1,2,3,4");
    expect(keypair.address).toBe("0xaddr-for-0xpub1,2,3,4");
  });
});

describe("signDeploymentWithInterimKey", () => {
  it("signs the deployment typed data with a throwaway Account built from the interim key", async () => {
    const signature = await signDeploymentWithInterimKey("0xpriv", "0xaddr", { domain: {}, message: {} });
    expect(signature).toEqual(["0xsig1", "0xsig2"]);
  });
});
```

- [x] **Step 3: Run to verify it fails**

Run: `npm test -- wallet`
Expected: FAIL — `generateInterimKeypair`/`signDeploymentWithInterimKey` are not exported yet.

- [x] **Step 4: Implement**

In `assets/src/wallet.js`, update the imports and add both functions:

```js
import { getStarknet } from "get-starknet-core";
import { RpcProvider, WalletAccount, stark, ec, Account } from "starknet";
import { computeAccountAddress } from "@medialane/sdk/starknet";
```

```js
// Used once, to authorize a newly provisioned author wallet's deployment.
// Never persisted: the caller uses it immediately and lets it go out of scope.
export function generateInterimKeypair() {
  const privateKeyBytes = ec.starkCurve.utils.randomPrivateKey();
  const privateKey = "0x" + Array.from(privateKeyBytes).map((b) => b.toString(16).padStart(2, "0")).join("");
  const publicKey = ec.starkCurve.getStarkKey(privateKeyBytes);
  const address = computeAccountAddress(publicKey);
  return { privateKey, publicKey, address };
}

export async function signDeploymentWithInterimKey(privateKey, address, typedData) {
  const account = new Account(new RpcProvider(), address, privateKey);
  const signature = await account.signMessage(typedData);
  return stark.signatureToHexArray(signature);
}
```

(The plan's Step 2 test mock returns a plain array from `signMessage`, and `signatureToHexArray` in the real `starknet` package accepts and normalizes that shape, matching how `signTypedData()` already uses it above.)

- [x] **Step 5: Run tests to verify they pass**

Run: `npm test -- wallet`
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add package.json package-lock.json assets/src/wallet.js tests/js/wallet.test.js
git commit -m "feat: add interim-keypair generation and deployment signing to wallet.js"
```

---

## Task 2: REST proxy routes for deploy/build and business provisioning

**Files:**
- Modify: `includes/class-rest-proxy.php`
- Test: `tests/php/test-rest-proxy.php`

**Interfaces:**
- Produces: `POST /medialane/v1/paymaster/deploy/build` → forwards to `/v1/paymaster/deploy/build`; `POST /medialane/v1/business/provisioning` → forwards to `/v1/business/provisioning`. Both use `check_tokenize_permission` and `forward_json()`, following the exact pattern of the existing `/paymaster/invoke/*` routes.

- [x] **Step 1: Write the failing test**

```php
public function test_deploy_build_and_provisioning_routes_forward_to_the_fixed_backend_path() {
	wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	update_option( Settings::OPTION_API_KEY, 'test-key' );

	$captured_urls = array();
	add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
		$captured_urls[] = $url;
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
	}, 10, 3 );

	$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/paymaster/deploy/build' ) );
	$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/business/provisioning' ) );

	$this->assertStringEndsWith( '/v1/paymaster/deploy/build', $captured_urls[0] );
	$this->assertStringEndsWith( '/v1/business/provisioning', $captured_urls[1] );
}
```

- [x] **Step 2: Run to verify it fails**

Run: `phpunit -c phpunit.xml.dist --filter test_deploy_build_and_provisioning_routes_forward_to_the_fixed_backend_path`
Expected: FAIL — 404, routes don't exist yet.

- [x] **Step 3: Implement**

Add to `register_routes()` in `includes/class-rest-proxy.php`, alongside the other `forward_json`-backed routes:

```php
register_rest_route( self::NAMESPACE, '/paymaster/deploy/build', array(
	'methods'             => 'POST',
	'callback'            => function ( \WP_REST_Request $request ) {
		return self::forward_json( $request, '/v1/paymaster/deploy/build' );
	},
	'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
) );
register_rest_route( self::NAMESPACE, '/business/provisioning', array(
	'methods'             => 'POST',
	'callback'            => function ( \WP_REST_Request $request ) {
		return self::forward_json( $request, '/v1/business/provisioning' );
	},
	'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
) );
```

- [x] **Step 4: Run tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Rest_Proxy`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add includes/class-rest-proxy.php tests/php/test-rest-proxy.php
git commit -m "feat: add REST proxy routes for paymaster deploy/build and business provisioning"
```

---

## Task 3: API client functions

**Files:**
- Modify: `assets/src/api.js`
- Test: `tests/js/api.test.js`

**Interfaces:**
- Produces: `buildSponsoredDeploy({ ownerPubkey, ownerAddress }): Promise<{ data: { typedData, deployment, calls } }>` and `provisionRecipientWallet({ recipientScheme, recipientValue, interimOwnerPubkey, derivationSalt, deployment }): Promise<{ data: { walletAddress: string, status: string } }>`.

- [x] **Step 1: Write the failing test**

```js
it("buildSponsoredDeploy posts to /paymaster/deploy/build", async () => {
  global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { typedData: {}, deployment: {}, calls: [] } }) });
  await buildSponsoredDeploy({ ownerPubkey: "0xpub", ownerAddress: "0xaddr" });
  expect(global.fetch).toHaveBeenCalledWith(
    expect.stringContaining("/paymaster/deploy/build"),
    expect.objectContaining({ method: "POST" }),
  );
});

it("provisionRecipientWallet posts to /business/provisioning", async () => {
  global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { walletAddress: "0xnew" } }) });
  const body = await provisionRecipientWallet({
    recipientScheme: "email", recipientValue: "a@example.com",
    interimOwnerPubkey: "0xpub", derivationSalt: "abcdefghijklmnop",
    deployment: { typedData: {}, signature: ["0x1"], deployment: {} },
  });
  expect(global.fetch).toHaveBeenCalledWith(
    expect.stringContaining("/business/provisioning"),
    expect.objectContaining({ method: "POST" }),
  );
  expect(body.data.walletAddress).toBe("0xnew");
});
```

Add `buildSponsoredDeploy, provisionRecipientWallet` to the existing import line at the top of `tests/js/api.test.js`.

- [x] **Step 2: Run to verify it fails**

Run: `npm test -- api`
Expected: FAIL — neither function exists.

- [x] **Step 3: Implement**

Add to `assets/src/api.js`:

```js
export function buildSponsoredDeploy(params) {
  return request("/paymaster/deploy/build", { method: "POST", body: JSON.stringify(params) });
}

export function provisionRecipientWallet(params) {
  return request("/business/provisioning", { method: "POST", body: JSON.stringify(params) });
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `npm test -- api`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add assets/src/api.js tests/js/api.test.js
git commit -m "feat: add buildSponsoredDeploy/provisionRecipientWallet to api.js"
```

---

## Task 4: Wire provisioning into prepareMint

**Files:**
- Modify: `assets/src/mint-flow.js`
- Modify: `includes/class-metabox.php`
- Modify: `includes/class-bulk-action.php`
- Test: `tests/js/mint-flow.test.js`

**Interfaces:**
- Consumes: `generateInterimKeypair`, `signDeploymentWithInterimKey` (Task 1); `buildSponsoredDeploy`, `provisionRecipientWallet` (Task 3).
- Produces: `prepareMint()`'s signature grows one required field, `authorEmail`. Its `recipient` is now the provisioned wallet's address, not the caller's own `address`. `owner` stays `address` (the signer, proving collection ownership on-chain per the verified `buildMintIntent` behavior).

- [x] **Step 1: Write the failing test**

Update the mocks at the top of `tests/js/mint-flow.test.js`:

```js
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
  buildSponsoredDeploy: vi.fn().mockResolvedValue({ data: { typedData: {}, deployment: {}, calls: [] } }),
  provisionRecipientWallet: vi.fn().mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } }),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  generateInterimKeypair: vi.fn().mockReturnValue({ privateKey: "0xpriv", publicKey: "0xpub", address: "0xinterim" }),
  signDeploymentWithInterimKey: vi.fn().mockResolvedValue(["0xdsig"]),
}));
```

Update the `prepareMint` test:

```js
describe("prepareMint", () => {
  it("provisions the author's wallet by email and mints to it, not to the caller's own address", async () => {
    const { createMintIntent, provisionRecipientWallet } = await import("../../assets/src/api.js");

    const result = await prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA",
      address: "0xowner", collectionContract: "0xcol", authorEmail: "author@example.com",
    });

    expect(provisionRecipientWallet).toHaveBeenCalledWith(expect.objectContaining({
      recipientScheme: "email", recipientValue: "author@example.com",
    }));
    expect(createMintIntent).toHaveBeenCalledWith(expect.objectContaining({
      owner: "0xowner", recipient: "0xauthorwallet",
    }));
    expect(result.postId).toBe(42);
  });
});
```

- [x] **Step 2: Run to verify it fails**

Run: `npm test -- mint-flow`
Expected: FAIL — `prepareMint` still mints to `address` and never calls `provisionRecipientWallet`.

- [x] **Step 3: Implement**

In `assets/src/mint-flow.js`, update the imports and `prepareMint`:

```js
import { signTypedData, waitForConfirmation, generateInterimKeypair, signDeploymentWithInterimKey } from "./wallet.js";
import {
  uploadJson, createMintIntent, buildSponsoredInvoke, executeSponsoredInvoke,
  buildSponsoredDeploy, provisionRecipientWallet,
} from "./api.js";
```

```js
function randomSalt() {
  return crypto.getRandomValues(new Uint8Array(16)).reduce((s, b) => s + b.toString(16).padStart(2, "0"), "");
}

async function resolveAuthorWallet(authorEmail) {
  const { address, privateKey, publicKey } = generateInterimKeypair();
  const buildRes = await buildSponsoredDeploy({ ownerPubkey: publicKey, ownerAddress: address });
  const signature = await signDeploymentWithInterimKey(privateKey, address, buildRes.data.typedData);
  const provisionRes = await provisionRecipientWallet({
    recipientScheme: "email",
    recipientValue: authorEmail,
    interimOwnerPubkey: publicKey,
    derivationSalt: randomSalt(),
    deployment: { typedData: buildRes.data.typedData, signature, deployment: buildRes.data.deployment },
  });
  return provisionRes.data.walletAddress;
}

// Does not touch the chain or post meta — callers batch these together before executing.
export async function prepareMint({ postId, title, body, image, license, address, collectionContract, authorEmail }) {
  const recipient = await resolveAuthorWallet(authorEmail);
  const metaRes = await uploadJson({ name: title, description: body, image: image || undefined, license });
  const intentRes = await createMintIntent({
    owner: address,
    collectionId: collectionContract,
    recipient,
    tokenUri: metaRes.data.url,
    royaltyBps: 0,
  });
  return { postId, license, calls: intentRes.data.calls };
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `npm test -- mint-flow`
Expected: PASS

- [x] **Step 5: Pass the author's email through from PHP**

In `includes/class-metabox.php`'s `enqueue()`, add to the `wp_localize_script` array:

```php
'authorEmail'        => $post ? get_the_author_meta( 'user_email', $post->post_author ) : '',
```

In `includes/class-bulk-action.php`'s `enqueue()`, add to each post's summary:

```php
'authorEmail' => get_the_author_meta( 'user_email', $p->post_author ),
```

- [x] **Step 6: Wire the new field through metabox.js and bulk-action.js**

In `assets/src/metabox.js`, add `authorEmail: data.authorEmail` to the `prepareMint()` call in `tokenizePost`.

In `assets/src/bulk-action.js`, add `authorEmail: post.authorEmail` to the `prepareMint()` call inside `tokenizeBulk`'s loop.

- [x] **Step 7: Run the full JS suite and rebuild**

Run: `npm test && npm run build`
Expected: All tests pass; build succeeds.

- [x] **Step 8: Commit**

```bash
git add assets/src/mint-flow.js assets/src/metabox.js assets/src/bulk-action.js includes/class-metabox.php includes/class-bulk-action.php tests/js/mint-flow.test.js assets/dist
git commit -m "feat: mint to the post author's provisioned wallet, not the signer's own address"
```

---

## Final Verification

- [x] Run the complete JS suite: `npm run build && npm test` — expect all passing.
- [x] Run the complete PHP suite: `WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_TESTS_PHPUNIT_POLYFILLS_PATH=<path> phpunit -c phpunit.xml.dist` — expect all passing.
- [x] Update `CLAUDE.md`'s Architecture section with the provisioning flow (this is exactly the kind of non-obvious mechanism that doc exists to capture — see its own guidance on what belongs there).
- [ ] Manually verify on a real WordPress install with a real wallet extension: tokenize a post as one WP user, confirm the resulting token's on-chain owner is a *different* address than the admin's own connected wallet, and that address resolves back to the author's registered email via `medialane-backend`'s own identity lookup.
- [ ] Confirm the case where the same author's email already has a wallet (a second post from the same author) reuses it instead of deploying a new one each time — `business-provisioning`'s own `findExistingWalletForRecipient` should handle this, but it's worth confirming against the real backend rather than trusting the read.
