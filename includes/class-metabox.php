<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metabox {

	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add() {
		add_meta_box( 'tokenize-content-mint', __( 'Tokenize & Protect', 'tokenize-content' ), array( __CLASS__, 'render' ), 'post', 'side', 'default' );
	}

	public static function render( \WP_Post $post ) {
		$status = PostMeta::get_status( $post->ID );
		?>
		<div id="tokenize-content-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="tokenize-content-metabox-body">
				<?php if ( PostMeta::STATUS_MINTED === $status ) : ?>
					<p><?php esc_html_e( 'Minted', 'tokenize-content' ); ?></p>
					<p><a href="https://voyager.online/tx/<?php echo esc_attr( get_post_meta( $post->ID, PostMeta::KEY_TX_HASH, true ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View transaction', 'tokenize-content' ); ?></a></p>
				<?php elseif ( PostMeta::STATUS_MINTING === $status && PostMeta::get_tx_hash( $post->ID ) ) : ?>
					<p><?php esc_html_e( 'In progress', 'tokenize-content' ); ?></p>
					<button type="button" class="button" id="tokenize-content-check-btn"
						data-tx-hash="<?php echo esc_attr( PostMeta::get_tx_hash( $post->ID ) ); ?>"
						data-contract="<?php echo esc_attr( PostMeta::get_contract( $post->ID ) ); ?>"
						data-license="<?php echo esc_attr( PostMeta::get_license( $post->ID ) ); ?>"
						data-index="<?php echo esc_attr( PostMeta::get_batch_index( $post->ID ) ); ?>"><?php esc_html_e( 'Check status', 'tokenize-content' ); ?></button>
				<?php elseif ( ! Eligibility::is_tokenizable( $post ) ) : ?>
					<p><?php echo esc_html( Eligibility::message() ); ?></p>
				<?php else : ?>
					<?php $fetched = Settings::fetch_live_collections(); ?>
					<?php $collections = is_wp_error( $fetched ) ? array() : $fetched; ?>
					<?php if ( is_wp_error( $fetched ) ) : ?>
						<p class="description"><?php esc_html_e( 'Collections could not be loaded right now. Reload to try again.', 'tokenize-content' ); ?></p>
					<?php endif; ?>
					<?php if ( count( $collections ) > 1 ) : ?>
						<p>
							<label for="tokenize-content-collection"><?php esc_html_e( 'Collection', 'tokenize-content' ); ?></label>
							<select id="tokenize-content-collection">
								<?php $resolved = Settings::resolve_collection_for_post( $post->ID, $collections ); ?>
								<?php foreach ( $collections as $collection ) : ?>
									<option value="<?php echo esc_attr( $collection['contract'] ); ?>" data-collection-id="<?php echo esc_attr( $collection['collectionId'] ); ?>" <?php selected( $resolved ? $resolved['contract'] : '', $collection['contract'] ); ?>><?php echo esc_html( $collection['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>
					<p>
						<label for="tokenize-content-license"><?php esc_html_e( 'License', 'tokenize-content' ); ?></label>
						<select id="tokenize-content-license">
							<?php foreach ( Settings::LICENSE_PRESETS as $preset ) : ?>
								<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( Settings::get_license_default(), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><textarea id="tokenize-content-license-custom" placeholder="<?php esc_attr_e( 'Custom license terms (used if License = Custom)', 'tokenize-content' ); ?>" style="display:none;width:100%;"></textarea></p>
					<button type="button" class="button button-primary" id="tokenize-content-tokenize-btn"><?php esc_html_e( 'Tokenize Post', 'tokenize-content' ); ?></button>
					<?php if ( PostMeta::STATUS_ERROR === $status ) : ?>
						<p class="tokenize-content-error" style="color:#b32d2e;"><?php echo esc_html( PostMeta::get_error( $post->ID ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function enqueue( string $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		global $post;
		wp_enqueue_script( 'tokenize-content-metabox', TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/metabox.js', array(), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_localize_script( 'tokenize-content-metabox', 'tokenizeContentData', array(
			'restUrl'            => esc_url_raw( rest_url( 'tokenize-content/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'walletAddress'      => Settings::get_wallet_address(),
			'aiPolicyDefault'    => Settings::get_ai_policy_default(),
			'postId'             => $post ? $post->ID : 0,
		) );
	}
}
