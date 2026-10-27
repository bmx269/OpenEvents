<?php
/**
 * Module base class.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules;

/**
 * Sensible defaults for optional Module methods.
 */
abstract class AbstractModule implements Module {

	public function requires(): array {
		return array();
	}

	public function install(): void {}

	public function version(): string {
		return '1';
	}
}
