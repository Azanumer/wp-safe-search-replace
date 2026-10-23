<?php
/**
 * Plugin Name: Safe Search & Replace
 * Plugin URI: https://github.com/Azanumer/wp-safe-search-replace
 * Description: Serialized-safe search and replace across WordPress tables with a dry-run report before anything is touched. Built for domain migrations.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://github.com/Azanumer
 * License: MIT
 * Text Domain: safe-search-replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSR_VERSION', '1.0.0' );
define( 'SSR_BATCH', 500 ); // Rows per DB round-trip; keeps memory flat on huge tables.

/**
 * Recursively replace a string inside mixed data, preserving PHP
 * serialization by re-serializing after the walk.
 *
 * @param mixed  $data    Any value (string/array/object/serialized string).
 * @param string $search  Needle.
 * @param string $replace Replacement.
 * @param int    $count   Running total of replacements made (by reference).
 * @return mixed The replaced data, in its original type.
 */
function ssr_recursive_replace( $data, $search, $replace, &$count = 0 ) {
	if ( is_string( $data ) ) {
		if ( is_serialized( $data ) ) {
			$unserialized = @unserialize( $data );
			// `b:0;` unserializes to false but is valid serialized data.
			if ( false !== $unserialized || 'b:0;' === $data ) {
				$result = ssr_recursive_replace( $unserialized, $search, $replace, $count );
				return serialize( $result );
			}
			// Corrupt serialized string: replace inside it literally (best effort).
			$replaced = str_replace( $search, $replace, $data, $n );
			$count += $n;
			return $replaced;
		}
		$replaced = str_replace( $search, $replace, $data, $n );
		$count += $n;
		return $replaced;
	}

	if ( is_array( $data ) ) {
		foreach ( $data as $key => $value ) {
			$new_key          = is_string( $key ) ? str_replace( $search, $replace, $key, $kn ) : $key;
			$count           += isset( $kn ) ? $kn : 0;
			$data[ $new_key ] = ssr_recursive_replace( $value, $search, $replace, $count );
			if ( $new_key !== $key ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	if ( is_object( $data ) ) {
		foreach ( get_object_vars( $data ) as $key => $value ) {
			$data->$key = ssr_recursive_replace( $value, $search, $replace, $count );
		}
		return $data;
	}

	return $data;
}

/**
 * Tables + text columns that are safe to scan, with their row IDs.
 *
 * @return array Table name => array( 'id' => primary key, 'columns' => text columns ).
 */
function ssr_scan_map() {
	global $wpdb;
	return array(
		$wpdb->posts    => array( 'id' => 'ID',           'columns' => array( 'post_content', 'post_excerpt', 'post_title', 'guid' ) ),
		$wpdb->postmeta => array( 'id' => 'meta_id',      'columns' => array( 'meta_value' ) ),
		$wpdb->options  => array( 'id' => 'option_id',    'columns' => array( 'option_value' ) ),
		$wpdb->comments => array( 'id' => 'comment_ID',   'columns' => array( 'comment_content', 'comment_author_url' ) ),
		$wpdb->commentmeta => array( 'id' => 'meta_id',   'columns' => array( 'meta_value' ) ),
		$wpdb->termmeta => array( 'id' => 'meta_id',      'columns' => array( 'meta_value' ) ),
		$wpdb->usermeta => array( 'id' => 'umeta_id',     'columns' => array( 'meta_value' ) ),
		$wpdb->links    => array( 'id' => 'link_id',      'columns' => array( 'link_url', 'link_description' ) ),
	);
}

/**
 * Run the search over all mapped tables.
 *
 * @param string $search  Needle.
 * @param string $replace Replacement (ignored in dry-run).
 * @param bool   $dry_run When true, only counts matches without writing.
 * @return array Report: rows per table + per-column cells, and total replacements.
 */
function ssr_run( $search, $replace, $dry_run = true ) {
	global $wpdb;

	$report = array(
		'dry_run' => $dry_run,
		'tables'  => array(),
		'total_cells' => 0,
		'total_replacements' => 0,
	);

	foreach ( ssr_scan_map() as $table => $map ) {
		$table_report = array( 'cells' => 0, 'replacements' => 0, 'columns' => array() );
		$cols_sql     = implode( ', ', array_merge( array( $map['id'] . ' AS row_id' ), $map['columns'] ) );
		// Column names come from our hardcoded map — safe to interpolate.
		// Only the LIKE values go through prepare().
		$where_parts  = array();
		foreach ( $map['columns'] as $col ) {
			$where_parts[] = "{$col} LIKE %s";
		}
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$offset = 0;

		while ( true ) {
			$args = array_merge(
				array_fill( 0, count( $map['columns'] ), $like ),
				array( SSR_BATCH, $offset )
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$cols_sql} FROM {$table} WHERE " .
					implode( ' OR ', $where_parts ) .
					' LIMIT %d OFFSET %d',
					...$args
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$row_id = $row['row_id'];
				foreach ( $map['columns'] as $col ) {
					$count   = 0;
					$new_val = ssr_recursive_replace( $row[ $col ], $search, $replace, $count );
					if ( $count > 0 ) {
						++$table_report['cells'];
						$table_report['replacements'] += $count;
						if ( ! isset( $table_report['columns'][ $col ] ) ) {
							$table_report['columns'][ $col ] = 0;
						}
						$table_report['columns'][ $col ] += $count;

						if ( ! $dry_run ) {
							$wpdb->update( $table, array( $col => $new_val ), array( $map['id'] => $row_id ) );
						}
					}
				}
			}
			$offset += SSR_BATCH;
		}

		$report['tables'][ $table ]       = $table_report;
		$report['total_cells']           += $table_report['cells'];
		$report['total_replacements']    += $table_report['replacements'];
	}

	return $report;
}

/**
 * Admin: Tools → Search & Replace page.
 */
function ssr_register_tools_page() {
	add_management_page(
		__( 'Search & Replace', 'safe-search-replace' ),
		__( 'Search & Replace', 'safe-search-replace' ),
		'manage_options',
		'safe-search-replace',
		'ssr_render_tools_page'
	);
}
add_action( 'admin_menu', 'ssr_register_tools_page' );

/**
 * Render the page, run dry-run / live replacement on submit.
 */
function ssr_render_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$search  = isset( $_POST['ssr_search'] ) ? stripslashes( $_POST['ssr_search'] ) : '';
	$replace = isset( $_POST['ssr_replace'] ) ? stripslashes( $_POST['ssr_replace'] ) : '';
	$report  = null;
	$error   = '';

	if ( isset( $_POST['ssr_run'] ) || isset( $_POST['ssr_dry'] ) ) {
		check_admin_referer( 'ssr_action' );

		if ( '' === $search ) {
			$error = __( 'Please enter a search string.', 'safe-search-replace' );
		} elseif ( isset( $_POST['ssr_run'] ) && 'I UNDERSTAND' !== trim( $_POST['ssr_confirm'] ?? '' ) ) {
			$error = __( 'Live replacement requires typing "I UNDERSTAND" as confirmation.', 'safe-search-replace' );
		} else {
			$dry_run = ! isset( $_POST['ssr_run'] );
			set_time_limit( 0 );
			$report = ssr_run( $search, $replace, $dry_run );

			if ( ! $dry_run ) {
				// URL-containing caches go stale after a domain move.
				wp_cache_flush();
			}
		}
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Search & Replace', 'safe-search-replace' ); ?></h1>
		<p><?php esc_html_e( 'Replace strings across posts, meta, options and comments. Serialized data (widgets, theme options) is unserialized, replaced and re-serialized so nothing breaks.', 'safe-search-replace' ); ?></p>
		<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Back up your database before a live run.', 'safe-search-replace' ); ?></strong></p></div>

		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php if ( is_array( $report ) ) : ?>
			<h2><?php echo $report['dry_run'] ? esc_html__( 'Dry-run report', 'safe-search-replace' ) : esc_html__( 'Replacement complete', 'safe-search-replace' ); ?></h2>
			<p><?php
			/* translators: 1: cell count, 2: replacement count. */
			echo esc_html( sprintf(
				__( '%1$d database cell(s) matched, %2$d replacement(s) %3$s.', 'safe-search-replace' ),
				$report['total_cells'],
				$report['total_replacements'],
				$report['dry_run'] ? __( 'would be made', 'safe-search-replace' ) : __( 'were made', 'safe-search-replace' )
			) );
			?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Table', 'safe-search-replace' ); ?></th>
					<th><?php esc_html_e( 'Matched cells', 'safe-search-replace' ); ?></th>
					<th><?php esc_html_e( 'Replacements', 'safe-search-replace' ); ?></th>
					<th><?php esc_html_e( 'Columns', 'safe-search-replace' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $report['tables'] as $table => $t ) : ?>
					<tr>
						<td><code><?php echo esc_html( $table ); ?></code></td>
						<td><?php echo (int) $t['cells']; ?></td>
						<td><?php echo (int) $t['replacements']; ?></td>
						<td><?php echo esc_html( implode( ', ', array_keys( $t['columns'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<hr>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'ssr_action' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="ssr_search"><?php esc_html_e( 'Search for', 'safe-search-replace' ); ?></label></th>
					<td><input type="text" id="ssr_search" name="ssr_search" class="regular-text" value="<?php echo esc_attr( $search ); ?>" placeholder="https://old-domain.com"></td>
				</tr>
				<tr>
					<th><label for="ssr_replace"><?php esc_html_e( 'Replace with', 'safe-search-replace' ); ?></label></th>
					<td><input type="text" id="ssr_replace" name="ssr_replace" class="regular-text" value="<?php echo esc_attr( $replace ); ?>" placeholder="https://new-domain.com"></td>
				</tr>
				<tr>
					<th><label for="ssr_confirm"><?php esc_html_e( 'Live-run confirmation', 'safe-search-replace' ); ?></label></th>
					<td>
						<input type="text" id="ssr_confirm" name="ssr_confirm" class="regular-text" autocomplete="off" placeholder="I UNDERSTAND">
						<p class="description"><?php esc_html_e( 'Type "I UNDERSTAND" to enable the live replacement button. Dry-run never writes anything.', 'safe-search-replace' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
			submit_button( __( 'Dry Run', 'safe-search-replace' ), 'secondary', 'ssr_dry' );
			submit_button( __( 'Run Live Replacement', 'safe-search-replace' ), 'primary', 'ssr_run' );
			?>
		</form>
	</div>
	<?php
}
