<?php
defined( 'ABSPATH' ) || exit;

/** Read files only from the plugin's fixed Batch directory. */
class SII_Batch {
	private $directory;

	public function __construct( $directory ) {
		$this->directory = rtrim( $directory, '/\\' );
	}

	public function directory() {
		return $this->directory;
	}

	public function scan() {
		if ( ! is_dir( $this->directory ) ) {
			return new WP_Error( 'batch_missing', 'The plugin Batch folder is missing or unreadable.' );
		}
		$groups  = array();
		$ignored = array();
		try {
			$iterator = new DirectoryIterator( $this->directory );
			foreach ( $iterator as $file ) {
				if ( $file->isDot() || $file->isDir() ) {
					continue;
				}
				$name = $file->getFilename();
				if ( in_array( $name, array( 'index.php', '.htaccess', 'web.config', '.gitkeep' ), true ) ) {
					continue;
				}
				if ( $file->isLink() || ! preg_match( '/^(.+)-(f|b)\.(jpe?g|png|webp|gif|avif)$/i', $name, $match ) ) {
					$ignored[] = $name;
					continue;
				}
				$sku = $match[1];
				$key = strtolower( $sku );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array( 'sku' => $sku, 'files' => array(), 'issues' => array() );
				}
				$side = strtolower( $match[2] );
				if ( isset( $groups[ $key ]['files'][ $side ] ) ) {
					$groups[ $key ]['issues'][] = 'Multiple ' . ( 'f' === $side ? 'front' : 'back' ) . ' files found; keep only one.';
				} else {
					$groups[ $key ]['files'][ $side ] = $name;
				}
			}
		} catch ( UnexpectedValueException $exception ) {
			return new WP_Error( 'batch_unreadable', 'The Batch folder could not be read.' );
		}
		uksort( $groups, 'strnatcasecmp' );
		natcasesort( $ignored );
		return array( 'groups' => $groups, 'ignored' => array_values( $ignored ) );
	}

	public function path( $name ) {
		if ( ! is_string( $name ) || basename( $name ) !== $name || false !== strpos( $name, '\\' ) ) {
			return new WP_Error( 'unsafe_file', 'Invalid Batch filename.' );
		}
		$root = realpath( $this->directory );
		$file = $this->directory . DIRECTORY_SEPARATOR . $name;
		if ( ! $root || is_link( $file ) || ! is_file( $file ) || realpath( dirname( $file ) ) !== $root ) {
			return new WP_Error( 'missing_file', 'A Batch image is missing or unreadable: ' . $name );
		}
		// Opening the file also works on Windows ACL setups where is_readable() is inaccurate.
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'unreadable_file', 'A Batch image could not be opened: ' . $name );
		}
		fclose( $handle );
		return $file;
	}

	/** Content hashes make a preview invalid if either source image changes. */
	public function fingerprint( $group ) {
		$hashes = array();
		foreach ( array( 'f', 'b' ) as $side ) {
			if ( empty( $group['files'][ $side ] ) ) {
				return new WP_Error( 'missing_pair', 'Both -f and -b images are required.' );
			}
			$path = $this->path( $group['files'][ $side ] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				return new WP_Error( 'hash_failed', 'Could not read a Batch image.' );
			}
			$hashes[] = $group['files'][ $side ] . ':' . $hash;
		}
		return hash( 'sha256', implode( '|', $hashes ) );
	}
}
