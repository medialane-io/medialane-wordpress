<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	const HOOK = 'settings_page_tokenize-content-settings';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_page() {
		add_options_page(
			__( 'Tokenize, Protect & License Your Content', 'tokenize-content' ),
			__( 'Tokenize & Protect', 'tokenize-content' ),
			'manage_options',
			'tokenize-content-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( string $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}
		wp_enqueue_script( 'tokenize-content-settings', TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/settings.js', array(), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_localize_script( 'tokenize-content-settings', 'tokenizeContentData', array(
			'restUrl'       => esc_url_raw( rest_url( 'tokenize-content/v1' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'siteName'      => get_bloginfo( 'name' ),
			'walletAddress' => Settings::get_wallet_address(),
		) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<?php self::render_intro(); ?>
			<?php self::render_api_key(); ?>
			<?php
			if ( ! Settings::get_api_key() ) {
				self::render_gate( __( 'Add your API key and save it to continue setup.', 'tokenize-content' ) );
				return;
			}
			self::render_wallet();
			if ( ! Settings::get_wallet_address() ) {
				self::render_gate( __( 'Connect the wallet that will sign every tokenize action on this site to continue setup.', 'tokenize-content' ) );
				return;
			}
			self::render_setup_complete();
			self::render_defaults();
			$fetched     = Settings::fetch_live_collections();
			$load_failed = is_wp_error( $fetched );
			$collections = $load_failed ? array() : $fetched;
			self::render_collections( $collections, $load_failed );
			self::render_create_collection();
			if ( count( $collections ) > 1 ) {
				self::render_category_map( $collections );
			}
			?>
		</div>
		<?php
	}

	private static function render_gate( string $message ) {
		?>
		<p class="description"><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private static function render_intro() {
		?>
			<h1><?php esc_html_e( 'Tokenize, Protect & License Your Content', 'tokenize-content' ); ?></h1>
			<p style="max-width:640px;font-size:14px;">
				<?php esc_html_e( 'This plugin turns your posts into protected IP assets through Medialane. Each tokenized post carries a permanent, checkable record of who wrote it, plus a clear license that says what other people are allowed to do with it.', 'tokenize-content' ); ?>
			</p>

			<div class="card" style="max-width:640px;margin:16px 0;padding:1px 20px 20px;">
				<h2><?php esc_html_e( 'What this does', 'tokenize-content' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;">
					<li><?php esc_html_e( 'Proof of authorship. A permanent, public record of who wrote a post and when, independent of this site staying online.', 'tokenize-content' ); ?></li>
					<li><?php esc_html_e( 'Real licensing terms. Choose a Creative Commons preset or reserve all rights, plus a policy on AI training use, applied automatically when a post is tokenized.', 'tokenize-content' ); ?></li>
					<li><?php esc_html_e( 'No wallet needed for your writers. Only the site admin connects one, once. Authors are identified by their WordPress email and never have to do anything themselves.', 'tokenize-content' ); ?></li>
					<li><?php esc_html_e( 'A public badge on every tokenized post, linking to its permanent record so readers can verify it themselves.', 'tokenize-content' ); ?></li>
				</ul>
				<h2><?php esc_html_e( 'How it works', 'tokenize-content' ); ?></h2>
				<ol style="padding-left:20px;">
					<li><?php esc_html_e( 'Add your Medialane API key below.', 'tokenize-content' ); ?></li>
					<li><?php esc_html_e( 'Connect the wallet that will manage tokenization for this site.', 'tokenize-content' ); ?></li>
					<li><?php esc_html_e( 'Tokenize a post from its editor screen, or several at once from the Posts list.', 'tokenize-content' ); ?></li>
				</ol>
			</div>

		<?php
	}

	private static function render_api_key() {
		$api_key_set = (bool) Settings::get_api_key();
		?>
			<h2><?php esc_html_e( 'API Key', 'tokenize-content' ); ?></h2>
			<p>
				<?php
				printf(
					esc_html__( 'Generate an API key at %s, then paste it below.', 'tokenize-content' ),
					'<a href="https://portal.medialane.io/account" target="_blank" rel="noopener noreferrer">portal.medialane.io/account</a>'
				);
				?>
			</p>
			<?php if ( defined( 'TOKENIZE_CONTENT_API_KEY' ) && TOKENIZE_CONTENT_API_KEY ) : ?>
				<p><em><?php esc_html_e( 'The API key is set in wp-config.php.', 'tokenize-content' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( Settings::GROUP_KEY ); ?>
					<table class="form-table">
						<tr>
							<th><label for="tokenize_content_api_key"><?php esc_html_e( 'API Key', 'tokenize-content' ); ?></label></th>
							<td><input type="password" id="tokenize_content_api_key" name="<?php echo esc_attr( Settings::OPTION_API_KEY ); ?>" value="" placeholder="<?php echo esc_attr( $api_key_set ? __( 'A key is saved. Enter a new key to replace it.', 'tokenize-content' ) : '' ); ?>" class="regular-text" autocomplete="off" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save API Key', 'tokenize-content' ) ); ?>
				</form>
			<?php endif; ?>

		<?php
	}

	private static function render_wallet() {
		?>
			<h2><?php esc_html_e( 'Wallet', 'tokenize-content' ); ?></h2>
			<p><strong><?php esc_html_e( 'Wallet:', 'tokenize-content' ); ?></strong> <span id="tokenize-content-wallet-status"><?php echo esc_html( Settings::get_wallet_address() ? Settings::get_wallet_address() : __( 'Not connected', 'tokenize-content' ) ); ?></span></p>
			<p><button type="button" id="tokenize-content-connect-wallet" class="button"><?php esc_html_e( 'Connect Wallet', 'tokenize-content' ); ?></button></p>

		<?php
	}

	private static function render_setup_complete() {
		?>
			<p><?php echo wp_kses_post( sprintf(
				__( 'Setup is complete. Head to %s to tokenize a post.', 'tokenize-content' ),
				'<a href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Posts', 'tokenize-content' ) . '</a>'
			) ); ?></p>

		<?php
	}

	private static function render_defaults() {
		?>
			<h2><?php esc_html_e( 'Tokenization defaults', 'tokenize-content' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP_DEFAULTS ); ?>
				<table class="form-table">
					<tr>
						<th><label for="tokenize_content_content_scope"><?php esc_html_e( 'Post content to tokenize', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_content_scope" name="<?php echo esc_attr( Settings::OPTION_CONTENT_SCOPE ); ?>">
								<option value="excerpt" <?php selected( Settings::get_content_scope(), 'excerpt' ); ?>><?php esc_html_e( 'Excerpt only', 'tokenize-content' ); ?></option>
								<option value="full" <?php selected( Settings::get_content_scope(), 'full' ); ?>><?php esc_html_e( 'Full post body', 'tokenize-content' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tokenize_content_license_default"><?php esc_html_e( 'Default license', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_license_default" name="<?php echo esc_attr( Settings::OPTION_LICENSE_DEFAULT ); ?>">
								<?php foreach ( Settings::license_presets() as $preset ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( Settings::get_license_default(), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used to tokenize posts in bulk, and pre-selected when tokenizing a single post.', 'tokenize-content' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tokenize_content_ai_policy_default"><?php esc_html_e( 'Default AI policy', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_ai_policy_default" name="<?php echo esc_attr( Settings::OPTION_AI_POLICY_DEFAULT ); ?>">
								<?php foreach ( Settings::AI_POLICIES as $policy ) : ?>
									<option value="<?php echo esc_attr( $policy ); ?>" <?php selected( Settings::get_ai_policy_default(), $policy ); ?>><?php echo esc_html( $policy ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Whether AI systems may use tokenized content for training. Recorded on-chain as part of the license, alongside commercial-use and remix terms.', 'tokenize-content' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

		<?php
	}

	private static function render_collections( array $collections, bool $load_failed ) {
		?>
			<?php if ( $collections ) : ?>
				<h2><?php esc_html_e( 'Collections', 'tokenize-content' ); ?></h2>
				<table class="widefat" style="max-width:640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Contract', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Default', 'tokenize-content' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $collections as $collection ) : ?>
							<tr>
								<td><?php echo esc_html( $collection['label'] ); ?></td>
								<td><code><?php echo esc_html( $collection['contract'] ); ?></code></td>
								<td><?php echo Settings::same_address( Settings::get_default_collection(), $collection['contract'] ) ? esc_html__( 'Yes', 'tokenize-content' ) : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( $load_failed ) : ?>
				<h2><?php esc_html_e( 'Collections', 'tokenize-content' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Collections could not be loaded right now. Reload to try again.', 'tokenize-content' ); ?></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Collections', 'tokenize-content' ); ?></h2>
				<p class="description"><?php esc_html_e( 'No collections found for this wallet yet. Create one below.', 'tokenize-content' ); ?></p>
			<?php endif; ?>

		<?php
	}

	private static function render_create_collection() {
		?>
			<h2><?php esc_html_e( 'Create a new collection', 'tokenize-content' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A separate collection to mint into — useful for organizing tokenized posts by section, author, or archive. The first collection you create becomes the default.', 'tokenize-content' ); ?></p>
			<p>
				<input type="text" id="tokenize-content-new-collection-label" placeholder="<?php esc_attr_e( 'Collection name, e.g. Politics', 'tokenize-content' ); ?>" class="regular-text" />
				<button type="button" id="tokenize-content-create-collection" class="button"><?php esc_html_e( 'Create Collection', 'tokenize-content' ); ?></button>
			</p>

		<?php
	}

	private static function render_category_map( array $collections ) {
		$category_map = Settings::get_category_map();
		?>
				<h2><?php esc_html_e( 'Route categories to collections', 'tokenize-content' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Posts in a mapped category are tokenized into that collection instead of the default. Bulk tokenization and the per-post editor both use this.', 'tokenize-content' ); ?></p>
				<table class="widefat" style="max-width:640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Collection', 'tokenize-content' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) : ?>
							<tr>
								<td><?php echo esc_html( $category->name ); ?></td>
								<td>
									<select class="tokenize-content-category-collection-select" data-category-id="<?php echo esc_attr( $category->term_id ); ?>">
										<option value=""><?php esc_html_e( 'Use default collection', 'tokenize-content' ); ?></option>
										<?php foreach ( $collections as $collection ) : ?>
											<option value="<?php echo esc_attr( $collection['contract'] ); ?>" <?php selected( Settings::same_address( isset( $category_map[ $category->term_id ] ) ? $category_map[ $category->term_id ] : '', $collection['contract'] ) ); ?>><?php echo esc_html( $collection['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" id="tokenize-content-save-category-map" class="button button-primary"><?php esc_html_e( 'Save Mapping', 'tokenize-content' ); ?></button></p>
		<?php
	}
}
