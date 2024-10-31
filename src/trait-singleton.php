<?php

namespace PIWA;

trait Singleton {
	protected static $instance = null;

	public $controller = null;

	public $atts = [];

	public static function get_instance(
		array|int|string|null $atts = [],
		string $content = '',
		string|\WP_Block $block_or_tagname = ''
	) {
		if ( ! is_object( self::$instance ) ) {
			self::$instance = new static();

			if ( isset( $atts['controller'] ) && is_object( $atts['controller'] ) ) {
				self::$instance->controller = $atts['controller'];
			}
		}

		/**
		 * Block and Shortcode render callbacks will call piwa() -> get_instance with three arguments.
		 *
		 * Merge them into the attributes array for the sake of parse_attributes().
		 */
		if ( is_array( $atts ) || empty( $atts ) ) {
			$atts                     = (array) $atts;
			$atts['content']          = $content;
			$atts['block_or_tagname'] = $block_or_tagname;
		}

		/**
		 * If a class has parse_attributes( $atts ) defined,
		 * it will receive an array $atts containing plugin configuration from Admin_Settings.
		 *
		 * @see PIWA::get_default_atts()
		 * @see Singleton::maybe_instantiate_a_class()
		 */
		if ( method_exists( self::$instance, 'parse_attributes' ) ) {
			self::$instance->parse_attributes( $atts );
		}

		return self::$instance;
	}

	/**
	 * Allows controller vars to be accessed as direct vars.
	 * Allows classes defined in the controller to be auto-instantiated when accessed.
	 *
	 * @see PIWA::autoload()
	 * @see PIWA::maybe_instantiate_objects()
	 */
	public function __get( $var_name ) {
		$var_name = strtolower( $var_name );

		if ( is_null( $this->controller ) && is_null( $this->$var_name ) ) {
			// We're in the controller.
			// Instantiate child classes if they are referenced directly.

			$this->maybe_instantiate_a_class( $var_name );

		} elseif ( ! isset( $this->$var_name ) && is_object( $this->controller ) ) {
			// We're not in the controller, but a var from the controller is being requested. Return it.

			if ( is_null( $this->controller->$var_name ) ) {
				$this->controller->maybe_instantiate_a_class( $var_name );
			}

			return $this->controller->$var_name;
		}

		if ( isset( $this->$var_name ) ) {

			// Return the var from the current class if it's set.

			return $this->$var_name;
		}

		return null;
	}

	public function maybe_instantiate_a_class( $class_or_var ) {

		if ( strtolower( $class_or_var ) !== $class_or_var ) {
			// There are uppercase letters; this must be a class name.
			$class = $class_or_var;
			$var   = strtolower( $class_or_var );
		} else {
			// The var is lowercase. Derive the class name.
			$class = str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $class_or_var ) ) );
			$var   = $class_or_var;
		}

		/**
		 * If this class is registered in PIWA::$class_autoload_index,
		 * instantiate it, pass the controller, and assign to the appropriate var.
		 */
		foreach ( $this->class_autoload_index as $classes ) {
			if ( array_key_exists( $class, $classes ) ) {

				/**
				 * Admin_Settings handles passing configuration attributes to other classes.
				 * Therefore, calculating default attributes as it's instantiating would cause infinite recursion.
				 * If Admin_Settings needs access to configuration, it should be done after instantiation,
				 * or ->parse_attributes() could access the controller.
				 */
				if ( 'Admin_Settings' === $class ) {
					$default_atts = [];
				} else {
					$default_atts = $this->get_default_atts();
				}

				/**
				 * All classes receive plugin configuration via an $atts array,
				 * which can be processed at instantiation by adding a parse_attributes( $atts ) method.
				 */
				$this->$var = call_user_func_array(
					[
						sprintf( '%s\%s', __NAMESPACE__, $class ),
						'get_instance',
					],
					[
						array_merge(
							$default_atts,
							[ 'controller' => $this ]
						),
					]
				);
				return true;
			}
		}
		return false;
	}

	/**
	 * Allows controller methods to be called directly from child objects within the namespace if using the Singleton trait.
	 */
	public function __call( $method, $args ) {
		if ( ! method_exists( $this, $method ) && method_exists( $this->controller, $method ) ) {
			return call_user_func_array( [ $this->controller, $method ], $args );
		}
		return call_user_func_array( [ $this, $method ], $args );
	}
}
