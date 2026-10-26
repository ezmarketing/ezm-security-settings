<?php
/**
 * Media Usage admin view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'orphans';
$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'orphan_queue';
$mime   = isset( $_GET['mime'] ) ? sanitize_key( wp_unslash( $_GET['mime'] ) ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$offset = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
$per    = 30;

$rows          = array();
$pending_total = 0;
if ( 'pending' === $view ) {
	$per           = 50;
	$pending_total = EZM_Media::count_quarantine( array( 's' => $search ) );
	$rows          = EZM_Media::list_quarantine(
		array(
			's'        => $search,
			'per_page' => $per,
			'offset'   => $offset,
		)
	);
} elseif ( 'unsafe' === $view ) {
	$rows = EZM_Uploads_Safety::list_for_ui();
} else {
	$rows = EZM_Media::list_usage(
		array(
			'status'   => $status,
			'mime'     => $mime,
			's'        => $search,
			'per_page' => $per,
			'offset'   => $offset,
		)
	);
}

$lib_unused  = EZM_Media::count_unreferenced() - EZM_Media::count_disk_orphans();
$disk_count  = EZM_Media::count_disk_orphans();
$total_bytes = EZM_Media::unreferenced_total_bytes();
$unsafe_n    = count( EZM_Uploads_Safety::unmarked_findings() );
$filter_count = ( 'orphans' === $view )
	? EZM_Media::count_usage(
		array(
			'status' => $status,
			'mime'   => $mime,
			's'      => $search,
		)
	)
	: 0;
$can_delete_all = in_array( $status, array( 'orphan_queue', 'unreferenced', 'disk_orphan' ), true );
?>
<div class="wrap ezm-wrap">
	<h1><?php esc_html_e( 'EZ Maintenance — Media Usage', 'ezm-security-settings' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Find unused media library items and files left on disk that are not in the library.', 'ezm-security-settings' ); ?>
	</p>

	<nav class="nav-tab-wrapper">
		<a class="nav-tab <?php echo 'orphans' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-media-usage&view=orphans' ) ); ?>"><?php esc_html_e( 'Orphaned queue', 'ezm-security-settings' ); ?></a>
		<a class="nav-tab <?php echo 'pending' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-media-usage&view=pending' ) ); ?>"><?php esc_html_e( 'Pending deletion', 'ezm-security-settings' ); ?></a>
		<a class="nav-tab <?php echo 'unsafe' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-media-usage&view=unsafe' ) ); ?>">
			<?php esc_html_e( 'Unsafe files', 'ezm-security-settings' ); ?>
			<?php if ( $unsafe_n > 0 ) : ?>
				<span class="ezm-badge-alert"><?php echo (int) $unsafe_n; ?></span>
			<?php endif; ?>
		</a>
	</nav>

	<?php if ( 'orphans' === $view ) : ?>
		<div class="ezm-disk-toolbar">
			<button type="button" class="button button-primary" id="ezm-media-scan"><?php esc_html_e( 'Scan media usage', 'ezm-security-settings' ); ?></button>
			<span id="ezm-media-status" class="ezm-status"></span>
			<span>
				<?php
				printf(
					/* translators: 1: library unused count, 2: disk orphan count, 3: total size */
					esc_html__( 'Library unused: %1$d · On disk: %2$d · %3$s', 'ezm-security-settings' ),
					max( 0, (int) $lib_unused ),
					(int) $disk_count,
					esc_html( size_format( $total_bytes ) )
				);
				?>
			</span>
		</div>

		<form method="get" class="ezm-search-form">
			<input type="hidden" name="page" value="ezm-media-usage" />
			<input type="hidden" name="view" value="orphans" />
			<select name="status">
				<option value="orphan_queue" <?php selected( $status, 'orphan_queue' ); ?>><?php esc_html_e( 'Orphan queue (library + disk)', 'ezm-security-settings' ); ?></option>
				<option value="unreferenced" <?php selected( $status, 'unreferenced' ); ?>><?php esc_html_e( 'Library unreferenced', 'ezm-security-settings' ); ?></option>
				<option value="disk_orphan" <?php selected( $status, 'disk_orphan' ); ?>><?php esc_html_e( 'On disk (not in library)', 'ezm-security-settings' ); ?></option>
				<option value="ignored" <?php selected( $status, 'ignored' ); ?>><?php esc_html_e( 'Ignored', 'ezm-security-settings' ); ?></option>
				<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All', 'ezm-security-settings' ); ?></option>
			</select>
			<select name="mime">
				<option value=""><?php esc_html_e( 'All types', 'ezm-security-settings' ); ?></option>
				<option value="image" <?php selected( $mime, 'image' ); ?>><?php esc_html_e( 'Images', 'ezm-security-settings' ); ?></option>
				<option value="audio" <?php selected( $mime, 'audio' ); ?>><?php esc_html_e( 'Audio', 'ezm-security-settings' ); ?></option>
				<option value="video" <?php selected( $mime, 'video' ); ?>><?php esc_html_e( 'Video', 'ezm-security-settings' ); ?></option>
				<option value="document" <?php selected( $mime, 'document' ); ?>><?php esc_html_e( 'Documents', 'ezm-security-settings' ); ?></option>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filename…', 'ezm-security-settings' ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', 'ezm-security-settings' ); ?></button>
		</form>

		<div class="ezm-bulk-bar">
			<label><input type="checkbox" id="ezm-select-all" /> <?php esc_html_e( 'Select all on page', 'ezm-security-settings' ); ?></label>
			<select id="ezm-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk actions', 'ezm-security-settings' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'ezm-security-settings' ); ?></option>
				<option value="import"><?php esc_html_e( 'Add to media library', 'ezm-security-settings' ); ?></option>
			</select>
			<button type="button" class="button" id="ezm-bulk-apply"><?php esc_html_e( 'Apply', 'ezm-security-settings' ); ?></button>
			<span id="ezm-bulk-status" class="ezm-status"></span>
			<?php if ( $can_delete_all && $filter_count > 0 ) : ?>
				<button
					type="button"
					class="button ezm-delete-all"
					id="ezm-delete-all"
					data-status="<?php echo esc_attr( $status ); ?>"
					data-mime="<?php echo esc_attr( $mime ); ?>"
					data-s="<?php echo esc_attr( $search ); ?>"
					data-count="<?php echo (int) $filter_count; ?>"
				><?php
					printf(
						/* translators: %d: number of matching files */
						esc_html__( 'Delete all (%d)', 'ezm-security-settings' ),
						(int) $filter_count
					);
				?></button>
			<?php endif; ?>
		</div>

		<table class="wp-list-table widefat fixed striped" id="ezm-media-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="ezm-select-all-head" /></td>
					<th><?php esc_html_e( 'File', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Size', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ezm-security-settings' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No rows. Run a media usage scan.', 'ezm-security-settings' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$aid  = (int) $row['attachment_id'];
					$hash = isset( $row['file_hash'] ) ? $row['file_hash'] : '';
					$url  = $aid > 0 ? wp_get_attachment_url( $aid ) : EZM_Media::uploads_url_for_path( $row['file_path'] );
					$edit = $aid > 0 ? get_edit_post_link( $aid, 'raw' ) : '';
					$status_label = $row['status'];
					if ( 'disk_orphan' === $row['status'] ) {
						$status_label = __( 'on disk (not in library)', 'ezm-security-settings' );
					}
					$can_import = ( 'disk_orphan' === $row['status'] && $aid === 0 && $hash );
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" class="ezm-row-check" data-id="<?php echo (int) $aid; ?>" data-hash="<?php echo esc_attr( $hash ); ?>" data-status="<?php echo esc_attr( $row['status'] ); ?>" />
						</th>
						<td>
							<strong><?php echo esc_html( basename( $row['file_path'] ) ); ?></strong>
							<div class="description"><?php echo esc_html( $row['file_path'] ); ?></div>
							<?php if ( ! empty( $row['ignore_note'] ) ) : ?>
								<div class="description"><em><?php echo esc_html( $row['ignore_note'] ); ?></em></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['mime_type'] ); ?></td>
						<td><?php echo esc_html( size_format( (int) $row['file_size'] ) ); ?></td>
						<td><?php echo esc_html( $status_label ); ?></td>
						<td>
							<?php if ( $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'ezm-security-settings' ); ?></a> |
							<?php endif; ?>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'ezm-security-settings' ); ?></a> |
							<?php endif; ?>
							<?php if ( $can_import ) : ?>
								<button type="button" class="button-link ezm-media-import" data-hash="<?php echo esc_attr( $hash ); ?>"><?php esc_html_e( 'Add to library', 'ezm-security-settings' ); ?></button> |
							<?php endif; ?>
							<?php if ( 'ignored' !== $row['status'] ) : ?>
								<button type="button" class="button-link ezm-media-ignore" data-id="<?php echo (int) $aid; ?>" data-hash="<?php echo esc_attr( $hash ); ?>"><?php esc_html_e( 'Ignore', 'ezm-security-settings' ); ?></button> |
								<button type="button" class="button-link-delete ezm-media-quarantine" data-id="<?php echo (int) $aid; ?>" data-hash="<?php echo esc_attr( $hash ); ?>"><?php esc_html_e( 'Delete', 'ezm-security-settings' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'offset' => max( 0, $offset - $per ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'ezm-security-settings' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'offset' => $offset + $per ) ) ); ?>"><?php esc_html_e( 'Load more', 'ezm-security-settings' ); ?></a>
		</p>

	<?php elseif ( 'unsafe' === $view ) : ?>
		<div class="ezm-disk-toolbar">
			<button type="button" class="button button-primary" id="ezm-unsafe-scan"><?php esc_html_e( 'Scan uploads', 'ezm-security-settings' ); ?></button>
			<span id="ezm-unsafe-status" class="ezm-status"></span>
			<?php if ( $unsafe_n > 0 ) : ?>
				<span class="ezm-alert-inline"><?php printf( esc_html__( '%d file(s) need review', 'ezm-security-settings' ), (int) $unsafe_n ); ?></span>
			<?php endif; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Flags .php, .exe, .zip, and similar files in uploads. Mark safe if intentional (for example a downloadable zip).', 'ezm-security-settings' ); ?></p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Size', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ezm-security-settings' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No unsafe files indexed. Run a scan.', 'ezm-security-settings' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr class="<?php echo empty( $row['marked_safe'] ) ? 'ezm-critical-row' : ''; ?>">
						<td>
							<strong><?php echo esc_html( basename( $row['path'] ) ); ?></strong>
							<div class="description"><?php echo esc_html( $row['rel'] ?? $row['path'] ); ?></div>
						</td>
						<td><?php echo esc_html( '.' . ( $row['ext'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) ( $row['size'] ?? 0 ) ) ); ?></td>
						<td>
							<?php
							echo ! empty( $row['marked_safe'] )
								? esc_html__( 'Marked safe', 'ezm-security-settings' )
								: esc_html__( 'Needs review', 'ezm-security-settings' );
							?>
						</td>
						<td>
							<?php if ( ! empty( $row['marked_safe'] ) ) : ?>
								<button type="button" class="button ezm-unsafe-unmark" data-hash="<?php echo esc_attr( $row['hash'] ); ?>"><?php esc_html_e( 'Unmark', 'ezm-security-settings' ); ?></button>
							<?php else : ?>
								<button type="button" class="button ezm-unsafe-mark" data-path="<?php echo esc_attr( $row['path'] ); ?>"><?php esc_html_e( 'Mark safe', 'ezm-security-settings' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

	<?php else : ?>
		<div class="ezm-disk-toolbar">
			<button type="button" class="button" id="ezm-media-purge-now"><?php esc_html_e( 'Purge due items now', 'ezm-security-settings' ); ?></button>
			<span class="description">
				<?php
				printf(
					/* translators: %d: pending count */
					esc_html__( '%d pending · removes items whose hold period has ended.', 'ezm-security-settings' ),
					(int) $pending_total
				);
				?>
			</span>
		</div>

		<form method="get" class="ezm-search-form">
			<input type="hidden" name="page" value="ezm-media-usage" />
			<input type="hidden" name="view" value="pending" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search path or filename…', 'ezm-security-settings' ); ?>" />
			<button class="button"><?php esc_html_e( 'Search', 'ezm-security-settings' ); ?></button>
			<?php if ( $search ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-media-usage&view=pending' ) ); ?>"><?php esc_html_e( 'Clear', 'ezm-security-settings' ); ?></a>
			<?php endif; ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Relative path', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Quarantined', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Purge after', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Size', 'ezm-security-settings' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ezm-security-settings' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No pending deletions.', 'ezm-security-settings' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['relative_path'] ); ?></td>
						<td><?php echo esc_html( $row['quarantined_at'] ); ?></td>
						<td><?php echo esc_html( $row['scheduled_purge_at'] ); ?></td>
						<td><?php echo esc_html( size_format( (int) $row['file_size'] ) ); ?></td>
						<td>
							<button type="button" class="button ezm-media-restore" data-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Restore', 'ezm-security-settings' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$showing_from = $pending_total ? ( $offset + 1 ) : 0;
		$showing_to   = min( $offset + $per, $pending_total );
		$prev_offset  = max( 0, $offset - $per );
		$next_offset  = $offset + $per;
		$base_args    = array(
			'page' => 'ezm-media-usage',
			'view' => 'pending',
			's'    => $search,
		);
		?>
		<p class="ezm-paging">
			<?php
			if ( $pending_total > 0 ) {
				printf(
					/* translators: 1: from, 2: to, 3: total */
					esc_html__( 'Showing %1$d–%2$d of %3$d', 'ezm-security-settings' ),
					(int) $showing_from,
					(int) $showing_to,
					(int) $pending_total
				);
			}
			?>
			<?php if ( $offset > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'offset' => $prev_offset ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'ezm-security-settings' ); ?></a>
			<?php endif; ?>
			<?php if ( $next_offset < $pending_total ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'offset' => $next_offset ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'ezm-security-settings' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
