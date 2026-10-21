<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION_API_KEY    = 'tokenize_content_api_key';
	const OPTION_WALLET     = 'tokenize_content_wallet_address';
	const OPTION_COLLECTION = 'tokenize_content_collection_contract';
	const OPTION_COLLECTION_LABELS = 'tokenize_content_collection_labels';
	const OPTION_CATEGORY_MAP = 'tokenize_content_category_collections';
	const OPTION_LICENSE_DEFAULT = 'tokenize_content_license_default';
	const OPTION_AI_POLICY_DEFAULT = 'tokenize_content_ai_policy_default';
	const OPTION_CONTENT_SCOPE   = 'tokenize_content_content_scope';
	const CAP_TOKENIZE = 'tokenize_content_tokenize_posts';
	const GROUP_KEY      = 'tokenize-content-key';
	const GROUP_DEFAULTS = 'tokenize-content-defaults';

	const LICENSE_PRESETS = array(
		'CC BY-SA', 'CC BY', 'CC BY-NC', 'CC BY-ND', 'CC BY-NC-SA', 'CC BY-NC-ND',
		'CC0', 'MIT', 'Apache 2.0', 'All Rights Reserved', 'Custom',
	);
	const AI_POLICIES = array( 'Allowed', 'Training Only', 'Not Allowed' );

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( string $hook ) {
		if ( 'settings_page_tokenize-content-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'tokenize-content-settings', TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/settings.js', array(), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_localize_script( 'tokenize-content-settings', 'tokenizeContentData', array(
			'restUrl'            => esc_url_raw( rest_url( 'tokenize-content/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'siteName'           => get_bloginfo( 'name' ),
			'collectionContract' => self::get_default_collection(),
			'walletAddress'      => self::get_wallet_address(),
		) );
	}

	public static function add_settings_page() {
		add_options_page(
			__( 'Tokenize, Protect & License Your Content', 'tokenize-content' ),
			__( 'Tokenize & Protect', 'tokenize-content' ),
			'manage_options',
			'tokenize-content-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( self::GROUP_KEY, self::OPTION_API_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ) ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_LICENSE_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_AI_POLICY_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_CONTENT_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function grant_default_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_TOKENIZE ) ) {
			$role->add_cap( self::CAP_TOKENIZE );
		}
	}

	public static function sanitize_api_key( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? (string) get_option( self::OPTION_API_KEY, '' ) : $value;
	}

	public static function canonical_address( string $address ): string {
		$address = strtolower( trim( $address ) );
		if ( 0 !== strpos( $address, '0x' ) ) {
			return $address;
		}
		$digits = ltrim( substr( $address, 2 ), '0' );
		return '0x' . ( '' === $digits ? '0' : $digits );
	}

	public static function sanitize_address( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^0x[0-9a-f]+$/', $value ) ? $value : '';
	}

	public static function get_api_key(): string {
		if ( defined( 'TOKENIZE_CONTENT_API_KEY' ) && TOKENIZE_CONTENT_API_KEY ) {
			return (string) TOKENIZE_CONTENT_API_KEY;
		}
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	public static function get_wallet_address(): string {
		return (string) get_option( self::OPTION_WALLET, '' );
	}

	public static function save_wallet_address( string $address ) {
		update_option( self::OPTION_WALLET, self::sanitize_address( $address ) );
	}

	public static function get_default_collection(): string {
		return (string) get_option( self::OPTION_COLLECTION, '' );
	}

	public static function set_default_collection( string $address ) {
		update_option( self::OPTION_COLLECTION, self::sanitize_address( $address ) );
	}

	public static function get_collection_labels(): array {
		$labels = get_option( self::OPTION_COLLECTION_LABELS, array() );
		return is_array( $labels ) ? $labels : array();
	}

	public static function save_collection_label( string $contract, string $label ) {
		$contract = self::sanitize_address( $contract );
		$label    = sanitize_text_field( $label );
		$labels   = self::get_collection_labels();

		$labels[ $contract ] = $label;
		update_option( self::OPTION_COLLECTION_LABELS, $labels );

		if ( ! self::get_default_collection() ) {
			self::set_default_collection( $contract );
		}
	}

	public static function fetch_live_collections(): array {
		$result = self::fetch_live_collections_checked();
		return is_wp_error( $result ) ? array() : $result;
	}

	public static function fetch_live_collections_checked() {
		$key   = self::get_api_key();
		$owner = self::get_wallet_address();
		if ( ! $key || ! $owner ) {
			return array();
		}

		$response = wp_remote_get( TOKENIZE_CONTENT_BACKEND_URL . '/v1/collections?owner=' . rawurlencode( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'tokenize_content_collections_unavailable', 'Collections could not be loaded.', array( 'status' => $status ) );
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : array();

		$labels = array();
		foreach ( self::get_collection_labels() as $stored => $label ) {
			$labels[ self::canonical_address( (string) $stored ) ] = $label;
		}
		$collections = array();
		foreach ( $items as $item ) {
			if ( empty( $item['contractAddress'] ) ) {
				continue;
			}
			$contract      = (string) $item['contractAddress'];
			$collections[] = array(
				'contract' => $contract,
				'label'    => isset( $labels[ self::canonical_address( $contract ) ] ) ? $labels[ self::canonical_address( $contract ) ] : $contract,
			);
		}
		return $collections;
	}

	public static function get_category_map(): array {
		$map = get_option( self::OPTION_CATEGORY_MAP, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function save_category_map( array $map ) {
		$sanitized = array();
		foreach ( $map as $category_id => $contract ) {
			$contract = self::sanitize_address( (string) $contract );
			if ( $contract ) {
				$sanitized[ (int) $category_id ] = $contract;
			}
		}
		update_option( self::OPTION_CATEGORY_MAP, $sanitized );
	}

	public static function resolve_collection_for_post( int $post_id, array $live_collections ): string {
		$valid = wp_list_pluck( $live_collections, 'contract' );
		$find  = function ( string $wanted ) use ( $valid ): string {
			foreach ( $valid as $contract ) {
				if ( '' !== $wanted && self::canonical_address( (string) $contract ) === self::canonical_address( $wanted ) ) {
					return (string) $contract;
				}
			}
			return '';
		};

		$map = self::get_category_map();
		if ( $map ) {
			foreach ( get_the_category( $post_id ) as $category ) {
				if ( isset( $map[ $category->term_id ] ) && '' !== $find( $map[ $category->term_id ] ) ) {
					return $find( $map[ $category->term_id ] );
				}
			}
		}

		$default = $find( self::get_default_collection() );
		if ( '' !== $default ) {
			return $default;
		}

		return $valid ? (string) $valid[0] : '';
	}

	public static function get_content_scope(): string {
		$scope = get_option( self::OPTION_CONTENT_SCOPE, 'excerpt' );
		return in_array( $scope, array( 'excerpt', 'full' ), true ) ? $scope : 'excerpt';
	}

	public static function get_license_default(): string {
		$license = get_option( self::OPTION_LICENSE_DEFAULT, '' );
		return $license ? (string) $license : 'All Rights Reserved';
	}

	public static function get_ai_policy_default(): string {
		$policy = get_option( self::OPTION_AI_POLICY_DEFAULT, '' );
		return $policy ? (string) $policy : 'Allowed';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$api_key_set       = (bool) self::get_api_key();
		$wallet_connected  = (bool) self::get_wallet_address();
		?>
		<div class="wrap">
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
					<?php settings_fields( self::GROUP_KEY ); ?>
					<table class="form-table">
						<tr>
							<th><label for="tokenize_content_api_key"><?php esc_html_e( 'API Key', 'tokenize-content' ); ?></label></th>
							<td><input type="password" id="tokenize_content_api_key" name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" value="" placeholder="<?php echo esc_attr( $api_key_set ? __( 'A key is saved. Enter a new key to replace it.', 'tokenize-content' ) : '' ); ?>" class="regular-text" autocomplete="off" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save API Key', 'tokenize-content' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! $api_key_set ) : ?>
				<p class="description"><?php esc_html_e( 'Add your API key and save it to continue setup.', 'tokenize-content' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<h2><?php esc_html_e( 'Wallet', 'tokenize-content' ); ?></h2>
			<p><strong><?php esc_html_e( 'Wallet:', 'tokenize-content' ); ?></strong> <span id="tokenize-content-wallet-status"><?php echo esc_html( self::get_wallet_address() ? self::get_wallet_address() : __( 'Not connected', 'tokenize-content' ) ); ?></span></p>
			<p><button type="button" id="tokenize-content-connect-wallet" class="button"><?php esc_html_e( 'Connect Wallet', 'tokenize-content' ); ?></button></p>

			<?php if ( ! $wallet_connected ) : ?>
				<p class="description"><?php esc_html_e( 'Connect the wallet that will sign every tokenize action on this site to continue setup.', 'tokenize-content' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<p><?php echo wp_kses_post( sprintf(
				__( 'Setup is complete. Head to %s to tokenize a post.', 'tokenize-content' ),
				'<a href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Posts', 'tokenize-content' ) . '</a>'
			) ); ?></p>

			<h2><?php esc_html_e( 'Tokenization defaults', 'tokenize-content' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP_DEFAULTS ); ?>
				<table class="form-table">
					<tr>
						<th><label for="tokenize_content_content_scope"><?php esc_html_e( 'Post content to tokenize', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_content_scope" name="<?php echo esc_attr( self::OPTION_CONTENT_SCOPE ); ?>">
								<option value="excerpt" <?php selected( self::get_content_scope(), 'excerpt' ); ?>><?php esc_html_e( 'Excerpt only', 'tokenize-content' ); ?></option>
								<option value="full" <?php selected( self::get_content_scope(), 'full' ); ?>><?php esc_html_e( 'Full post body', 'tokenize-content' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tokenize_content_license_default"><?php esc_html_e( 'Default license', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_license_default" name="<?php echo esc_attr( self::OPTION_LICENSE_DEFAULT ); ?>">
								<?php foreach ( self::LICENSE_PRESETS as $preset ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( self::get_license_default(), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used to tokenize posts in bulk, and pre-selected when tokenizing a single post.', 'tokenize-content' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tokenize_content_ai_policy_default"><?php esc_html_e( 'Default AI policy', 'tokenize-content' ); ?></label></th>
						<td>
							<select id="tokenize_content_ai_policy_default" name="<?php echo esc_attr( self::OPTION_AI_POLICY_DEFAULT ); ?>">
								<?php foreach ( self::AI_POLICIES as $policy ) : ?>
									<option value="<?php echo esc_attr( $policy ); ?>" <?php selected( self::get_ai_policy_default(), $policy ); ?>><?php echo esc_html( $policy ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Whether AI systems may use tokenized content for training. Recorded on-chain as part of the license, alongside commercial-use and remix terms.', 'tokenize-content' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php $fetched = self::fetch_live_collections_checked(); ?>
			<?php $load_failed = is_wp_error( $fetched ); ?>
			<?php $collections = $load_failed ? array() : $fetched; ?>
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
								<td><?php echo $collection['contract'] === self::get_default_collection() ? esc_html__( 'Yes', 'tokenize-content' ) : ''; ?></td>
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

			<h2><?php esc_html_e( 'Create a new collection', 'tokenize-content' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A separate collection to mint into — useful for organizing tokenized posts by section, author, or archive. The first collection you create becomes the default.', 'tokenize-content' ); ?></p>
			<p>
				<input type="text" id="tokenize-content-new-collection-label" placeholder="<?php esc_attr_e( 'Collection name, e.g. Politics', 'tokenize-content' ); ?>" class="regular-text" />
				<button type="button" id="tokenize-content-create-collection" class="button"><?php esc_html_e( 'Create Collection', 'tokenize-content' ); ?></button>
			</p>

			<?php if ( count( $collections ) > 1 ) : ?>
				<h2><?php esc_html_e( 'Route categories to collections', 'tokenize-content' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Posts in a mapped category are tokenized into that collection instead of the default. Bulk tokenization and the per-post editor both use this.', 'tokenize-content' ); ?></p>
				<?php $category_map = self::get_category_map(); ?>
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
											<option value="<?php echo esc_attr( $collection['contract'] ); ?>" <?php selected( isset( $category_map[ $category->term_id ] ) ? $category_map[ $category->term_id ] : '', $collection['contract'] ); ?>><?php echo esc_html( $collection['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" id="tokenize-content-save-category-map" class="button button-primary"><?php esc_html_e( 'Save Mapping', 'tokenize-content' ); ?></button></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
