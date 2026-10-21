# Tokenize, Protect & License Your Content

WordPress plugin that turns posts into licensed IP assets on [Medialane](https://medialane.io).

## Features

- Tokenize a post from its editor, or several posts at once from the Posts list.
- Choose a license and an AI training policy.
- Organize posts into collections, with categories routed to a collection.
- A public badge on tokenized posts.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Starknet wallet browser extension
- A Medialane API key from [portal.medialane.io/account](https://portal.medialane.io/account)

## Installation

1. Copy this directory to `wp-content/plugins/` and activate the plugin.
2. Open **Settings → Tokenize & Protect**, save your API key, and connect a wallet.
3. Tokenize from a post's editor sidebar, or from the Posts list bulk action.

## Development

```bash
npm install
npm run build
npm test
phpunit -c phpunit.xml.dist
```

`phpunit` needs a WordPress test library: set `WP_TESTS_DIR` (see `bin/install-wp-tests.sh`)
and `WP_TESTS_PHPUNIT_POLYFILLS_PATH`.

## License

GPL-2.0-or-later.
