<?php
/**
 * Persistence for parcel dispatches.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Database;

class DispatchRepository {

	/**
	 * Inserts a dispatch row.
	 *
	 * @return int Inserted row ID, or 0 when the database rejected the insert
	 *             (for example because the barcode already exists).
	 */
	public function create( string $barcode, int $order_id, int $user_id, string $dispatched_at ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			DatabaseInstaller::table_name(),
			array(
				'barcode'       => $barcode,
				'order_id'      => $order_id,
				'user_id'       => $user_id,
				'dispatched_at' => $dispatched_at,
			),
			array( '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{id:int,barcode:string,order_id:int,user_id:int,dispatched_at:string}|null
	 */
	public function find_by_barcode( string $barcode ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, barcode, order_id, user_id, dispatched_at FROM ' . DatabaseInstaller::table_name() . ' WHERE barcode = %s LIMIT 1',
				$barcode
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * @return array<int, array{id:int,barcode:string,order_id:int,user_id:int,dispatched_at:string}>
	 */
	public function history_for_order( int $order_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, barcode, order_id, user_id, dispatched_at FROM ' . DatabaseInstaller::table_name() . ' WHERE order_id = %d ORDER BY id ASC',
				$order_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	public function exists( string $barcode ): bool {
		global $wpdb;

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . DatabaseInstaller::table_name() . ' WHERE barcode = %s LIMIT 1',
				$barcode
			)
		);
	}

	public function is_order_dispatched( int $order_id ): bool {
		global $wpdb;

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . DatabaseInstaller::table_name() . ' WHERE order_id = %d LIMIT 1',
				$order_id
			)
		);
	}

	/**
	 * Paginated dispatch history, newest first.
	 *
	 * @param array{barcode?:string,order_id?:int,user_id?:int} $filters
	 * @return array{items: array<int, array{id:int,barcode:string,order_id:int,user_id:int,dispatched_at:string}>, total:int}
	 */
	public function paginate( array $filters = array(), int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table = DatabaseInstaller::table_name();
		$where = array( '1=1' );
		$params = array();

		if ( isset( $filters['barcode'] ) && '' !== (string) $filters['barcode'] ) {
			$where[]  = 'barcode = %s';
			$params[] = (string) $filters['barcode'];
		}

		if ( ! empty( $filters['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $filters['order_id'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}

		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT id, barcode, order_id, user_id, dispatched_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results(
			$wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * @return array{id:int,barcode:string,order_id:int,user_id:int,dispatched_at:string}
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'barcode'       => (string) $row['barcode'],
			'order_id'      => (int) $row['order_id'],
			'user_id'       => (int) $row['user_id'],
			'dispatched_at' => (string) $row['dispatched_at'],
		);
	}
}
