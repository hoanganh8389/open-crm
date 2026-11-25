<?php
/**
 * BizCity_CRM_Announce_Cell — the site ⇄ cell wire of "Lịch thông báo" (PHASE-0.146, doc 21 §2/§5).
 *
 *   push(id, op)  ⇒ letter `job_upsert` (channel-inbound@1.2.0, job.kind = "broadcast") through the scheduler's Hub pipe
 *                   (BizCity_Scheduler_Hub_Pipe, platform `scheduler`) ⇒ Hub ⇒ cell. JSONL line BEFORE the Hub call (R-CH-FILE-LOG).
 *                   ack accepted|duplicate ⇒ cell_state (upsert/resume ⇒ active · pause ⇒ paused · cancel ⇒ ended);
 *                   0 / 403 / 429 / 5xx / 409 in_progress ⇒ retry through wp-cron (≤ 6 attempts, same letter) — the site never sends itself.
 *   relay()       ⇒ Hub brain relay `broadcast/*` (schedules · runs · run · control), fail-open `_degraded`.
 *   refresh()     ⇒ pull the cell's truth for a page of rows (next run, last run, ended) — no job-event ingestion (doc 21 §0).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * [2026-10-28 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L4 / S146-L6 — new file.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Announce_Cell {

	const CONTRACT     = 'channel-inbound@1.2.0';
	const RETRY_HOOK   = 'bizcity_crm_announce_push_retry';
	const MAX_ATTEMPTS = 6;
	const OPS          = array( 'upsert', 'cancel', 'pause', 'resume', 'run_now' );
	const FORBIDDEN    = array( 'role', 'tenant_id', 'person_id', 'user_hash', 'wp_user_id', 'principal' );
	const FINAL_RUN    = array( 'completed', 'completed_partial', 'failed', 'cancelled', 'skipped_late' );

	/**
	 * Test seams: hub():?array{target,key_id,instance} · post(target,letter):{status,body} · owner_hash(user_id):string ·
	 * relay(method,path,account,query,body):array · schedule_retry(ts,args) · log(event,ctx)
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( self::RETRY_HOOK, array( __CLASS__, 'on_retry' ), 10, 4 );
	}

	public static function on_retry( $id, $op = 'upsert', $attempt = 1, $nonce = '' ): void {
		self::push( (int) $id, (string) $op, max( 1, (int) $attempt ), (string) $nonce );
	}

	/**
	 * The letter (pure). pause / resume / run_now carry a nonce in `idem` (the same op twice at one rev is two acts);
	 * upsert / cancel are deterministic per rev (a retry is the same letter ⇒ the cell answers duplicate).
	 */
	public static function letter( array $row, array $recipients, string $op, int $key_id, string $instance, string $owner_hash, string $tz, int $blog, string $nonce = '' ): array {
		$id   = (int) ( $row['id'] ?? 0 );
		$rev  = max( 1, (int) ( $row['rev'] ?? 1 ) );
		$ref  = (string) ( $row['broadcast_ref'] ?? '' );
		$job  = array(
			'op'            => $op,
			'site_event_id' => $id,
			'rev'           => $rev,
			'kind'          => 'broadcast',
			'number_ref'    => (string) ( $row['account_ref'] ?? '' ),
			'broadcast'     => array( 'broadcast_ref' => $ref ),
		);
		if ( 'upsert' === $op || 'run_now' === $op ) {
			$job['name']                   = BizCity_CRM_Announce_Store::cut( (string) ( $row['title'] ?? '' ), 180 );
			$job['broadcast']['schedule']  = BizCity_CRM_Announce_Store::json( $row['schedule_json'] ?? '' );
			$job['broadcast']['pack']      = BizCity_CRM_Announce_Store::build_pack( $row, $recipients, $tz );
		}
		$channel = array( 'platform' => class_exists( 'BizCity_Scheduler_Hub_Pipe' ) ? BizCity_Scheduler_Hub_Pipe::PLATFORM : 'scheduler', 'channel_ref' => $instance );
		$suffix  = '' !== $nonce ? '_' . preg_replace( '/[^a-z0-9]/', '', strtolower( $nonce ) ) : '';
		return self::scrub( array(
			'contract'   => self::CONTRACT,
			'kind'       => 'job_upsert',
			'trace_id'   => 'bcj.' . $blog . '.' . $id . '.' . $rev,
			'idem'       => 'bcj_' . $blog . '_' . $id . '_' . $rev . '_' . $op . $suffix,
			'thread_key' => 'bc:' . $ref,
			'tenant'     => array( 'key_id' => $key_id ),
			'channel'    => $channel,
			'contact'    => $channel + array( 'platform_uid' => strtolower( $owner_hash ) ),
			'job'        => $job,
		) );
	}

	/** Which state the row takes once the cell accepted $op. */
	public static function state_after( string $op, string $current ): string {
		switch ( $op ) {
			case 'cancel':
				return 'ended';
			case 'pause':
				return 'paused';
			case 'run_now':
				return 'draft' === $current ? 'active' : $current;
			default:
				return 'active';
		}
	}

	/**
	 * Send one op of one row to the cell. Never throws.
	 *
	 * @return array{status:string,cell_state?:string,http?:int,reason?:string,run_ref?:string}
	 */
	public static function push( int $id, string $op = 'upsert', int $attempt = 0, string $nonce = '' ): array {
		try {
			return self::handle( $id, in_array( $op, self::OPS, true ) ? $op : 'upsert', $attempt, $nonce );
		} catch ( \Throwable $e ) {
			error_log( '[crm][announce] push ' . $id . ' swallowed ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return array( 'status' => 'error', 'reason' => 'exception' );
		}
	}

	private static function handle( int $id, string $op, int $attempt, string $nonce ): array {
		$row = BizCity_CRM_Announce_Store::get_by_id( $id );
		if ( ! $row ) {
			return array( 'status' => 'skipped', 'reason' => 'not_found' );
		}
		if ( in_array( $op, array( 'pause', 'resume', 'run_now' ), true ) && '' === $nonce ) {
			$nonce = substr( md5( uniqid( (string) $id, true ) ), 0, 10 );
		}
		// // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — a row the person deleted must not be re-created at the cell by a wp-cron retry that was queued before the delete.
		if ( 'cancel' !== $op && 'deleted' === (string) ( $row['state_reason'] ?? '' ) ) {
			return array( 'status' => 'skipped', 'reason' => 'deleted' );
		}
		$state = (string) ( $row['cell_state'] ?? 'draft' );
		$hash  = self::owner_hash( $row );
		if ( '' === $hash && 'cancel' !== $op ) {
			return self::settle( $row, $op, array( 'cell_state' => 'error', 'state_reason' => 'owner_agent_missing' ), array( 'status' => 'owner_agent_missing' ) );
		}
		$hub = self::hub();
		if ( null === $hub ) {
			return self::retry_or_give_up( $row, $op, $attempt, $nonce, 0, 'hub_not_ready' );
		}
		$letter = self::letter( $row, 'upsert' === $op || 'run_now' === $op ? BizCity_CRM_Announce_Store::recipients( $id ) : array(), $op, $hub['key_id'], $hub['instance'], '' !== $hash ? $hash : str_repeat( '0', 64 ), BizCity_CRM_Announce_Store::tz(), function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1, $nonce );
		self::log( 'announce_push', array( 'ref' => $row['broadcast_ref'], 'op' => $op, 'rev' => (int) $row['rev'], 'attempt' => $attempt, 'recipients' => isset( $letter['job']['broadcast']['pack'] ) ? count( $letter['job']['broadcast']['pack']['recipients'] ) : 0, 'account' => substr( hash( 'sha256', (string) $row['account_ref'] ), 0, 12 ) ) );
		$res  = self::post( $hub, $letter );
		$code = (int) ( $res['status'] ?? 0 );
		$ack  = is_array( $res['body'] ?? null ) ? $res['body'] : array();
		$ack_status = (string) ( $ack['status'] ?? '' );
		self::log( 'announce_push_ack', array( 'ref' => $row['broadcast_ref'], 'op' => $op, 'http' => $code, 'ack' => $ack_status, 'code' => substr( sanitize_key( (string) ( $ack['code'] ?? '' ) ), 0, 60 ) ) );

		if ( $code >= 200 && $code < 300 && in_array( $ack_status, array( 'accepted', 'duplicate' ), true ) ) {
			$cols = array( 'cell_state' => self::state_after( $op, $state ), 'state_reason' => null );
			$out  = array( 'status' => $ack_status, 'http' => $code );
			$run  = preg_match( '/^BR-[a-f0-9]{6,32}$/', (string) ( $ack['run_ref'] ?? '' ) ) ? (string) $ack['run_ref'] : '';
			if ( '' !== $run ) {
				$cols['last_run_json'] = array( 'run_ref' => $run, 'state' => 'queued', 'counts' => null, 'started_at' => null, 'ended_at' => null );
				$out['run_ref']        = $run;
			}
			$push = array( 'next_run_at' => isset( $ack['next_run_at'] ) ? (string) $ack['next_run_at'] : null );
			return self::settle( $row, $op, $cols, $out, $push );
		}
		$err = substr( sanitize_key( (string) ( $ack['code'] ?? $ack['error'] ?? 'http_' . $code ) ), 0, 60 );
		if ( 409 === $code && 'stale_rev' === $err ) {
			return array( 'status' => 'stale_rev', 'http' => $code );
		}
		if ( 409 === $code && 'owner_agent_not_found' === $err ) {
			return self::settle( $row, $op, array( 'cell_state' => 'error', 'state_reason' => $err ), array( 'status' => 'owner_agent_not_found', 'http' => $code ) );
		}
		if ( 403 === $code && class_exists( 'BizCity_Scheduler_Hub_Pipe' ) ) {
			BizCity_Scheduler_Hub_Pipe::forget_registration();
		}
		if ( 0 === $code || 403 === $code || 429 === $code || $code >= 500 || ( 409 === $code && 'in_progress' === $err ) ) {
			return self::retry_or_give_up( $row, $op, $attempt, $nonce, $code, 0 === $code ? 'hub_unavailable' : $err );
		}
		return self::settle( $row, $op, array( 'cell_state' => 'error', 'state_reason' => '' !== $err ? $err : 'rejected' ), array( 'status' => 'rejected', 'http' => $code, 'reason' => $err ) );
	}

	private static function retry_or_give_up( array $row, string $op, int $attempt, string $nonce, int $http, string $reason ): array {
		$next = $attempt + 1;
		if ( $next >= self::MAX_ATTEMPTS ) {
			return self::settle( $row, $op, array( 'cell_state' => 'error', 'state_reason' => 'gave_up_' . $reason ), array( 'status' => 'rejected', 'http' => $http, 'reason' => 'gave_up_' . $reason ) );
		}
		$delay = min( 3600, 60 * ( 2 ** $attempt ) );
		$args  = array( (int) $row['id'], $op, $next, $nonce );
		$ts    = BizCity_CRM_Announce_Store::now() + $delay;
		if ( isset( self::$readers['schedule_retry'] ) ) {
			call_user_func( self::$readers['schedule_retry'], $ts, $args );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $ts, self::RETRY_HOOK, $args );
		}
		$cols = in_array( $op, array( 'upsert', 'resume' ), true ) ? array( 'cell_state' => 'scheduling', 'state_reason' => $reason ) : array( 'state_reason' => $reason );
		return self::settle( $row, $op, $cols, array( 'status' => 'retrying', 'http' => $http, 'reason' => $reason, 'attempts' => $next ) );
	}

	private static function settle( array $row, string $op, array $cols, array $out, array $push = array() ): array {
		$meta = BizCity_CRM_Announce_Store::json( $row['cell_push_json'] ?? '' );
		$meta = array_merge( $meta, $push, array( 'op' => $op, 'rev' => (int) $row['rev'], 'status' => $out['status'] ?? '', 'http' => $out['http'] ?? 0, 'at' => gmdate( 'c', BizCity_CRM_Announce_Store::now() ) ) );
		$cols['cell_push_json'] = $meta;
		if ( 'deleted' === (string) ( $row['state_reason'] ?? '' ) ) {
			$cols['state_reason'] = 'deleted'; // // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — a cancel retry (or its give-up) must not bring the deleted row back into the list
		}
		BizCity_CRM_Announce_Store::update_row( (int) $row['id'], $cols );
		return $out + ( isset( $cols['cell_state'] ) ? array( 'cell_state' => $cols['cell_state'] ) : array() );
	}

	/**
	 * [2026-11-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.146 / OC-007.4 — "Sẵn sàng gửi": would a row made now by $user_id on $account pass the
	 * owner check of push() (owner_agent_missing)? Same resolver, nothing written.
	 */
	public static function owner_ready( string $account, int $user_id ): bool {
		return '' !== self::owner_hash( array( 'account_ref' => $account, 'created_by' => $user_id ) );
	}

	/**
	 * The principal hash that receives the owner's 2 Zalo notes (D146-12): the number owner first, then whoever made the row.
	 * The cell prefers `number_ref` among the numbers this hash sits on (bizcityResolveSiteJobRecipient).
	 */
	private static function owner_hash( array $row ): string {
		$acc   = BizCity_CRM_Announce_Store::account( (string) ( $row['account_ref'] ?? '' ) );
		$users = array_values( array_unique( array_filter( array( (int) ( $acc['owner_user_id'] ?? 0 ), (int) ( $row['created_by'] ?? 0 ) ) ) ) );
		foreach ( $users as $uid ) {
			if ( isset( self::$readers['owner_hash'] ) ) {
				$h = (string) call_user_func( self::$readers['owner_hash'], $uid );
			} else {
				$a = class_exists( 'BizCity_Scheduler_Owner_Agent' ) ? BizCity_Scheduler_Owner_Agent::resolve( $uid ) : null;
				$h = is_array( $a ) ? (string) $a['hash'] : '';
			}
			if ( preg_match( '/^[a-f0-9]{64}$/', $h ) ) {
				return $h;
			}
		}
		return '';
	}

	/** @return array{target:array,key_id:int,instance:string}|null */
	private static function hub() {
		if ( isset( self::$readers['hub'] ) ) {
			$h = call_user_func( self::$readers['hub'] );
			return is_array( $h ) ? $h : null;
		}
		if ( ! class_exists( 'BizCity_Scheduler_Hub_Pipe' ) ) {
			return null;
		}
		$target   = BizCity_Scheduler_Hub_Pipe::target();
		$key_id   = BizCity_Scheduler_Hub_Pipe::key_id();
		$instance = BizCity_Scheduler_Hub_Pipe::client_instance();
		if ( null === $target || $key_id <= 0 || '' === $instance ) {
			return null;
		}
		BizCity_Scheduler_Hub_Pipe::ensure_registered( $target, $instance );
		return array( 'target' => $target, 'key_id' => $key_id, 'instance' => $instance );
	}

	private static function post( array $hub, array $letter ): array {
		if ( isset( self::$readers['post'] ) ) {
			return (array) call_user_func( self::$readers['post'], $hub['target'] ?? array(), $letter );
		}
		return BizCity_Scheduler_Hub_Pipe::post_letter( $hub['target'], $letter );
	}

	/* ── relay (doc 21 §5) ────────────────────────────────────────────── */

	/** Hub `brain/broadcast/*`. A Hub / cell failure is `200 + success:false + _degraded:true`, never an exception. */
	public static function relay( string $method, string $path, string $account, array $query = array(), array $body = array() ): array {
		if ( isset( self::$readers['relay'] ) ) {
			$r = call_user_func( self::$readers['relay'], $method, $path, $account, $query, $body );
		} elseif ( class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'broadcast_call' ) ) {
			$r = BizCity_Zalo_Personal_Hub_Client::instance()->broadcast_call( $method, $path, $account, $query, $body );
		} else {
			$r = null;
		}
		if ( ! is_array( $r ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => 'hub_client_missing', 'message' => 'Chưa đọc được trạng thái từ trợ lý Zalo.' );
		}
		if ( ! empty( $r['_degraded'] ) || ( empty( $r['ok'] ) && empty( $r['success'] ) ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => (string) ( $r['code'] ?? 'cell_unavailable' ), 'message' => (string) ( $r['message'] ?? 'Trợ lý Zalo chưa trả lời, trạng thái có thể cũ.' ) );
		}
		return $r;
	}

	/**
	 * Pull next run / last run / ended for a page of rows (one `schedules` + one `runs` call per number) and write the changes back.
	 *
	 * @return array{rows:array,degraded:bool}
	 */
	public static function refresh( array $rows ): array {
		$by = array();
		foreach ( $rows as $i => $row ) {
			if ( 'draft' !== (string) ( $row['cell_state'] ?? 'draft' ) ) {
				$by[ (string) $row['account_ref'] ][] = $i;
			}
		}
		$degraded = false;
		foreach ( $by as $account => $idx ) {
			$s = self::relay( 'GET', 'broadcast/schedules', $account );
			$r = self::relay( 'GET', 'broadcast/runs', $account, array( 'limit' => 50 ) );
			if ( empty( $s['ok'] ) || empty( $r['ok'] ) ) {
				$degraded = true;
				continue;
			}
			$sched = array();
			foreach ( (array) ( $s['items'] ?? array() ) as $it ) {
				$sched[ (string) ( $it['broadcast_ref'] ?? '' ) ] = $it;
			}
			$last = array();
			foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
				$ref = (string) ( $it['broadcast_ref'] ?? '' );
				if ( ! isset( $last[ $ref ] ) ) {
					$last[ $ref ] = $it; // newest first (doc 21 §5)
				}
			}
			foreach ( $idx as $i ) {
				$rows[ $i ] = self::merge_cell( $rows[ $i ], $sched[ (string) $rows[ $i ]['broadcast_ref'] ] ?? null, $last[ (string) $rows[ $i ]['broadcast_ref'] ] ?? null );
			}
		}
		return array( 'rows' => $rows, 'degraded' => $degraded );
	}

	/** One row + the cell's schedule item + newest run ⇒ updated row (written only when something changed). Pure apart from the write. */
	public static function merge_cell( array $row, $sched, $run, bool $write = true ): array {
		$push    = BizCity_CRM_Announce_Store::json( $row['cell_push_json'] ?? '' );
		$cols    = array();
		$next    = is_array( $sched ) && ! empty( $sched['enabled'] ) && empty( $sched['paused'] ) ? ( $sched['next_run_at'] ?? null ) : null;
		if ( ( $push['next_run_at'] ?? null ) !== $next ) {
			$push['next_run_at']    = $next;
			$cols['cell_push_json'] = $push;
		}
		if ( is_array( $run ) && ! empty( $run['run_ref'] ) ) {
			$lr = array(
				'run_ref'    => (string) $run['run_ref'],
				'state'      => (string) ( $run['state'] ?? '' ),
				'counts'     => is_array( $run['counts'] ?? null ) ? $run['counts'] : null,
				'started_at' => $run['started_at'] ?? null,
				'ended_at'   => $run['ended_at'] ?? null,
			);
			if ( BizCity_CRM_Announce_Store::json( $row['last_run_json'] ?? '' ) !== $lr ) {
				$cols['last_run_json'] = $lr;
			}
		}
		$state = (string) ( $row['cell_state'] ?? '' );
		if ( 'active' === $state && is_array( $sched ) && ! empty( $sched['paused'] ) ) {
			$cols['cell_state'] = 'paused';
		} elseif ( 'paused' === $state && is_array( $sched ) && ! empty( $sched['enabled'] ) && empty( $sched['paused'] ) ) {
			$cols['cell_state'] = 'active';
		} elseif ( 'active' === $state && ( ! is_array( $sched ) || empty( $sched['enabled'] ) ) && is_array( $run ) && in_array( (string) ( $run['state'] ?? '' ), self::FINAL_RUN, true ) ) {
			// now / once / dates whose last run is over and no schedule row left ⇒ the row is done
			$cols['cell_state'] = 'ended';
		}
		if ( ! $cols ) {
			return $row;
		}
		if ( $write ) {
			BizCity_CRM_Announce_Store::update_row( (int) $row['id'], $cols );
		}
		foreach ( $cols as $k => $v ) {
			$row[ $k ] = is_array( $v ) ? wp_json_encode( $v ) : $v;
		}
		return $row;
	}

	/* ── helpers ──────────────────────────────────────────────────────── */

	private static function log( string $event, array $ctx ): void {
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $event, $ctx );
			return;
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_PERSONAL, BizCity_Channel_File_Logger::LEVEL_INFO, $event, 'Lịch thông báo ⇒ cell.', $ctx );
		}
	}

	private static function scrub( array $a ): array {
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) && in_array( $k, self::FORBIDDEN, true ) ) {
				unset( $a[ $k ] );
			} elseif ( is_array( $v ) ) {
				$a[ $k ] = self::scrub( $v );
			}
		}
		return $a;
	}
}
