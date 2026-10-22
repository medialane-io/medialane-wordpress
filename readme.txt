=== Tokenize, Protect & License Your Content ===
Contributors: medialane
Tags: ip, licensing, nft, wallet, starknet
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your posts into protected, licensed IP assets with Medialane.

== Description ==

Tokenize posts as licensed IP assets. Choose a Creative Commons license or reserve all rights, and set a policy on AI training use.

Tokenize one post from its editor, or several at once from the Posts list. Only published posts without a password can be tokenized. Organize posts into collections, with categories routed to a collection. Tokenized posts show a public badge.

== Source code ==

The JavaScript in `assets/dist` is built from `assets/src` with `npm install && npm run build`. The source is at https://github.com/medialane-io/medialane-wordpress.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Tokenize & Protect, save your API key (from portal.medialane.io/account), and connect a wallet.
3. Tokenize from a post's editor sidebar, or from the Posts list bulk action.

== Frequently Asked Questions ==

= Do my authors need a wallet? =

No. Only the site admin connects a wallet.

= Can I keep the API key out of the database? =

Yes. Define a `TOKENIZE_CONTENT_API_KEY` constant in `wp-config.php`.

= Who pays for the requests? =

Every request the plugin makes is paid from the credits of the Medialane account that owns the API key.

== Changelog ==

= 0.3.0 =
* Creating a collection is sponsored, like tokenizing.
* Bulk tokenizing skips posts that are already tokenized.
* Posts too long to tokenize in full are reported before anything is uploaded.
* A post is marked as tokenized only once its token is found on chain.
* The default license is always a preset with terms.
* Translation template included.

= 0.2.0 =
* License presets and an AI training policy.
* Multiple collections, with category routing.
* Public badge on tokenized posts.
* Bulk tokenizing from the Posts list.

= 0.1.0 =
* Initial release.
