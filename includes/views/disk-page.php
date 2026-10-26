<?php
/**
 * Disk Space admin view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tree    = EZM_Disk::get_tree();
$history = EZM_Disk::get_history();
$job     = get_transient( EZM_Disk::JOB_TRANSIENT );
?>
<div class="wrap ezm-wrap" id="ezm-disk-page">
	<h1><?php esc_html_e( 'EZ Maintenance — Disk Space', 'ezm-security-settings' ); ?></h1>

	<div class="ezm-disk-toolbar">
		<button type="button" class="button button-primary" id="ezm-disk-rescan"><?php esc_html_e( 'Rescan', 'ezm-security-settings' ); ?></button>
		<span id="ezm-disk-status" class="ezm-status">
			<?php
			if ( is_array( $job ) && 'running' === ( $job['status'] ?? '' ) ) {
				esc_html_e( 'Scan in progress…', 'ezm-security-settings' );
			} elseif ( $tree ) {
				printf(
					/* translators: 1: datetime, 2: size */
					esc_html__( 'Last scanned: %1$s · Total: %2$s', 'ezm-security-settings' ),
					esc_html( $tree['scanned_at'] ?? '' ),
					esc_html( EZM_Disk::format_bytes( $tree['total'] ?? 0 ) )
				);
			} else {
				esc_html_e( 'No scan yet.', 'ezm-security-settings' );
			}
			?>
		</span>
	</div>

	<h2><?php esc_html_e( '90-day growth', 'ezm-security-settings' ); ?></h2>
	<div id="ezm-disk-chart" class="ezm-chart" data-history="<?php echo esc_attr( wp_json_encode( array_values( $history ) ) ); ?>"></div>

	<h2><?php esc_html_e( 'Folder tree', 'ezm-security-settings' ); ?></h2>
	<div id="ezm-disk-tree" class="ezm-tree">
		<?php if ( $tree && ! empty( $tree['nodes'] ) ) : ?>
			<?php
			$nodes      = $tree['nodes'];
			$root_total = max( 1, (int) ( $tree['total'] ?? 1 ) );
			$render     = function ( $rel, $depth ) use ( &$render, $nodes, $root_total ) {
				if ( ! isset( $nodes[ $rel ] ) ) {
					return;
				}
				$n        = $nodes[ $rel ];
				$total    = (int) $n['total'];
				$pct      = round( ( $total / $root_total ) * 100, 1 );
				$bloat    = EZM_Disk::is_bloat_path( $rel ) ? ' ezm-bloat' : '';
				$children = isset( $n['children'] ) && is_array( $n['children'] ) ? $n['children'] : array();
				$has_kids = count( $children ) > 0;
				$label    = ( '' === $rel ) ? ( $n['name'] ? $n['name'] : __( '(site root)', 'ezm-security-settings' ) ) : $n['name'];
				// Root + wp-content start open; everything else collapsed.
				$open_default = ( '' === $rel ) || ( 'wp-content' === $rel ) || ( 'wp-content' === basename( $rel ) );
				$state_class  = $has_kids ? ( $open_default ? '' : ' is-collapsed' ) : ' is-leaf';

				echo '<div class="ezm-tree-branch' . esc_attr( $state_class . $bloat ) . '">';
				echo '<div class="ezm-tree-row">';
				if ( $has_kids ) {
					echo '<button type="button" class="ezm-tree-toggle" aria-expanded="' . ( $open_default ? 'true' : 'false' ) . '" title="' . esc_attr__( 'Expand / collapse', 'ezm-security-settings' ) . '"><span class="ezm-tree-caret" aria-hidden="true"></span></button>';
				} else {
					echo '<span class="ezm-tree-toggle-spacer" aria-hidden="true"></span>';
				}
				echo '<span class="ezm-tree-name">' . esc_html( $label ) . '</span> ';
				echo '<span class="ezm-tree-size">' . esc_html( EZM_Disk::format_bytes( $total ) ) . '</span> ';
				echo '<span class="ezm-tree-pct">' . esc_html( (string) $pct ) . '%</span>';
				echo '</div>';

				if ( $has_kids ) {
					echo '<div class="ezm-tree-children"' . ( $open_default ? '' : ' hidden' ) . '>';
					$shown = 0;
					foreach ( $children as $child ) {
						if ( $shown >= 40 && $depth > 0 ) {
							echo '<div class="ezm-tree-more">…</div>';
							break;
						}
						$render( $child, $depth + 1 );
						$shown++;
					}
					echo '</div>';
				}
				echo '</div>';
			};
			$render( '', 0 );
			?>
		<?php else : ?>
			<p><?php esc_html_e( 'Run a rescan to build the folder size tree.', 'ezm-security-settings' ); ?></p>
		<?php endif; ?>
	</div>
</div>
