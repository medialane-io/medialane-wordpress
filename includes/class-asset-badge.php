<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetBadge {

	const ASSET_BASE_URL = 'https://medialane.io/asset/starknet';
	const BLOCK          = 'tokenize-content/badge';
	const SHORTCODE      = 'tokenize_content_badge';
	const STYLE          = 'tokenize-content-badge';
	const BLOCK_SCRIPT   = 'tokenize-content-badge-block';

	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'append_to_content' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		wp_register_style( self::STYLE, TOKENIZE_CONTENT_PLUGIN_URL . 'assets/badge.css', array(), TOKENIZE_CONTENT_PLUGIN_VERSION );
		wp_register_script( self::BLOCK_SCRIPT, TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/badge-block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_set_script_translations( self::BLOCK_SCRIPT, 'tokenize-content' );
		register_block_type( TOKENIZE_CONTENT_PLUGIN_DIR . 'blocks/badge', array(
			'render_callback' => array( __CLASS__, 'render_block' ),
		) );
	}

	public static function append_to_content( string $content ): string {
		$post = get_post();
		if ( ! $post || ! is_singular( Eligibility::post_types() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( has_block( self::BLOCK, $post ) || has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return $content;
		}
		return $content . self::render( $post->ID );
	}

	public static function render_block( array $attributes = array(), string $content = '', $block = null ): string {
		$post_id = $block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
		return self::render( $post_id );
	}

	public static function render_shortcode(): string {
		return self::render( (int) get_the_ID() );
	}

	public static function url_for_post( int $post_id ): string {
		if ( PostMeta::STATUS_MINTED !== PostMeta::get_status( $post_id ) ) {
			return '';
		}
		$contract = PostMeta::get_contract( $post_id );
		$token_id = PostMeta::get_token_id( $post_id );
		if ( ! $contract || ! $token_id ) {
			return '';
		}
		return self::ASSET_BASE_URL . '/' . rawurlencode( $contract ) . '/' . rawurlencode( $token_id );
	}

	public static function render( int $post_id ): string {
		$asset_url = self::url_for_post( $post_id );
		if ( '' === $asset_url ) {
			return '';
		}
		$license = PostMeta::get_license( $post_id );
		$image     = get_the_post_thumbnail( $post_id, 'medium' );

		wp_enqueue_style( self::STYLE );
		ob_start();
		?>
		<div class="tokenize-content-asset-badge">
			<?php if ( $image ) : ?>
				<div class="tokenize-content-asset-badge__image"><?php echo $image; ?></div>
			<?php endif; ?>
			<div class="tokenize-content-asset-badge__body">
				<p class="tokenize-content-asset-badge__eyebrow"><?php esc_html_e( 'IP Protected & Tokenized', 'tokenize-content' ); ?></p>
				<p class="tokenize-content-asset-badge__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
				<?php if ( $license ) : ?>
					<p class="tokenize-content-asset-badge__license"><?php echo esc_html( $license ); ?></p>
				<?php endif; ?>
				<a class="tokenize-content-asset-badge__link" href="<?php echo esc_url( $asset_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View this asset on Medialane', 'tokenize-content' ); ?>
				</a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
