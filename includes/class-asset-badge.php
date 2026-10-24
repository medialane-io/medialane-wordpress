<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetBadge {

	const ASSET_BASE_URL = 'https://medialane.io/asset/starknet';

	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'append_to_content' ) );
	}

	public static function append_to_content( string $content ): string {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		return $content . self::render( get_the_ID() );
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

		ob_start();
		self::print_styles_once();
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

	private static function print_styles_once() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.tokenize-content-asset-badge { display: flex; gap: 1.25rem; align-items: center; margin: 2rem 0; padding: 1.25rem; border: 1px solid #e2e2e2; border-radius: 8px; background: #fafafa; }
			.tokenize-content-asset-badge__image img { display: block; width: 96px; height: 96px; object-fit: cover; border-radius: 6px; }
			.tokenize-content-asset-badge__eyebrow { margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #6b6b6b; }
			.tokenize-content-asset-badge__title { margin: 0 0 0.25rem; font-weight: 600; }
			.tokenize-content-asset-badge__license { margin: 0 0 0.5rem; font-size: 0.875rem; color: #6b6b6b; }
			.tokenize-content-asset-badge__link { font-size: 0.875rem; font-weight: 600; text-decoration: none; }
		</style>
		<?php
	}
}
