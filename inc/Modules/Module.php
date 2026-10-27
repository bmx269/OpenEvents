<?php
/**
 * Module contract.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules;

/**
 * A feature that site owners can switch on and off under Events → Settings → Modules.
 *
 * Add-on plugins register modules with the openevents/modules filter.
 * Extend AbstractModule rather than implementing this directly so new optional
 * methods do not break you.
 */
interface Module {

	/** Unique, stable id, e.g. "ical" or "acme-zoom". */
	public function id(): string;

	public function label(): string;

	public function description(): string;

	/**
	 * Ids of modules that must be enabled for this one to boot.
	 *
	 * @return string[]
	 */
	public function requires(): array;

	/** Registers hooks. Called on plugins_loaded, only when the module is enabled. */
	public function boot(): void;

	/** Creates or upgrades module storage. Called when enabled and when version() changes. */
	public function install(): void;

	/** Bump to trigger install() again after a schema change. */
	public function version(): string;
}
