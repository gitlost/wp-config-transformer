<?php

/**
 * Transform a wp-config.php file.
 *
 * EXAMPLE:
 * $config_transformer = new WPConfigTransformer( '/path/to/wp-config.php' );
 * $config_transformer->exists( 'constant', 'WP_DEBUG' );       // Returns true
 * $config_transformer->add( 'constant', 'WP_DEBUG', true );    // Returns false
 * $config_transformer->update( 'constant', 'WP_DEBUG', true ); // Returns true
 * $config_transformer->remove( 'constant', 'WP_DEBUG' );       // Returns true
 */
class WPConfigTransformer {

	/**
	 * @var string
	 */
	protected $wp_config_path;

	/**
	 * @var string
	 */
	protected $wp_config_src;

	/**
	 * @var array
	 */
	protected $wp_configs = [];

	/**
	 * Instantiate the class with a valid wp-config.php.
	 *
	 * @throws Exception
	 *
	 * @param string $wp_config_path Path to a wp-config.php file.
	 */
	public function __construct( $wp_config_path ) {
		if ( ! file_exists( $wp_config_path ) ) {
			throw new Exception( 'wp-config.php file does not exist.' );
		}
		if ( ! is_readable( $wp_config_path ) ) {
			throw new Exception( 'wp-config.php file is not readable.' );
		}
		if ( ! is_writable( $wp_config_path ) ) {
			throw new Exception( 'wp-config.php file is not writable.' );
		}
		$this->wp_config_path = $wp_config_path;
	}

	/**
	 * Check whether a config exists in the wp-config.php file.
	 *
	 * @throws Exception
	 *
	 * @param string $type Config type (constant or variable).
	 * @param string $name Config name.
	 *
	 * @return bool
	 */
	public function exists( $type, $name ) {
		$wp_config_src = file_get_contents( $this->wp_config_path );
		if ( ! $wp_config_src ) {
			throw new Exception( 'wp-config.php file is empty.' );
		}
		$this->wp_config_src = $wp_config_src;

		$wp_configs = $this->parse_wp_config( $this->wp_config_src );
		if ( ! $wp_configs ) {
			throw new Exception( 'No constants defined in wp-config.php file.' );
		}
		$this->wp_configs = $wp_configs;

		if ( ! isset( $this->wp_configs[ $type ] ) ) {
			throw new Exception( "Config type '{$type}' does not exist." );
		}

		return isset( $this->wp_configs[ $type ][ $name ] );
	}

	/**
	 * Add a config to the wp-config.php file.
	 *
	 * @throws Exception
	 *
	 * @param string $type   Config type (constant or variable).
	 * @param string $name   Config name.
	 * @param mixed  $value  Config value.
	 * @param bool   $raw    (optional) Force raw format value without quotes (only applies to strings).
	 * @param string $target (optional) Config placement target (definition is inserted before).
	 *
	 * @return bool
	 */
	public function add( $type, $name, $value, $raw = false, $target = null ) {
		if ( $this->exists( $type, $name ) ) {
			return false;
		}

		$target = is_null( $target ) ? "/* That's all, stop editing!" : $target;

		if ( false === strpos( $this->wp_config_src, $target ) ) {
			throw new Exception( 'Unable to locate placement target.' );
		}

		$new_value = ( $raw && is_string( $value ) ) ? $value : var_export( $value, true );
		$new_src   = $this->normalize( $type, $name, $new_value );

		$contents = str_replace( $target, $new_src . "\n\n" . $target, $this->wp_config_src );

		return $this->save( $contents );
	}

	/**
	 * Update an existing config in the wp-config.php file.
	 *
	 * @param string $type      Config type (constant or variable).
	 * @param string $name      Config name.
	 * @param mixed  $value     Config value.
	 * @param bool   $raw       (optional) Force raw format value without quotes (only applies to strings).
	 * @param bool   $normalize (optional) Normalize config definition syntax using WP Coding Standards.
	 *
	 * @return bool
	 */
	public function update( $type, $name, $value, $raw = false, $normalize = false ) {
		if ( ! $this->exists( $type, $name ) ) {
			return $this->add( $type, $name, $value, $raw );
		}

		$old_value = $this->wp_configs[ $type ][ $name ]['value'];
		$old_src   = $this->wp_configs[ $type ][ $name ]['src'];

		$new_value = ( $raw && is_string( $value ) ) ? $value : var_export( $value, true );
		$new_src = ( $normalize ) ? $this->normalize( $type, $name, $new_value ) : str_replace( $old_value, $new_value, $old_src );

		$contents = str_replace( $old_src, $new_src, $this->wp_config_src );

		return $this->save( $contents );
	}

	/**
	 * Remove a config from the wp-config.php file.
	 *
	 * @param string $type Config type (constant or variable).
	 * @param string $name Config name.
	 *
	 * @return bool
	 */
	public function remove( $type, $name ) {
		if ( ! $this->exists( $type, $name ) ) {
			return false;
		}

		$pattern  = sprintf( '/%s\s*(.)/', preg_quote( $this->wp_configs[ $type ][ $name ]['src'] ) );
		$contents = preg_replace( $pattern, '$1', $this->wp_config_src );

		return $this->save( $contents );
	}

	/**
	 * Return normalized src for a name/value pair.
	 *
	 * @throws Exception
	 *
	 * @param string $type  Config type (constant or variable).
	 * @param string $name  Config name.
	 * @param mixed  $value Config value.
	 *
	 * @return string
	 */
	protected function normalize( $type, $name, $value ) {
		if ( 'constant' === $type ) {
			$placeholder = "define( '%s', %s );";
		} elseif ( 'variable' === $type ) {
			$placeholder = '$%s = %s;';
		} else {
			throw new Exception( "Unable to normalize config type '{$type}'." );
		}

		return sprintf( $placeholder, $name, $value );
	}

	/**
	 * Parse config source and return an array.
	 *
	 * @param string $src Config file source.
	 *
	 * @return array
	 */
	protected function parse_wp_config( $src ) {
		$configs = [];

		preg_match_all( '/^\h*define\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(.*?)\s*\)\s*;/ims', $src, $constants );
		preg_match_all( '/^\h*\$(\w+)\s*=\s*(.*?)\s*;/ims', $src, $variables );

		if ( ! empty( $constants[0] ) && ! empty( $constants[1] ) && ! empty( $constants[2] ) ) {
			foreach ( $constants[1] as $index => $name ) {
				$configs['constant'][ $name ] = array(
					'src'   => $constants[0][ $index ],
					'value' => $constants[2][ $index ],
				);
			}
		}

		if ( ! empty( $variables[0] ) && ! empty( $variables[1] ) && ! empty( $variables[2] ) ) {
			foreach ( $variables[1] as $index => $name ) {
				$configs['variable'][ $name ] = array(
					'src'   => $variables[0][ $index ],
					'value' => $variables[2][ $index ],
				);
			}
		}

		return $configs;
	}

	/**
	 * Save the wp-config.php file with new contents.
	 *
	 * @throws Exception
	 *
	 * @param string $contents New config contents.
	 *
	 * @return bool
	 */
	protected function save( $contents ) {
		if ( $contents === $this->wp_config_src ) {
			return false;
		}

		$result = file_put_contents( $this->wp_config_path, $contents, LOCK_EX );

		if ( false === $result ) {
			throw new Exception( 'Failed to update the wp-config.php file.' );
		}

		return true;
	}

}
