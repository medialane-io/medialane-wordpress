#!/usr/bin/env bash
set -eo pipefail

DB_NAME=${1-medialane_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$WP_TESTS_DIR/src}

download() {
	curl -s "$1" > "$2"
}

if [ "$WP_VERSION" = "latest" ]; then
	WP_TESTS_TAG="trunk"
else
	WP_TESTS_TAG="tags/$WP_VERSION"
fi

svn export --quiet --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn export --quiet --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"

svn export --quiet --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/src/" "$WP_CORE_DIR"

mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
