<?php
/**
 * Maintenance.
 *
 * Owns the plugin's upgrade lane. Two independent lanes, kept separate by design:
 *
 *  1. Schema convergence, keyed on SURECOOKIE_DB_VERSION. Runs on every request
 *     in every context, because consent logging writes to the table from
 *     front-end and REST requests too.
 *  2. Data migrations, keyed on a stable id per migration in a single ledger
 *     option. Runs only on a plugin version change, and only in admin/cron/WP-CLI.
 *
 * Read the docblock on {@see self::migrations()} before adding a migration.
 *
 * @package SureCookie
 * @subpackage SureCookie/Core
 * @since 0.0.1
 */

namespace SureCookie\Core;

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Database\Init as DB_Init;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Modules\Services\Declared_Cookies;
use SureCookie\Inc\Modules\Services\First_Party_Repair;
use SureCookie\Inc\Modules\Services\Installed_Services;
use SureCookie\Inc\Services\CookieCategoryMemory;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Update Compatibility
 *
 * @package SureCookie
 */
class Maintenance {
	use GetInstance;

	/**
	 * Ledger of migrations that have already completed on this site.
	 *
	 * Shape: `array<string, int>` of migration id => completion timestamp. One row
	 * per site, written non-autoloaded via {@see Update::option()} because it is
	 * only read on a plugin version change. Can never outgrow the registry:
	 * {@see self::write_ledger()} intersects it with the registered ids on every write.
	 *
	 * @since 1.3.0
	 */
	public const LEDGER_OPTION = 'surecookie_migrations';

	/**
	 * Option holding the plugin version this site last completed a run for.
	 *
	 * Autoloaded on purpose: it is read on every request as the upgrade gate,
	 * and `admin/analytics.php` reads it on `plugins_loaded` to derive
	 * `from_version` for the `plugin_updated` event.
	 *
	 * @since 1.3.0
	 */
	private const SAVED_VERSION_OPTION = 'surecookie_saved_version';

	/**
	 * Option holding the DB schema version (network-wide on multisite).
	 *
	 * Autoloaded on purpose: read on every request by {@see self::maybe_upgrade_db()}.
	 *
	 * @since 1.3.0
	 */
	private const DB_VERSION_OPTION = 'surecookie_db_version';

	/**
	 * Advisory lock held for the duration of a migration pass.
	 *
	 * @since 1.3.0
	 */
	private const LOCK_TRANSIENT = 'surecookie_migrations_lock';

	/**
	 * Lock lifetime. Long enough for a slow pass, short enough that a request
	 * killed mid-pass does not block the retry for long.
	 *
	 * @since 1.3.0
	 */
	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Throttle applied after a failed schema upgrade.
	 *
	 * @since 1.3.0
	 */
	private const DB_BACKOFF_TRANSIENT = 'surecookie_db_upgrade_backoff';

	/**
	 * Schema-upgrade retry interval, applied only after a failure.
	 *
	 * @since 1.3.0
	 */
	private const DB_BACKOFF_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Column used to spot-check that the consent-log schema actually landed.
	 *
	 * IMPORTANT: this must stay the newest column added by the current
	 * SURECOOKIE_DB_VERSION. Bump it whenever that constant gains a column, or
	 * verification passes vacuously and a silently failed `dbDelta` is recorded
	 * as a successful upgrade.
	 *
	 * @since 1.3.0
	 */
	private const SCHEMA_PROBE_COLUMN = 'is_forwarded';

	/**
	 * Unique index the atomic consent-log upsert depends on.
	 *
	 * @since 1.3.0
	 */
	private const CONSENT_LOG_UNIQUE_INDEX = 'session_unique';

	/**
	 * Registry ids that repair the schema rather than backfill data.
	 *
	 * These are exempt from the fresh-install seed: a backfill has nothing to do
	 * on a site with no history, but a schema repair is the only thing that can
	 * rescue an install whose activation DDL failed silently. See
	 * {@see self::seed_fresh_install()}.
	 *
	 * @since 1.3.0
	 */
	private const SCHEMA_REPAIRS = [ 'consent_log_unique_key' ];

	/**
	 * Pre-ledger per-migration flag options, mapped to their migration id.
	 *
	 * Legacy sites are adopted into the ledger on the first pass and these rows
	 * deleted at the same time; three were autoloaded by accident, so deleting
	 * them is also the option-hygiene fix. Safe to delete this map (and the four
	 * keys from `uninstall.php`) once no supported upgrade path can still start
	 * from a build that wrote them, roughly two releases after this ships.
	 *
	 * @since 1.3.0
	 */
	private const LEGACY_FLAGS = [
		'surecookie_consent_log_unique_key_migrated'    => 'consent_log_unique_key',
		'surecookie_services_backfill_v1'               => 'installed_services',
		'surecookie_cookie_provider_backfill_v1'        => 'scanned_cookie_providers',
		'surecookie_cookie_category_memory_backfill_v1' => 'cookie_category_memory',
	];

	/**
	 *  Constructor
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_init', self::class . '::init' );
		} else {
			add_action( 'init', self::class . '::init' );
		}
	}

	/**
	 * Init
	 *
	 * Hot path on a healthy, up-to-date site: two autoloaded option reads and a
	 * return. No transient read, no query, no lock.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function init(): void {
		/*
		 * Schema convergence is independent of the plugin version: DB_VERSION can
		 * change without a release, and consent logging writes to the table on
		 * front-end/REST requests, so this must not sit behind the saved-version
		 * or context gates below.
		 */
		self::maybe_upgrade_db();

		$saved_version = self::saved_version();

		/*
		 * Fresh install: seed the data backfills as already done. A site that
		 * never ran an older build has no legacy data to replay.
		 */
		if ( $saved_version === '' ) {
			self::seed_fresh_install();
			self::store_saved_version();
			return;
		}

		$comparison = version_compare( $saved_version, SURECOOKIE_VERSION );

		if ( $comparison === 0 ) {
			return;
		}

		/*
		 * Downgrade. Migrations must never replay backwards, but the marker still
		 * has to track the code that is actually running, or every admin request
		 * looks like an upgrade to `admin/analytics.php`.
		 */
		if ( $comparison > 0 ) {
			self::store_saved_version();
			return;
		}

		/*
		 * Migrations touch the settings option, the scanned-cookie option and the
		 * service catalog. On a front-end hit no module has booted (they load on
		 * init:999) and the catalog resolve pulls in WP_Filesystem, so defer to a
		 * ready context. Cron covers sites whose admin is rarely visited.
		 */
		if ( ! self::should_run_migrations() ) {
			return;
		}

		/*
		 * Peek at the lock before announcing the upgrade, so a pass already in
		 * flight (or a stale lock from a request that died mid-pass) does not fire
		 * `surecookie_update_before` every request for the whole TTL with no
		 * matching `_after`. Courtesy check only: manage_backward() does the real acquire.
		 */
		if ( self::pass_in_progress() ) {
			return;
		}

		/**
		 * Fires before SureCookie applies its upgrade work.
		 *
		 * Only fires on a real upgrade, and only in a context where the upgrade
		 * is actually performed.
		 *
		 * @since 0.0.1
		 *
		 * @param string $saved_version Version recorded before this run.
		 * @param string $new_version   Version being upgraded to.
		 */
		do_action( 'surecookie_update_before', $saved_version, SURECOOKIE_VERSION );

		/*
		 * A contended lock means this request did no work, so it must not record
		 * the upgrade as applied. Bumping here would strand every pending migration:
		 * the holder can still die mid-pass and hold the lock for its full TTL, and
		 * the next request would skip the pass, bump the version, and never look
		 * again. Returning instead lets the pass retry once the lock clears.
		 */
		if ( ! self::manage_backward() ) {
			return;
		}

		/*
		 * Bumped even when an individual migration failed: the failed one stays
		 * out of the ledger and retries on the next version change. Pinning the
		 * version here instead would retry a broken step on every request.
		 */
		self::store_saved_version();

		/**
		 * Fires after SureCookie has applied its upgrade work.
		 *
		 * @since 0.0.1
		 *
		 * @param string $saved_version Version recorded before this run.
		 * @param string $new_version   Version now recorded.
		 */
		do_action( 'surecookie_update_after', $saved_version, SURECOOKIE_VERSION );
	}

	/**
	 * Run every registered migration that has not completed on this site.
	 *
	 * Kept public under this name: the manual re-run entry point (`wp eval`,
	 * support debugging), the unit-test injection seam, and the generated Pro
	 * stub already declares it. Safe to call any time - the ledger, not the
	 * caller, decides what runs.
	 *
	 * @since 0.0.1
	 *
	 * @param array<string, callable(): void>|null $migrations Optional migration set.
	 *                                                         Test seam only: the ledger
	 *                                                         is pruned to the ids passed
	 *                                                         in, so a partial set drops
	 *                                                         the records it omits.
	 *                                                         Defaults to the registry.
	 * @return bool False when another request already holds the pass, in which
	 *              case nothing was attempted and the caller must not record the
	 *              upgrade as applied.
	 */
	public static function manage_backward( ?array $migrations = null ): bool {
		$migrations = $migrations ?? self::migrations();

		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			$ledger = self::adopt_legacy_flags( self::read_ledger() );

			foreach ( $migrations as $id => $callback ) {
				if ( isset( $ledger[ $id ] ) ) {
					continue;
				}

				// A typo in the registry would otherwise be skipped in silence and
				// retried forever, invisible even in development.
				if ( ! is_callable( $callback ) ) {
					self::report_failure( (string) $id, new \RuntimeException( 'migration callback is not callable' ) );
					continue;
				}

				try {
					$callback();
					$ledger[ $id ] = time();
				} catch ( \Throwable $e ) {
					// Left out of the ledger on purpose so the next version change
					// retries it. One failing migration must not stop the rest.
					self::report_failure( $id, $e );
				}
			}

			self::write_ledger( $ledger, $migrations );

			// Not a migration, deliberately unledgered: a convergence step that is
			// a no-op unless the stale key is actually present. See its docblock.
			self::remove_wp_consent_api_flag();
		} finally {
			self::release_lock();
		}

		return true;
	}

	/**
	 * Re-run dbDelta when SURECOOKIE_DB_VERSION changed, so column additions
	 * apply without a deactivate/reactivate cycle.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function maybe_upgrade_db(): void {
		$stored = is_multisite()
			? get_network_option( null, self::DB_VERSION_OPTION )
			: get_option( self::DB_VERSION_OPTION );

		if ( $stored === SURECOOKIE_DB_VERSION ) {
			return;
		}

		/*
		 * A schema upgrade that cannot succeed (locked table, insufficient grants,
		 * a dbDelta that keeps failing) would otherwise re-run the whole DDL every
		 * request for the life of the site - on multisite, one dbDelta per blog.
		 * Back off between attempts.
		 *
		 * The backoff is scoped to match the version it guards: on multisite the
		 * version is a network option and create_db_tables() sweeps every blog, so
		 * a per-blog transient would let the next blog re-run the sweep and
		 * throttle nothing.
		 */
		$multisite = is_multisite();

		if ( $multisite ? get_site_transient( self::DB_BACKOFF_TRANSIENT ) : get_transient( self::DB_BACKOFF_TRANSIENT ) ) {
			return;
		}

		try {
			DB_Init::create_db_tables();

			/*
			 * dbDelta() swallows SQL errors (a failed ALTER on a locked table),
			 * so verify the column landed before recording the version.
			 * Otherwise a silently failed upgrade is masked forever.
			 */
			if ( self::schema_upgrade_applied() ) {
				self::store_db_version();
				return;
			}

			Logger::get_instance()->log( 'SureCookie: schema upgrade did not apply, will retry.', 'warning' );
		} catch ( \Throwable $e ) {
			Logger::get_instance()->log( 'SureCookie: schema upgrade failed - ' . $e->getMessage(), 'warning' );
		}

		if ( $multisite ) {
			set_site_transient( self::DB_BACKOFF_TRANSIENT, 1, self::DB_BACKOFF_TTL );
		} else {
			set_transient( self::DB_BACKOFF_TRANSIENT, 1, self::DB_BACKOFF_TTL );
		}
	}

	/**
	 * Persist the current DB schema version (network-wide on multisite).
	 *
	 * Called by `loader.php` on activation, where `create_db_tables()` has just
	 * run inline, so it stays public.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function store_db_version(): void {
		if ( is_multisite() ) {
			update_network_option( null, self::DB_VERSION_OPTION, SURECOOKIE_DB_VERSION );
		} else {
			update_option( self::DB_VERSION_OPTION, SURECOOKIE_DB_VERSION, true );
		}
	}

	/**
	 * The migration registry.
	 *
	 * ADDING A MIGRATION
	 * ------------------
	 * 1. Add one line here: a permanent, unique id => the private method.
	 * 2. Write the private method. Return on success, throw on failure.
	 *
	 * That is the whole checklist. There is no option key to invent and nothing
	 * to add to `uninstall.php`, because completion is recorded inside the single
	 * {@see self::LEDGER_OPTION} row that is already listed there.
	 *
	 * Rules:
	 *
	 *  1. Migrations are gated on the id, not a version number: every id not in
	 *     the ledger runs, whatever version the site upgrades from. Deliberate -
	 *     this repo writes unreleased code against the `@since 1.3.0` placeholder,
	 *     which `version_compare()` silently sorts below every real version, so a
	 *     version-keyed migration would never run and never say so.
	 *  2. Ids are permanent. Renaming one re-runs it everywhere; removing one
	 *     retires it ({@see self::write_ledger()} drops unknown ids, so the ledger
	 *     never grows past this list). Safe to delete a migration once no supported
	 *     upgrade path can reach it, in practice two-plus majors after its release.
	 *  3. The callback MUST be idempotent: it can be re-entered after a crash, on
	 *     a restored database, or when its ledger entry is lost.
	 *  4. The callback MUST throw on failure - returning quietly reads as success
	 *     and never retries. The three existing backfills cannot fully honour
	 *     this: the module methods they call discard the bool from their option
	 *     writes, so their ledger entry records "attempted", not "verified". Do
	 *     not assume the guarantee holds when adding a migration alongside them.
	 *  5. A migration that repairs the SCHEMA rather than backfilling data must
	 *     also be listed in {@see self::SCHEMA_REPAIRS}, so the fresh-install seed
	 *     does not mark it done sight unseen.
	 *
	 * @since 1.3.0
	 * @return array<string, callable(): void>
	 */
	private static function migrations(): array {
		return [
			'consent_log_unique_key'     => [ self::class, 'migrate_consent_log_unique_key' ],
			'installed_services'         => [ self::class, 'backfill_installed_services' ],
			'scanned_cookie_providers'   => [ self::class, 'backfill_scanned_cookie_providers' ],
			'cookie_category_memory'     => [ self::class, 'backfill_cookie_category_memory' ],
			'first_party_cookie_domains' => [ self::class, 'repair_first_party_cookie_domains' ],
		];
	}

	/**
	 * Whether this request is in a fit state to run data migrations.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private static function should_run_migrations(): bool {
		if ( is_admin() || wp_doing_cron() ) {
			return true;
		}

		return defined( 'WP_CLI' ) && constant( 'WP_CLI' );
	}

	/**
	 * Mark the data backfills as done for a brand new install.
	 *
	 * Schema repairs are deliberately NOT seeded, only data backfills. A backfill
	 * has nothing to find without legacy data, but a schema repair is the site's
	 * only self-heal path: `create_db_tables()` discards `create()`'s result and
	 * `loader.php` records the DB version anyway, so an activation whose `dbDelta`
	 * silently failed looks up to date to {@see self::maybe_upgrade_db()} forever.
	 * Seeding it here would close that last door. Letting it run costs one indexed
	 * COUNT on the first upgrade, since the repair early-returns when the index exists.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function seed_fresh_install(): void {
		$now    = time();
		$ledger = [];

		foreach ( array_keys( self::migrations() ) as $id ) {
			if ( in_array( $id, self::SCHEMA_REPAIRS, true ) ) {
				continue;
			}

			$ledger[ $id ] = $now;
		}

		Update::option( self::LEDGER_OPTION, $ledger );
	}

	/**
	 * Read and normalise the completion ledger.
	 *
	 * @since 1.3.0
	 * @return array<string, int>
	 */
	private static function read_ledger(): array {
		$stored = get_option( self::LEDGER_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$ledger = [];

		foreach ( $stored as $id => $completed_at ) {
			// Cast rather than type-check: PHP normalises a numeric-string array
			// key to int, so id '863' comes back as int 863. Type-rejecting it
			// would drop the row every read and re-run that migration forever.
			$id = strval( $id );

			if ( $id !== '' && is_numeric( $completed_at ) ) {
				$ledger[ $id ] = (int) $completed_at;
			}
		}

		ksort( $ledger );

		return $ledger;
	}

	/**
	 * Persist the ledger, dropping ids that are no longer registered.
	 *
	 * The stored value is re-read with the option cache bypassed and merged in,
	 * so a concurrent pass that completed a migration between our read and this
	 * write is not silently rolled back. Our own entries win on collision.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, int>              $ledger     Ledger to persist.
	 * @param array<string, callable(): void> $migrations Registry it is bounded by.
	 * @return void
	 */
	private static function write_ledger( array $ledger, array $migrations ): void {
		wp_cache_delete( self::LEDGER_OPTION, 'options' );

		/*
		 * get_option() consults the `notoptions` cache BEFORE the per-option one,
		 * and this pass's first read poisoned it when the row did not yet exist.
		 * Without evicting that entry too, the re-read below short-circuits to
		 * empty and the merge protects nothing on exactly the site it was written
		 * for: a legacy install adopting the ledger for the first time.
		 */
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) ) {
			unset( $notoptions[ self::LEDGER_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		$stored = self::read_ledger();

		/*
		 * Bounded by the caller's set UNION the registry, so retired ids never
		 * accumulate while a partial set (test seam, or a support engineer
		 * re-running one id) cannot erase records it was not asked about.
		 */
		$merged = array_intersect_key( $ledger + $stored, $migrations + self::migrations() );
		ksort( $merged );

		if ( $merged === $stored ) {
			return;
		}

		Update::option( self::LEDGER_OPTION, $merged );
	}

	/**
	 * Fold any pre-ledger per-migration flags into the ledger and delete them.
	 *
	 * A flag that exists but is falsy means the migration never completed, so it
	 * is deleted without being adopted and the migration runs.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, int> $ledger Current ledger.
	 * @return array<string, int>
	 */
	private static function adopt_legacy_flags( array $ledger ): array {
		foreach ( self::LEGACY_FLAGS as $legacy_option => $id ) {
			$flag = get_option( $legacy_option, null );

			if ( $flag === null ) {
				continue;
			}

			if ( $flag && ! isset( $ledger[ $id ] ) ) {
				$ledger[ $id ] = is_numeric( $flag ) ? (int) $flag : time();
			}

			delete_option( $legacy_option );
		}

		return $ledger;
	}

	/**
	 * Report a failed migration.
	 *
	 * `Logger::log()` only writes to the debug log in development mode, so the
	 * action is the supported way to surface an upgrade failure on a live site.
	 *
	 * @since 1.3.0
	 *
	 * @param string     $id    Migration id.
	 * @param \Throwable $error Error thrown by the migration.
	 * @return void
	 */
	private static function report_failure( string $id, \Throwable $error ): void {
		/*
		 * Logged as a warning, not an error: Logger routes 'error' to
		 * WP_CLI::error(), which halts the command. A failed backfill must not
		 * abort `wp plugin update`.
		 */
		Logger::get_instance()->log(
			sprintf( 'SureCookie: migration "%1$s" failed - %2$s', $id, $error->getMessage() ),
			'warning'
		);

		/**
		 * Fires when a migration step fails.
		 *
		 * @since 1.3.0
		 *
		 * @param string     $id    Migration id.
		 * @param \Throwable $error Error thrown by the migration.
		 */
		do_action( 'surecookie_migration_failed', $id, $error );
	}

	/**
	 * Whether a migration pass is already in flight.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private static function pass_in_progress(): bool {
		return (bool) get_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Take the advisory migration lock.
	 *
	 * A transient is the right primitive for a lock (wrong for a completion
	 * record): losing it to object-cache eviction degrades to two concurrent
	 * passes - the pre-refactor behaviour - whereas losing a completion record
	 * would silently re-run a migration.
	 *
	 * Advisory, not mutual exclusion: `get_transient()` then `set_transient()` is
	 * not atomic, so it only shrinks the collision window to the microseconds
	 * between the two calls. A true lock would need MySQL `GET_LOCK` (not portable
	 * to the SQLite drop-in) or `add_option()` semantics (unreliable), neither
	 * worth it when every migration must be idempotent.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private static function acquire_lock(): bool {
		if ( self::pass_in_progress() ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		return true;
	}

	/**
	 * Release the advisory migration lock.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Read the recorded plugin version.
	 *
	 * @since 1.3.0
	 * @return string Empty string on a fresh install or a corrupt value.
	 */
	private static function saved_version(): string {
		$saved = get_option( self::SAVED_VERSION_OPTION, '' );

		return is_scalar( $saved ) ? strval( $saved ) : '';
	}

	/**
	 * Record the running plugin version as applied.
	 *
	 * Deliberately autoloaded, and deliberately never written earlier than
	 * `init`/`admin_init`: `admin/analytics.php` captures the previous value on
	 * `plugins_loaded` to build the `plugin_updated` event, so moving this write
	 * any earlier would silently destroy that event.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function store_saved_version(): void {
		update_option( self::SAVED_VERSION_OPTION, SURECOOKIE_VERSION, true );
	}

	/**
	 * Spot-check that the newest column of the current SURECOOKIE_DB_VERSION
	 * actually exists.
	 *
	 * Network-wide on multisite, to match the two things it sits between: the DDL
	 * sweep covers every blog and the version marker it gates is a network option.
	 * Probing only the current blog would record a partially applied upgrade as
	 * done and leave the failed blog with no retry - Pro's consent forwarding then
	 * writes the new columns into a subsite that never got them.
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	private static function schema_upgrade_applied(): bool {
		if ( ! is_multisite() ) {
			return self::schema_probe_column_exists();
		}

		// `number => 0` on purpose: get_sites() otherwise caps at 100 and would
		// verify a subset, which is a quieter version of the same bug.
		$blog_ids = get_sites(
			[
				'fields'   => 'ids',
				'number'   => 0,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			]
		);

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$applied = self::schema_probe_column_exists();
			restore_current_blog();

			if ( ! $applied ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the probe column exists in the current blog's consent-log table.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private static function schema_probe_column_exists(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One-shot schema verification; %i is the WP 6.2+ identifier placeholder.
		$column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', ConsentLog::get_instance()->get_tablename(), self::SCHEMA_PROBE_COLUMN ) );

		return ! empty( $column );
	}

	/**
	 * Strip the removed `wp_consent_api_enabled` flag from the stored settings.
	 *
	 * The flag shipped as dead code (issue #863) and was dropped from the schema;
	 * Settings::get merges the raw stored array over the defaults, so a leftover
	 * would still surface in the admin payload and mislead an auditor.
	 *
	 * Deliberately unledgered: a convergence step, not a migration - it reads one
	 * already-autoloaded option and no-ops unless the dead key is present, so a
	 * ledger entry would buy nothing and later need retiring. Reads the raw stored
	 * array, not the defaults-merged one, so it cannot materialise every default
	 * into the database.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function remove_wp_consent_api_flag(): void {
		$settings = get_option( SURECOOKIE_SETTINGS_OPTION, [] );

		if ( ! is_array( $settings ) || ! array_key_exists( 'wp_consent_api_enabled', $settings ) ) {
			return;
		}

		unset( $settings['wp_consent_api_enabled'] );
		update_option( SURECOOKIE_SETTINGS_OPTION, $settings );
	}

	/**
	 * Backfill the Known Services installed registry.
	 *
	 * Marks catalog services whose full declared-cookie set already exists on the
	 * site as installed, so declarations a user made by hand before the Known
	 * Services feature surface as installed after upgrade. Idempotent: the module
	 * dedups by name+domain, so re-entering creates no duplicate cookies.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function backfill_installed_services(): void {
		Installed_Services::get_instance()->backfill_from_existing_cookies();
	}

	/**
	 * Backfill the provider on already-stored scanned cookies.
	 *
	 * Earlier scans stored an empty provider because the ingest read a key the
	 * scan API does not send. The next scan repopulates it, but until then the
	 * Provider column shows a dash, so fill what the bundled catalog knows by
	 * name+domain. Blanks only - a provider a scan has since resolved is never
	 * overwritten, which also makes this safe to re-enter.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function backfill_scanned_cookie_providers(): void {
		Declared_Cookies::get_instance()->backfill_missing_providers();
	}

	/**
	 * Recover cookie categories the admin assigned before the assignment store
	 * existed.
	 *
	 * Without this, the first scan after upgrading would revert those choices one
	 * final time - the very rework the store exists to prevent. Only recovers
	 * assignments the last scan's snapshot proves were manual, and re-remembering
	 * an already-current assignment is suppressed, so this is safe to re-enter.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function backfill_cookie_category_memory(): void {
		CookieCategoryMemory::backfill_from_reported_snapshot();
	}

	/**
	 * Replace the catalog's first-party placeholder domain in stored cookies
	 * with this site's own host, and fold the duplicates it created.
	 *
	 * The bundled catalog cannot know its install host, so it declares first-party
	 * cookies (_ga, _fbp, _clck, ...) on the literal `.domain.com`. That string
	 * was copied verbatim into the scanned- and custom-cookie stores, where it is
	 * published to visitors in the cookie policy's Domain column and never matches
	 * the domain the scanner observed. Resolving the catalog fixes new writes only;
	 * for a cookie both declared and observed the forward fix makes the stale row
	 * permanent. See {@see First_Party_Repair} for the full argument.
	 *
	 * Idempotent by convergence: a second pass finds no placeholder. Unlike the
	 * three backfills above, this one honours rule 4 - every write is checked and throws.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private static function repair_first_party_cookie_domains(): void {
		First_Party_Repair::run();
	}

	/**
	 * Apply the consent-log UNIQUE KEY migration introduced for issue #457.
	 *
	 * `dbDelta` only runs on activation; auto-updates and file swaps never trigger
	 * it. Without this, existing installs run the `INSERT ... ON DUPLICATE KEY
	 * UPDATE` in {@see ConsentLog::upsert()} without the `UNIQUE KEY (session_id,
	 * timestamp)` it relies on, silently degrading to a plain `INSERT` and leaving
	 * the race in place.
	 *
	 * Flow:
	 *   1. (MySQL only) Return when the index is already present - the common case,
	 *      keeping the expensive dedup off every healthy site.
	 *   2. (MySQL only) Drop duplicate `(session_id, timestamp)` rows, since `dbDelta`
	 *      silently skips `ADD UNIQUE KEY` when duplicates exist. Skipped on SQLite,
	 *      whose driver has no multi-table `DELETE ... JOIN`.
	 *   3. Re-run the table DDL via `ConsentLog::create()`.
	 *   4. Throw unless the index is confirmed, so a partially created table (#381)
	 *      retries on the next version change instead of being masked forever. MySQL
	 *      confirms via `information_schema.STATISTICS`; on SQLite that view is
	 *      unreliable (WordPress/sqlite-database-integration#146), so a successful
	 *      `CREATE TABLE` (key inline) is the signal.
	 *
	 * Multisite: `create()` delegates to `network_create_tables()` across every blog,
	 * while the dedup and verification touch only the current blog's table; the
	 * per-site ledger means each blog verifies as its admin first loads.
	 *
	 * @since 1.0.0
	 * @throws \RuntimeException When the unique key is still absent afterwards.
	 * @return void
	 */
	private static function migrate_consent_log_unique_key(): void {
		global $wpdb;

		$consent_log = ConsentLog::get_instance();
		$table       = $consent_log->get_tablename();
		$is_sqlite   = self::is_sqlite();

		// Step 1 (MySQL only): nothing to repair when the key is already there.
		if ( ! $is_sqlite && self::consent_log_index_count( $table ) >= 1 ) {
			return;
		}

		// Step 2 (MySQL only): dedupe rows that would make `dbDelta` silently
		// skip the UNIQUE KEY. Multi-table `DELETE ... JOIN` is MySQL-specific.
		if ( ! $is_sqlite ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One-time upgrade dedup; the table name is a server-controlled constant from wpdb prefix + plugin.
			$dedup_sql = $wpdb->prepare(
				'DELETE t1 FROM %i AS t1 INNER JOIN %i AS t2 ON t1.session_id = t2.session_id AND t1.timestamp = t2.timestamp AND t1.id < t2.id',
				$table,
				$table
			);

			if ( is_string( $dedup_sql ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $dedup_sql is the output of $wpdb->prepare() above.
				$wpdb->query( $dedup_sql );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		/*
		 * Step 3: (re)apply the schema. `create()` runs the idempotent CREATE
		 * TABLE DDL, which carries the unique key inline on both engines,
		 * back-fills a partial table, and returns whether the table now exists.
		 */
		$table_ready = $consent_log->create( $consent_log->get_columns_definition() );

		// Step 4 (SQLite): a successful CREATE TABLE is the only usable signal.
		if ( $is_sqlite ) {
			if ( ! $table_ready ) {
				throw new \RuntimeException( 'SureCookie: consent log table is still absent after CREATE TABLE.' );
			}

			return;
		}

		// Step 4 (MySQL): verify the UNIQUE KEY landed. `dbDelta` swallows the
		// 1062 duplicate-key error, so a failure is otherwise invisible.
		if ( self::consent_log_index_count( $table ) < 1 ) {
			throw new \RuntimeException( 'SureCookie: consent log unique key is still absent after dbDelta.' );
		}
	}

	/**
	 * Count the consent-log unique index (MySQL only).
	 *
	 * @since 1.3.0
	 *
	 * @param string $table Fully qualified table name.
	 * @return int
	 */
	private static function consent_log_index_count( string $table ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME   = %s
				   AND INDEX_NAME   = %s',
				$table,
				self::CONSENT_LOG_UNIQUE_INDEX
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
	}

	/**
	 * Whether WordPress is on the SQLite database driver (Playground / WP Studio)
	 * rather than MySQL/MariaDB.
	 *
	 * Detects the engine via the SQLite integration's own signals: `DB_ENGINE`
	 * (its drop-in sets it to `sqlite`), falling back to `SQLITE_DB_DROPIN_VERSION`.
	 * An `information_schema` probe is deliberately avoided - the driver emulates
	 * `information_schema.TABLES`, so a probe returns true there and cannot tell
	 * the engines apart (WordPress/sqlite-database-integration#146).
	 *
	 * @since 1.2.3
	 * @return bool
	 */
	private static function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && DB_ENGINE === 'sqlite' )
			|| defined( 'SQLITE_DB_DROPIN_VERSION' );
	}
}
