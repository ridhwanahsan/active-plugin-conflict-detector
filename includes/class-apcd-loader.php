<?php
/**
 * Loader for registering WordPress hooks in a structured manner.
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APCD_Loader {
	/**
	 * Actions to register.
	 *
	 * @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}>
	 */
	private $actions = array();

	/**
	 * Filters to register.
	 *
	 * @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}>
	 */
	private $filters = array();

	/**
	 * Add an action.
	 *
	 * @param string $hook Hook name.
	 * @param object $component Instance.
	 * @param string $callback Method.
	 * @param int    $priority Priority.
	 * @param int    $accepted_args Args.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Add a filter.
	 *
	 * @param string $hook Hook name.
	 * @param object $component Instance.
	 * @param string $callback Method.
	 * @param int    $priority Priority.
	 * @param int    $accepted_args Args.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Run: Register hooks with WP.
	 */
	public function run() {
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), (int) $hook['priority'], (int) $hook['accepted_args'] );
		}
		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), (int) $hook['priority'], (int) $hook['accepted_args'] );
		}
	}
}

