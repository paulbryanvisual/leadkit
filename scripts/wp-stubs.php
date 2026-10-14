<?php
/**
 * Just enough WordPress to load LeadKit's PHP and run it — options,
 * transients, filters, $wpdb's options table, REST responses — plus a fake
 * monday.com API that keeps boards in memory and records every call.
 *
 * For the check-*.php scripts only. Never loaded by the plugin.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['lk_options']    = array();
$GLOBALS['lk_transients'] = array();
$GLOBALS['lk_filters']    = array();
$GLOBALS['lk_actions']    = array();
$GLOBALS['lk_settings']   = array();

function lk_reset() {
	$GLOBALS['lk_options']    = array();
	$GLOBALS['lk_transients'] = array();
	// Back to the hooks the plugin registered when it loaded; drop the rest.
	$GLOBALS['lk_filters']    = $GLOBALS['lk_boot_filters'] ?? array();
	$GLOBALS['lk_actions']    = $GLOBALS['lk_boot_actions'] ?? array();
	$GLOBALS['wpdb']->rows    = array();
	LkMonday::reset();
}

// ---- hooks ---------------------------------------------------------------------
function add_action( $hook, $cb, $prio = 10 ) {
	$GLOBALS['lk_actions'][ $hook ][] = array( $prio, $cb );
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['lk_filters'][ $hook ][] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['lk_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$list = $GLOBALS['lk_actions'][ $hook ] ?? array();
	usort( $list, fn( $a, $b ) => $a[0] <=> $b[0] );
	foreach ( $list as $a ) {
		$a[1]( ...$args );
	}
}
function remove_all_actions( $hook ) {
	unset( $GLOBALS['lk_actions'][ $hook ] );
}
function add_shortcode() {}
function register_post_type() {}
function get_posts( $args = array() ) {
	return array();
}
function register_activation_hook() {}
function register_deactivation_hook() {}
function register_rest_route() {}
function register_setting( $group, $name, $args ) {
	$GLOBALS['lk_settings'][ $name ] = $args;
}
function plugin_dir_url( $f ) {
	return 'https://example.test/wp-content/plugins/leadkit/';
}
function rest_url( $p = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $p, '/' );
}
function home_url( $p = '' ) {
	return 'https://example.test' . $p;
}
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p;
}
function is_admin() {
	return false;
}

// ---- options and transients ------------------------------------------------------
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['lk_options'] ) ? $GLOBALS['lk_options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['lk_options'][ $k ] = $v;
	return true;
}
function add_option( $k, $v = '', $dep = '', $autoload = null ) {
	if ( array_key_exists( $k, $GLOBALS['lk_options'] ) ) {
		return false;
	}
	$GLOBALS['lk_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['lk_options'][ $k ] );
	return true;
}
function get_transient( $k ) {
	$t = $GLOBALS['lk_transients'][ $k ] ?? null;
	return $t && $t[1] > time() ? $t[0] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['lk_transients'][ $k ] = array( $v, time() + ( $ttl ?: 999999 ) );
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['lk_transients'][ $k ] );
}
function wp_cache_delete( $k, $g = '' ) {
	return true;
}
function wp_parse_args( $a, $d ) {
	return array_merge( $d, (array) $a );
}

// ---- formatting --------------------------------------------------------------------
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v, $f );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $s ) {
	return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL );
}
function sanitize_file_name( $s ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $s );
}
function wp_unslash( $s ) {
	return $s;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return esc_html( $s );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_url_raw( $s ) {
	return (string) $s;
}
function __( $s, $d = '' ) {
	return $s;
}
function esc_html__( $s, $d = '' ) {
	return esc_html( $s );
}
function _n( $one, $many, $n, $d = '' ) {
	return 1 === (int) $n ? $one : $many;
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( (string) $u, $c );
}
function wp_get_referer() {
	return $GLOBALS['lk_referer'] ?? false;
}
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
function wp_date( $format, $ts = null, $tz = null ) {
	$d = new DateTime( '@' . ( null === $ts ? time() : (int) $ts ) );
	$d->setTimezone( $tz ?: wp_timezone() );
	return $d->format( $format );
}
function is_email( $e ) {
	return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
}

// ---- REST --------------------------------------------------------------------------
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_status() {
		return $this->status;
	}
}
class WP_REST_Request {
	private $body;
	private $params;
	public function __construct( $body = '', $params = array() ) {
		$this->body   = $body;
		$this->params = $params;
	}
	public function get_body() {
		return $this->body;
	}
	public function get_param( $k ) {
		return $this->params[ $k ] ?? null;
	}
}

// ---- $wpdb: only the options table, with INSERT IGNORE that really ignores ----------
class LkWpdb {
	public $options = 'wp_options';
	public $rows    = array();
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%s/',
			function () use ( &$i, $args ) {
				return "'" . addslashes( (string) $args[ $i++ ] ) . "'";
			},
			$sql
		);
	}
	public function query( $sql ) {
		if ( preg_match( "/^INSERT IGNORE INTO wp_options .*VALUES \\('([^']*)', '([^']*)'/", $sql, $m ) ) {
			if ( isset( $this->rows[ $m[1] ] ) ) {
				return 0;
			}
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}
		throw new Exception( 'unexpected query: ' . $sql );
	}
	public function get_var( $sql ) {
		if ( preg_match( "/option_name = '([^']*)'/", $sql, $m ) ) {
			return $this->rows[ $m[1] ] ?? null;
		}
		throw new Exception( 'unexpected get_var: ' . $sql );
	}
	public function delete( $table, $where ) {
		unset( $this->rows[ $where['option_name'] ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new LkWpdb();

function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
class WP_Error {
	public $msg;
	public function __construct( $c = '', $m = '' ) {
		$this->msg = $m;
	}
	public function get_error_message() {
		return $this->msg;
	}
}

// ---- a fake monday.com ----------------------------------------------------------------
class LkMonday {
	public static $boards = array();
	public static $calls  = array();
	public static $next   = 9000;
	/** @var callable|null Return non-null to answer a call yourself (e.g. an error). */
	public static $fault = null;
	/** @var callable|null ( alias, op, args ) => true to refuse that one mutation of a batch. */
	public static $aliasFault = null;

	public static function reset() {
		self::$boards = array();
		self::$calls      = array();
		self::$fault      = null;
		self::$aliasFault = null;
	}
	public static function board( $id, $cols ) {
		self::$boards[ (string) $id ] = array( 'cols' => $cols, 'items' => array() );
	}
	public static function mutations() {
		return array_values( array_filter( self::$calls, fn( $c ) => 0 === strpos( ltrim( $c['q'] ), 'mutation' ) ) );
	}
	public static function url( $b, $id ) {
		return "https://example.monday.com/boards/$b/pulses/$id";
	}
	/** What monday.com shows as a column's text. */
	public static function text( $val ) {
		if ( ! is_array( $val ) ) {
			return (string) $val;
		}
		if ( isset( $val['email'] ) ) {
			return (string) $val['email'];
		}
		if ( isset( $val['url'] ) ) {
			return ( $val['text'] ?? '' ) . ' - ' . $val['url'];
		}
		if ( isset( $val['text'] ) ) {
			return (string) $val['text'];
		}
		return isset( $val['item_ids'] ) ? '' : json_encode( $val );
	}
	public static function find_item( $id ) {
		foreach ( self::$boards as $b => $board ) {
			if ( isset( $board['items'][ (string) $id ] ) ) {
				return array( (string) $b, $board['items'][ (string) $id ] );
			}
		}
		return null;
	}

	public static function answer( $q, $v ) {
		self::$calls[] = array( 'q' => $q, 'v' => $v );
		if ( self::$fault ) {
			$r = ( self::$fault )( $q, $v );
			if ( null !== $r ) {
				return $r;
			}
		}
		$err = fn( $m ) => array( 'errors' => array( array( 'message' => $m ) ) );

		// Several aliased mutations in one request: each answered on its own;
		// a refused one is null with an error, the rest still happen.
		if ( preg_match_all( '/(\w+): (create_item|change_multiple_column_values)\(([^)]*)\)/', $q, $ms, PREG_SET_ORDER ) ) {
			$data   = array();
			$errors = array();
			foreach ( $ms as $m ) {
				$args = array();
				foreach ( explode( ',', $m[3] ) as $pair ) {
					list( $k, $val ) = array_map( 'trim', explode( ':', $pair, 2 ) );
					$args[ $k ]      = '$' === $val[0] ? ( $v[ substr( $val, 1 ) ] ?? null ) : $val;
				}
				$b = (string) $args['board_id'];
				if ( self::$aliasFault && ( self::$aliasFault )( $m[1], $m[2], $args ) ) {
					$data[ $m[1] ] = null;
					$errors[]      = array( 'message' => 'refused ' . $m[1] );
					continue;
				}
				if ( 'create_item' === $m[2] ) {
					$id                                = (string) ( self::$next++ );
					self::$boards[ $b ]['items'][ $id ] = array( 'name' => $args['item_name'], 'created_at' => gmdate( 'c' ), 'values' => json_decode( $args['column_values'] ?? '{}', true ) ?: array() );
					$data[ $m[1] ]                     = array( 'id' => $id, 'url' => self::url( $b, $id ) );
				} elseif ( isset( self::$boards[ $b ]['items'][ (string) $args['item_id'] ] ) ) {
					foreach ( json_decode( $args['column_values'], true ) as $col => $val ) {
						self::$boards[ $b ]['items'][ (string) $args['item_id'] ]['values'][ $col ] = $val;
					}
					$data[ $m[1] ] = array( 'id' => $args['item_id'] );
				} else {
					$data[ $m[1] ] = null;
					$errors[]      = array( 'message' => 'Item not found in board' );
				}
			}
			return array( 'data' => $data ) + ( $errors ? array( 'errors' => $errors ) : array() );
		}

		// Newest items on a board, with some columns (the gift linker).
		if ( false !== strpos( $q, 'order_by' ) ) {
			$b     = (string) $v['b'][0];
			$items = self::$boards[ $b ]['items'] ?? array();
			uasort( $items, fn( $x, $y ) => strcmp( $y['created_at'] ?? '', $x['created_at'] ?? '' ) );
			$out = array();
			foreach ( array_slice( $items, 0, 100, true ) as $id => $it ) {
				$cvs = array();
				foreach ( $v['c'] as $c ) {
					$val   = $it['values'][ $c ] ?? null;
					$cvs[] = array( 'id' => $c, 'text' => null === $val ? null : self::text( $val ), 'linked_item_ids' => array_map( 'strval', $val['item_ids'] ?? array() ) );
				}
				$out[] = array( 'id' => (string) $id, 'name' => $it['name'], 'created_at' => $it['created_at'] ?? gmdate( 'c' ), 'column_values' => $cvs );
			}
			return array( 'data' => array( 'boards' => array( array( 'items_page' => array( 'items' => $out ) ) ) ) );
		}
		if ( false !== strpos( $q, 'items(ids: $i) { id name url }' ) ) {
			$out = array();
			foreach ( $v['i'] as $id ) {
				$hit = self::find_item( $id );
				if ( $hit ) {
					$out[] = array( 'id' => (string) $id, 'name' => $hit[1]['name'], 'url' => self::url( $hit[0], $id ) );
				}
			}
			return array( 'data' => array( 'items' => $out ) );
		}

		if ( false !== strpos( $q, 'columns { id title' ) ) {
			$out = array();
			foreach ( (array) $v['b'] as $b ) {
				$cols = array();
				foreach ( self::$boards[ (string) $b ]['cols'] ?? array() as $id => $c ) {
					$cols[] = array( 'id' => $id, 'title' => $c[0], 'type' => $c[1] );
				}
				$out[] = array( 'columns' => $cols );
			}
			return array( 'data' => array( 'boards' => $out ) );
		}
		if ( false !== strpos( $q, 'create_column' ) ) {
			preg_match( '/column_type: (\w+)/', $q, $m );
			$id = $m[1] . '_' . ( self::$next++ );
			self::$boards[ $v['b'] ]['cols'][ $id ] = array( $v['t'], $m[1] );
			return array( 'data' => array( 'create_column' => array( 'id' => $id ) ) );
		}
		if ( false !== strpos( $q, 'items_page_by_column_values' ) ) {
			$hits = array();
			foreach ( self::$boards[ (string) $v['b'] ]['items'] ?? array() as $id => $it ) {
				if ( in_array( self::text( $it['values'][ $v['c'] ] ?? '' ), $v['v'], true ) ) {
					$hit = array( 'id' => (string) $id, 'name' => $it['name'], 'url' => self::url( $v['b'], $id ) );
					if ( isset( $v['r'] ) ) {
						$hit['column_values'] = array_map( fn( $c ) => array( 'text' => (string) ( $it['values'][ $c ] ?? '' ) ), $v['r'] );
					}
					$hits[] = $hit;
				}
			}
			return array( 'data' => array( 'items_page_by_column_values' => array( 'items' => $hits ) ) );
		}
		if ( false !== strpos( $q, 'create_item' ) ) {
			if ( ! isset( self::$boards[ (string) $v['b'] ] ) ) {
				return $err( 'board not found' );
			}
			$id = (string) ( self::$next++ );
			self::$boards[ (string) $v['b'] ]['items'][ $id ] = array( 'name' => $v['n'], 'created_at' => gmdate( 'c' ), 'values' => json_decode( $v['v'] ?? '{}', true ) ?: array() );
			return array( 'data' => array( 'create_item' => array( 'id' => $id, 'url' => self::url( $v['b'], $id ) ) ) );
		}
		if ( false !== strpos( $q, 'change_multiple_column_values' ) ) {
			$b = (string) $v['b'];
			if ( ! isset( self::$boards[ $b ]['items'][ (string) $v['i'] ] ) ) {
				return $err( 'Item not found in board' );
			}
			foreach ( json_decode( $v['v'], true ) as $col => $val ) {
				self::$boards[ $b ]['items'][ (string) $v['i'] ]['values'][ $col ] = $val;
			}
			return array( 'data' => array( 'change_multiple_column_values' => array( 'id' => $v['i'] ) ) );
		}
		if ( false !== strpos( $q, 'items(ids: $i)' ) ) {
			$out = array();
			foreach ( $v['i'] as $id ) {
				$hit = self::find_item( $id );
				if ( $hit ) {
					$val   = $hit[1]['values'][ $v['c'][0] ] ?? array();
					$out[] = array( 'id' => (string) $id, 'column_values' => array( array( 'id' => $v['c'][0], 'linked_item_ids' => array_map( 'strval', $val['item_ids'] ?? array() ) ) ) );
				}
			}
			return array( 'data' => array( 'items' => $out ) );
		}
		return $err( 'fake monday does not know: ' . substr( $q, 0, 80 ) );
	}
}

function wp_remote_post( $url, $args ) {
	$req = json_decode( $args['body'], true );
	return array( 'body' => json_encode( LkMonday::answer( $req['query'], (array) $req['variables'] ) ), 'code' => 200 );
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}

// ---- asserting -------------------------------------------------------------------------
$GLOBALS['lk_pass'] = 0;
$GLOBALS['lk_fail'] = 0;
function lk_ok( $cond, $name, $got = null ) {
	if ( $cond ) {
		++$GLOBALS['lk_pass'];
		return;
	}
	++$GLOBALS['lk_fail'];
	fwrite( STDERR, 'FAIL ' . $name . ( null !== $got ? "\n     got: " . json_encode( $got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '' ) . "\n" );
}
function lk_eq( $got, $want, $name ) {
	$same = json_encode( $got ) === json_encode( $want );
	lk_ok( $same, $name . ( $same ? '' : "\n     want: " . json_encode( $want, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $same ? null : $got );
}
function lk_done() {
	echo ( $GLOBALS['lk_fail'] ? 'FAILED' : 'ok' ) . ' — ' . $GLOBALS['lk_pass'] . ' passed, ' . $GLOBALS['lk_fail'] . " failed\n";
	exit( $GLOBALS['lk_fail'] ? 1 : 0 );
}

// The plugin, all of it, as WordPress would load it.
require dirname( __DIR__ ) . '/leadkit.php';
$GLOBALS['lk_boot_filters'] = $GLOBALS['lk_filters'];
$GLOBALS['lk_boot_actions'] = $GLOBALS['lk_actions'];
