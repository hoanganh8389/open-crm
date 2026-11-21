<?php
/**
 * BizCity_CRM_Business_Pack_Exporter — Zalo Brain CRM decoration of the `customers` projection pack.
 *
 * [2026-10-09 10:48 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L4 (D-W20-1) — the plugin no longer registers any pack
 * kind. The 5 Woo kinds (sales, orders, stock, catalog, catalog_map) moved to core/mcp/verticals/woo (BizCity_Woo_Pack_Exporter)
 * and `customers` has a base version in core/crm (BizCity_CRM_Customers_Pack) so the cell keeps them without this plugin. What
 * the plugin adds is the professional layer: each customers row gets its pipeline stage label through the core filter
 * `bizcity_crm_pack_customer_row` (and the raw stage key when the caller asks for it — MCP reads). Previous full exporter:
 * _archived/WP-20-Z1/includes/woo/class-business-pack-exporter.php.
 *
 * Deploy with the matching core (O1): an older core still expects this class to register the 7 kinds.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Business_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_CRM_Business_Pack_Exporter {

	const ROW_FILTER = 'bizcity_crm_pack_customer_row';

	/** @var array<string,array> per-request memo: batch hash => contact_id => pipeline row */
	private static $memo = array();

	public static function register(): void {
		add_filter( self::ROW_FILTER, array( __CLASS__, 'decorate' ), 10, 3 );
	}

	/**
	 * Stage of the contact from the CRM pipeline: `crm_stage` = display label (unknown key kept as is, as before); `stage` = raw
	 * key, only when the row already has that key. The pipeline is loaded once per batch (the whole pack build), not per row.
	 *
	 * @param mixed $row
	 * @param int   $contact_id
	 * @param int[] $batch_ids
	 * @return mixed
	 */
	public static function decorate( $row, $contact_id = 0, $batch_ids = array() ) {
		$cid = (int) $contact_id;
		if ( ! is_array( $row ) || $cid <= 0 || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
			return $row;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $batch_ids ) ) ) );
		if ( ! in_array( $cid, $ids, true ) ) {
			$ids = array( $cid );
		}
		sort( $ids );
		$key = md5( implode( ',', $ids ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			if ( count( self::$memo ) > 20 ) {
				self::$memo = array();
			}
			self::$memo[ $key ] = (array) BizCity_CRM_Customer_Pipeline::rows( $ids );
		}
		$stage = (string) ( self::$memo[ $key ][ $cid ]['stage'] ?? '' );
		if ( '' === $stage ) {
			return $row;
		}
		$labels           = BizCity_CRM_Customer_Pipeline::LABELS;
		$row['crm_stage'] = (string) ( $labels[ $stage ] ?? $stage );
		if ( array_key_exists( 'stage', $row ) ) {
			$row['stage'] = $stage;
		}
		return $row;
	}

	/** Test seam reset. */
	public static function reset(): void {
		self::$memo = array();
	}
}
