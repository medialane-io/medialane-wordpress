<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TokenizePage {

	const SLUG     = 'tokenize-content-tokenize';
	const HOOK     = 'posts_page_tokenize-content-tokenize';
	const PER_PAGE = 50;

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php',
			__( 'Tokenize with Medialane', 'tokenize-content' ),
			__( 'Tokenize', 'tokenize-content' ),
			Settings::CAP_TOKENIZE,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( string $hook ) {
		if ( self::HOOK !== $hook || ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return;
		}
		Scripts::enqueue( 'tokenize-content-tokenize-page', 'tokenize-page.js', Settings::script_data() );
	}

	public static function pending( int $paged = 1 ): \WP_Query {
		return new \WP_Query( array(
			'post_type'      => Eligibility::post_types(),
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => PostMeta::KEY_STATUS, 'compare' => 'NOT EXISTS' ),
				array( 'key' => PostMeta::KEY_STATUS, 'value' => PostMeta::STATUS_MINTED, 'compare' => '!=' ),
			),
		) );
	}

	public static function render() {
		if ( ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return;
		}
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$query = self::pending( $paged );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tokenize with Medialane', 'tokenize-content' ); ?></h1>
			<?php if ( ! $query->have_posts() ) : ?>
				<p><?php esc_html_e( 'Nothing is waiting to be tokenized.', 'tokenize-content' ); ?></p>
			<?php else : ?>
				<div id="tokenize-content-page-status"></div>
				<p>
					<button type="button" class="button button-primary" id="tokenize-content-page-run"><?php esc_html_e( 'Tokenize selected', 'tokenize-content' ); ?></button>
				</p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" id="tokenize-content-page-all" /></td>
							<th><?php esc_html_e( 'Title', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Type', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Date', 'tokenize-content' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tokenize-content' ); ?></th>
						</tr>
					</thead>
					<tbody id="tokenize-content-page-rows">
						<?php foreach ( $query->posts as $post ) : ?>
							<?php self::render_row( $post ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$links = paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => max( 1, $paged ),
					'total'   => (int) $query->max_num_pages,
				) );
				if ( $links ) {
					echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_row( \WP_Post $post ) {
		$status   = PostMeta::get_status( $post->ID );
		$disabled = PostMeta::STATUS_MINTING === $status || ! current_user_can( 'edit_post', $post->ID );
		$type     = get_post_type_object( $post->post_type );
		?>
		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="tokenize_content_ids[]" value="<?php echo esc_attr( $post->ID ); ?>" data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>" <?php disabled( $disabled ); ?> />
			</th>
			<td><?php echo esc_html( get_the_title( $post ) ); ?></td>
			<td><?php echo esc_html( $type ? $type->labels->singular_name : $post->post_type ); ?></td>
			<td><?php echo esc_html( get_the_date( '', $post ) ); ?></td>
			<td><?php self::render_status( $post->ID, $status ); ?></td>
		</tr>
		<?php
	}

	private static function render_status( int $post_id, string $status ) {
		if ( PostMeta::STATUS_MINTING === $status ) {
			esc_html_e( 'In progress', 'tokenize-content' );
		} elseif ( PostMeta::STATUS_ERROR === $status ) {
			printf( '%s: %s', esc_html__( 'Failed', 'tokenize-content' ), esc_html( PostMeta::get_error( $post_id ) ) );
		} else {
			echo '&mdash;';
		}
	}
}
