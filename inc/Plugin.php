<?php
/**
 * Service wiring.
 *
 * @package OpenEvents
 */

namespace OpenEvents;

use OpenEvents\Admin\AdminColumns;
use OpenEvents\Admin\AdminApp;
use OpenEvents\Content\Meta;
use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\Blocks;
use OpenEvents\Frontend\Editor;
use OpenEvents\Frontend\Templates;
use OpenEvents\Integrations\Abilities;
use OpenEvents\Integrations\BlockBindings;
use OpenEvents\Modules\Registry;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Occurrences\Sync;
use OpenEvents\Rest\OccurrencesController;

/**
 * Holds the shared service instances and registers their hooks.
 *
 * Add-ons reach services through openevents(), e.g.
 * openevents()->occurrences()->query( [ ... ] ).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private Repository $occurrences;
	private Registry $modules;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();
		$plugin->register();

		/**
		 * Fires once core services are registered and enabled modules have booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'openevents/loaded', $plugin );
	}

	private function __construct() {
		$this->settings    = new Settings();
		$this->occurrences = new Repository();
		$this->modules     = new Registry( $this->settings );
	}

	private function register(): void {
		Installer::register();
		( new PostTypes( $this->settings ) )->register();
		( new Meta( $this->settings ) )->register();
		\OpenEvents\Content\Attributes::register();
		( new Sync() )->register();
		\OpenEvents\Attendance\Cleanup::register();
		$rest = new OccurrencesController( $this->occurrences );
		$rest->register();
		( new Abilities( $rest ) )->register();
		( new BlockBindings() )->register();
		( new AdminApp( $this->settings, $this->modules ) )->register();
		( new Blocks() )->register();
		( new Editor() )->register();
		( new Templates() )->register();

		if ( is_admin() ) {
			( new AdminColumns() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'openevents', Cli\Command::class );
		}

		$this->modules->boot();
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function occurrences(): Repository {
		return $this->occurrences;
	}

	public function modules(): Registry {
		return $this->modules;
	}
}
