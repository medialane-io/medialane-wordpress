# Tokenize, Protect & License Your Content

WordPress plugin that turns posts into licensed IP assets on [Medialane](https://medialane.io).

## Features

- Tokenize a post from its editor, or several posts at once from the Posts list. Only published posts without a password can be tokenized.
- Choose a license and an AI training policy.
- Organize posts into collections, with categories routed to a collection.
- A public badge on tokenized posts.
- Translatable interface.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Starknet wallet browser extension
- A Medialane API key from [portal.medialane.io/account](https://portal.medialane.io/account). Every request the plugin makes is paid from that account's credits.

## Installation

1. Download the latest zip from [Releases](https://github.com/medialane-io/medialane-wordpress/releases), upload it under **Plugins → Add New → Upload Plugin**, and activate it.
2. Open **Settings → Tokenize & Protect**, save your API key, and connect a wallet.
3. Tokenize from a post's editor sidebar, or from the Posts list bulk action.

## Development

```bash
npm install
npm run build
npm test
phpunit -c phpunit.xml.dist
```

`phpunit` (9.6) needs a WordPress test library: set `WP_TESTS_DIR` (see `bin/install-wp-tests.sh`)
and `WP_TESTS_PHPUNIT_POLYFILLS_PATH`.

Define `TOKENIZE_CONTENT_API_KEY` or `TOKENIZE_CONTENT_BACKEND_URL` in `wp-config.php` to keep the key out of the database or to point the plugin at another backend.

## Releases

Push a tag `vX.Y.Z` that matches the plugin version. The release workflow tests, builds and attaches `tokenize-content-X.Y.Z.zip`.

## License

GPL-2.0-or-later.
