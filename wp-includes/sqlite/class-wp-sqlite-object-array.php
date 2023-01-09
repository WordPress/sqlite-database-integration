<?php
/**
 * Modify queried data to a PHP object.
 *
 * @package wp-sqlite-integration
 * @since 1.0.0
 */

/**
 * Class to change queried data to PHP object.
 *
 * @author kjm
 */
class WP_SQLite_Object_Array {

	/**
	 * Constructor.
	 *
	 * @param array    $data The data to be converted.
	 * @param stdClass $node The node to be converted.
	 */
	public function __construct( $data = null, &$node = null ) {
		foreach ( $data as $key => $value ) {
			if ( ! $node ) {
				$node =& $this;
			}
			if ( is_array( $value ) ) {
				$node->$key = new stdClass();
				self::__construct( $value, $node->$key );
			} else {
				$node->$key = $value;
			}
		}
	}
}
