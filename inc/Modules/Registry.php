<?php
/**
 * Module registry.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules;

use OpenEvents\Settings;

/**
 * Discovers modules, resolves dependencies, runs installs, and boots enabled ones.
 */
final class Registry {

	private const VERSIONS_OPTION = 'openevents_module_versions';

	/** @var array<string, Module>|null */
	private ?array $modules = null;

	/** @var string[] */
	private array $booted = array();

	public function __construct( private Settings $settings ) {}

	/**
	 * @return array<string, Module> Keyed by id.
	 */
	public function all(): array {
		if ( null === $this->modules ) {
			$bundled = array( new ICal\Module(), new Rsvp\Module() );

			/**
			 * Filters the available modules. Append your Module instance to add a feature
			 * that site owners can toggle in Events → Settings → Modules.
			 *
			 * @param Module[] $modules Modules.
			 */
			$modules = apply_filters( 'openevents/modules', $bundled );

			$this->modules = array();
			foreach ( $modules as $module ) {
				if ( $module instanceof Module ) {
					$this->modules[ $module->id() ] = $module;
				}
			}
		}
		return $this->modules;
	}

	public function get( string $id ): ?Module {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @return string[] Ids of modules that booted this request.
	 */
	public function enabled_ids(): array {
		return $this->booted;
	}

	public function is_enabled( string $id ): bool {
		return in_array( $id, $this->booted, true );
	}

	/**
	 * Adds every transitive requirement of the given ids and drops unknown ids.
	 *
	 * @param string[] $ids Requested module ids.
	 * @return string[]
	 */
	public function resolve( array $ids ): array {
		$all      = $this->all();
		$resolved = array();
		$queue    = array_values( $ids );
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $all[ $id ] ) && ! in_array( $id, $resolved, true ) ) {
				$resolved[] = $id;
				array_push( $queue, ...$all[ $id ]->requires() );
			}
		}
		return $resolved;
	}

	public function boot(): void {
		// Third-party modules may be registered by plugins that load after us.
		add_action( 'plugins_loaded', array( $this, 'boot_enabled' ), 20 );
	}

	public function boot_enabled(): void {
		$versions = get_option( self::VERSIONS_OPTION, array() );
		$versions = is_array( $versions ) ? $versions : array();
		$changed  = false;

		foreach ( $this->resolve( $this->settings->enabled_modules() ) as $id ) {
			$module = $this->get( $id );
			if ( ( $versions[ $id ] ?? null ) !== $module->version() ) {
				$module->install();
				$versions[ $id ] = $module->version();
				$changed         = true;
			}
			$module->boot();
			$this->booted[] = $id;
		}

		if ( $changed ) {
			update_option( self::VERSIONS_OPTION, $versions );
		}

		/**
		 * Fires after enabled modules have booted.
		 *
		 * @param string[] $booted Booted module ids.
		 */
		do_action( 'openevents/modules_booted', $this->booted );
	}
}
