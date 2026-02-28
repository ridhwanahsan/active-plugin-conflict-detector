<?php
/**
 * Logger utilities for reading and parsing debug.log
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APCD_Logger {
	/**
	 * Get debug.log full path if it exists.
	 *
	 * @return string|null
	 */
	public function get_debug_log_path() {
		$path = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $path ) && is_readable( $path ) ) {
			return $path;
		}
		return null;
	}

	/**
	 * Tail last N lines from a file.
	 *
	 * @param string $file File path.
	 * @param int    $lines Number of lines.
	 * @return string[] Lines from end (oldest to newest among tailed set).
	 */
	public function tail( $file, $lines = 100 ) {
		$result = array();
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return $result;
		}

		$f       = fopen( $file, 'rb' );
		$buffer  = '';
		$chunk   = 4096;
		$pos     = -1;
		$linecnt = 0;
		fseek( $f, 0, SEEK_END );
		$filesize = ftell( $f );

		while ( $linecnt <= $lines && -$pos < $filesize ) {
			$pos -= $chunk;
			if ( -$pos > $filesize ) {
				$pos = -$filesize;
			}
			fseek( $f, $pos, SEEK_END );
			$buffer = fread( $f, min( $chunk, $filesize ) ) . $buffer;
			$linecnt = substr_count( $buffer, "\n" );
		}
		fclose( $f );

		$rows = explode( "\n", trim( $buffer ) );
		if ( count( $rows ) > $lines ) {
			$rows = array_slice( $rows, -$lines );
		}
		return $rows;
	}

	/**
	 * Extract fatal errors from lines.
	 *
	 * @param string[] $lines Log lines.
	 * @return array<int, array{message:string, plugin:string|null, line:string}>
	 */
	public function extract_fatal_errors( $lines ) {
		$errors = array();
		foreach ( $lines as $line ) {
			if ( stripos( $line, 'PHP Fatal error' ) !== false || stripos( $line, 'Uncaught Error' ) !== false ) {
				$plugin = null;
				if ( preg_match( '#wp-content[\\/\\\\]plugins[\\/\\\\]([^\\/\\\\]+)#i', $line, $m ) ) {
					$plugin = isset( $m[1] ) ? $m[1] : null;
				}
				$errors[] = array(
					'message' => $line,
					'plugin'  => $plugin,
					'line'    => $line,
				);
			}
		}
		return $errors;
	}
}

