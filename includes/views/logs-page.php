<?php
/**
 * Logs admin view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

$allowed_orderby = array( 'name', 'plugin', 'ctime', 'mtime', 'size' );
$orderby         = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'mtime';
if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
	$orderby = 'mtime';
}
$order = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
$order = ( 'ASC' === $order ) ? 'ASC' : 'DESC';

$logs      = EZM_Logs::list_logs(
	array(
		's'        => $search,
		'per_page' => 50,
		'orderby'  => $orderby,
		'order'    => $order,
	)
);
$wc_active = class_exists( 'WooCommerce' );

/**
 * Build a sortable column header link.
 *
 * @param string $key     Orderby key.
 * @param string $label   Column label.
 * @param string $current Current orderby.
 * @param string $cur_ord Current order ASC|DESC.
 * @param string $search  Search string.
 * @return string
 */
$ezm_sort_link = function ( $key, $label, $current, $cur_ord, $search ) {
	$next = ( $key === $current && 'ASC' === $cur_ord ) ? 'DESC' : 'ASC';
	if ( $key !== $current ) {
		$next = in_array( $key, array( 'ctime', 'mtime', 'size' ), true ) ? 'DESC' : 'ASC';
	}
	$url = add_query_arg(
		array(
			'page'    => 'ezm-logs',
			's'       => $search,
			'orderby' => $key,
			'order'   => $next,
		),
		admin_url( 'admin.php' )
	);
	$class = 'ezm-sortable';
	if ( $key === $current ) {
		$class .= ( 'ASC' === $cur_ord ) ? ' sorted asc' : ' sorted desc';
	}
	return sprintf(
		'<a href="%s" class="%s"><span>%s</span><span class="sorting-indicators" aria-hidden="true"></span></a>',
		esc_url( $url ),
		esc_attr( $class ),
		esc_html( $label )
	);
};
?>
<div class="wrap ezm-wrap">
	<h1><?php esc_html_e( 'EZ Maintenance — Logs', 'ezm-security-settings' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Find and browse error logs on this site. Exclude folders and critical size are under Settings.', 'ezm-security-settings' ); ?>
	</p>

	<?php
	$msg = isset( $_GET['ezm_log_msg'] ) ? sanitize_key( wp_unslash( $_GET['ezm_log_msg'] ) ) : '';
	if ( 'deleted' === $msg ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log file deleted.', 'ezm-security-settings' ); ?></p></div>
	<?php elseif ( 'unlink_failed' === $msg ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not delete the log file (permissions?).', 'ezm-security-settings' ); ?></p></div>
	<?php elseif ( 'missing' === $msg ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Log not found in the index.', 'ezm-security-settings' ); ?></p></div>
	<?php endif; ?>

	<?php
	$object_cache = EZM_Audit::evaluate_object_cache();
	if ( ! empty( $object_cache['exists'] ) && ! empty( $object_cache['is_redis'] ) ) :
		if ( ! empty( $object_cache['problem'] ) && empty( $object_cache['ignored'] ) ) :
			?>
			<div class="notice notice-error" id="ezm-object-cache-notice">
				<p><strong><?php echo esc_html( $object_cache['message'] ); ?></strong></p>
				<?php if ( ! empty( $object_cache['reasons'] ) ) : ?>
					<ul style="list-style:disc;margin-left:1.4em;">
						<?php foreach ( $object_cache['reasons'] as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'A leftover Redis object-cache drop-in can make the site very slow when Redis is not running. Deleting it disables the drop-in; Redis plugins recreate it only when you enable object cache again — it is not regenerated on every page load.', 'ezm-security-settings' ); ?>
				</p>
				<p>
					<button type="button" class="button" id="ezm-object-cache-ignore"><?php esc_html_e( 'Ignore', 'ezm-security-settings' ); ?></button>
					<button type="button" class="button button-link-delete" id="ezm-object-cache-delete"><?php esc_html_e( 'Delete object-cache.php', 'ezm-security-settings' ); ?></button>
				</p>
			</div>
		<?php elseif ( ! empty( $object_cache['ignored'] ) ) : ?>
			<div class="notice notice-info" id="ezm-object-cache-notice">
				<p>
					<?php esc_html_e( 'Ignoring Redis object-cache.php warning for this drop-in.', 'ezm-security-settings' ); ?>
					<button type="button" class="button button-small" id="ezm-object-cache-unignore"><?php esc_html_e( 'Stop ignoring', 'ezm-security-settings' ); ?></button>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $wc_active ) : ?>
		<div class="notice notice-info inline"><p>
			<?php
			printf(
				/* translators: %s: URL */
				esc_html__( 'Looking for WooCommerce logs? %s', 'ezm-security-settings' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'Go to WooCommerce → Status → Logs', 'ezm-security-settings' ) . '</a>'
			);
			?>
		</p></div>
	<?php endif; ?>

	<div class="ezm-disk-toolbar">
		<button type="button" class="button button-primary" id="ezm-logs-refresh"><?php esc_html_e( 'Refresh index', 'ezm-security-settings' ); ?></button>
		<span id="ezm-logs-refresh-status" class="ezm-status">
			<?php
			printf(
				/* translators: %s: size */
				esc_html__( 'Tracked: %s', 'ezm-security-settings' ),
				esc_html( size_format( EZM_Logs::tracked_total_bytes() ) )
			);
			?>
		</span>
	</div>

	<form method="get" class="ezm-search-form">
		<input type="hidden" name="page" value="ezm-logs" />
		<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
		<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search paths…', 'ezm-security-settings' ); ?>" />
		<button class="button"><?php esc_html_e( 'Search', 'ezm-security-settings' ); ?></button>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="manage-column column-primary <?php echo 'name' === $orderby ? 'sorted ' . esc_attr( strtolower( $order ) ) : 'sortable'; ?>">
					<?php echo $ezm_sort_link( 'name', __( 'Name', 'ezm-security-settings' ), $orderby, $order, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</th>
				<th scope="col" class="manage-column <?php echo 'plugin' === $orderby ? 'sorted ' . esc_attr( strtolower( $order ) ) : 'sortable'; ?>">
					<?php echo $ezm_sort_link( 'plugin', __( 'Plugin', 'ezm-security-settings' ), $orderby, $order, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</th>
				<th scope="col" class="manage-column <?php echo 'ctime' === $orderby ? 'sorted ' . esc_attr( strtolower( $order ) ) : 'sortable'; ?>">
					<?php echo $ezm_sort_link( 'ctime', __( 'Created', 'ezm-security-settings' ), $orderby, $order, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</th>
				<th scope="col" class="manage-column <?php echo 'mtime' === $orderby ? 'sorted ' . esc_attr( strtolower( $order ) ) : 'sortable'; ?>">
					<?php echo $ezm_sort_link( 'mtime', __( 'Modified', 'ezm-security-settings' ), $orderby, $order, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</th>
				<th scope="col" class="manage-column <?php echo 'size' === $orderby ? 'sorted ' . esc_attr( strtolower( $order ) ) : 'sortable'; ?>">
					<?php echo $ezm_sort_link( 'size', __( 'Size', 'ezm-security-settings' ), $orderby, $order, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $logs ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No logs indexed yet. Click Refresh index.', 'ezm-security-settings' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $logs as $row ) : ?>
				<?php
				$crit         = ( (int) $row['file_size'] ) >= ( (int) EZM_Settings::get( 'critical_log_mb', 20 ) * 1024 * 1024 );
				$label        = ! empty( $row['source_label'] ) ? $row['source_label'] : $row['plugin_slug'];
				$download_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'ezm_logs_download',
							'hash'   => $row['file_hash'],
						),
						admin_url( 'admin-post.php' )
					),
					'ezm_logs_download_' . $row['file_hash']
				);
				$delete_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'ezm_logs_delete',
							'hash'   => $row['file_hash'],
						),
						admin_url( 'admin-post.php' )
					),
					'ezm_logs_delete_' . $row['file_hash']
				);
				?>
				<tr class="<?php echo $crit ? 'ezm-critical-row' : ''; ?>">
					<td>
						<strong><?php echo esc_html( basename( $row['file_path'] ) ); ?></strong>
						<div class="description"><?php echo esc_html( $row['file_path'] ); ?></div>
						<p class="ezm-log-actions">
							<a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'ezm-security-settings' ); ?></a>
							|
							<a href="<?php echo esc_url( $delete_url ); ?>" class="ezm-log-delete" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this log file?', 'ezm-security-settings' ) ); ?>');"><?php esc_html_e( 'Delete', 'ezm-security-settings' ); ?></a>
						</p>
					</td>
					<td><?php echo esc_html( $label ); ?></td>
					<td><?php echo esc_html( $row['ctime'] ); ?></td>
					<td><?php echo esc_html( $row['mtime'] ); ?></td>
					<td><?php echo esc_html( size_format( (int) $row['file_size'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
