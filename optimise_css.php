<?php

//Load in the files for HtmlDocument and Minify
require 'vendor/autoload.php';

//The simpledom client
use simplehtmldom\HtmlDocument;

//The minifier
use MatthiasMullie\Minify;

/**
 * Class to optimise the files cached by WP Rocket
 *
 *
 */

class LCOptimise {

	/**
	 * Hostname of the domain
	 * that the script is being run
	 * on. This should match any CDN
	 *
	 * @var string
	 */
	private $host;

	/**
	 * The full hostname of the domain
	 * that the script is being run on, including subdomain
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * Path with mounted files
	 * this will be /mnt/cache if setup
	 * as per instructions in the readme
	 *
	 * @var string
	 */
	private $mount_path;


	/**
	 * Path to the rocket cache directory
	 * from the document root
	 *   *
	 * @var string
	 */
	private $rocket_cache_path;


	/**
	 * Path to the WordPress directory
	 * from the document root
	 *   *
	 * @var string
	 */
	private $wordpress_path;

	/**
	 * File to log status updates to
	 *
	 * @var string
	 */
	private $status_file;

	/**
	 * A lockfile used to ensure this script is not
	 * run simulateously by multiple processes
	 *
	 * @var string
	 */
	private $lockfile;

	/**
	 * The prefix used for the tmpfiles
	 * allows easily deleting them after
	 * script is run
	 *
	 * @var string
	 */
	private $temp_prefix;

	/**
	* Initialise the class
	*
	*/
	public function init() {

		//Get the configs
		$dotenv = Dotenv\Dotenv::createImmutable( __DIR__ );
		$dotenv->load();

		//Set variables
		$this->host              = $_ENV['host'];
		$this->domain            = $_ENV['domain'];
		$this->mount_path        = $_ENV['mount_path'];
		$this->rocket_cache_path = $this->mount_path . $_ENV['rocket_cache_path'];
		$this->wordpress_path    = $this->mount_path . $_ENV['wordpress_path'];
		$this->status_file       = $this->rocket_cache_path . 'statusfile.txt';
		$this->lockfile          = '/home/pptruser/lockfile.lock';
		$this->temp_prefix       = 'TMPLC';

		// Lock the file.
		$fp = fopen( $this->lockfile, 'w+' );
		if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) { // do an exclusive lock.
			die();
		}

		// Write time to file to help with debugging if required.
		ftruncate( $fp, 0 );    // Truncate the file to 0.
		rewind( $fp );           // Set write pointer to beginning of file.
		fwrite( $fp, strtotime( 'now' ) );    // Write the new Hit Count.

		//If logging isn't enabled, update the logfile write time.
		//This allows us to track last run
		if ( $_ENV['write_log'] == false || $_ENV['write_log'] == 'false' ) {
			$fp = fopen( $this->status_file, 'w+' );
			ftruncate( $fp, 0 );    // Truncate the file to 0.
			rewind( $fp );           // Set write pointer to beginning of file.
			fwrite( $fp, strtotime( 'now' ) );    // Write the new Hit Count.
		}

		//Get files cached by WP Rocket
		$files = $this->get_files( $this->rocket_cache_path );

		//Process files
		$this->process_files( $files );

	}

	/**
	* Process the file list
	*
	* @param array $paths_to_check An array of absolute paths to WP Rocket html files
	*/
	private function process_files( $paths_to_check ) {

		//Set strings to detect actions by
		$footer_comment = $_ENV['footer_comment'];

		$source_html_array = array();
		$check_count       = 0;

		foreach ( (array) $paths_to_check as $html_file ) {

			//Run through and optimise CSS
			$check_count++;
			$this->log( ' Checking ' . $check_count . '/' . count( $paths_to_check ) . ' ' . $html_file );

			//Get file contents
			$file_contents = @file_get_contents( $html_file );

			//Is 404 skip
			if ( strstr( $file_contents, 'Error 404' ) ) {
				continue;
			}

			//Html error, skip
			if ( ! $this->html_string_passes_checks( $file_contents ) ) {
				$this->log( ' FILE ERROR 1' . substr( trim( $file_contents ), 0, 14 ) );
				continue;
			}

			// Check if already inlined, add is-cached comments
			if ( strstr( $file_contents, $footer_comment ) ) {

				$mark_as_cached = $this->mark_links_as_cached( $file_contents, $html_file );
				continue; //already inlined, skip
			}

			//Preserve <noscript> inline styles
			$file_contents = str_replace( '<noscript><style', '<noscript><style rel="noscript"', $file_contents );

			//Get CSS files for each cache file path
			$css_array = $this->get_css_paths( $file_contents );

			//No css found
			if ( count( $css_array ) == 0 ) {
				continue;
			}

			//Log activity
			$this->log( 'for ' . $html_file . ' check ' . print_r( $css_array, true ) );

			//Get original CSS length for comparison purposes
			//And all CSS together
			$original_css_length = 0;
			$original_css        = '';
			foreach ( $css_array as $css_file ) {
				$this->log( 'File is ' . basename( $css_file ) );
				$original_css .= file_get_contents( $css_file );
			}
			$original_css_length = strlen( $original_css );
			$this->log( 'Length is ' . $original_css_length );

			// Get rendered HTML and write to temp file.
			$rendered_html = $this->get_rendered_html( $html_file );// get rendered HTML via chrome headless.
			$this->log( 'Got html length ' . strlen( $rendered_html ) );

			//Didn't get any rendered HTML
			if ( ! $rendered_html ) {
				continue;
			}

			//Get the CSS from the page-local.js script that defines how big the primary divs are
			//This allow us to tell the browser to stop rendering them
			$extra_inline_css = '';
			preg_match( '@<style data-intrinsic-lc[^>]+>(.*?)</style>@', $rendered_html, $matches );
			if ( isset( $matches[1] ) ) {
				$extra_inline_css = $matches[1];
			}
			$this->log( 'Extra inline CSS length ' . strlen( $extra_inline_css ) );

			//Create temp file with rendered and non-rendered HTML
			$rendered_html_out = $rendered_html . $file_contents;
			$tmp_html_file     = tempnam( sys_get_temp_dir(), $this->temp_prefix );
			file_put_contents( $tmp_html_file, $rendered_html_out );

			//Create temp file with CSS
			$tmp_css_file = tempnam( sys_get_temp_dir(), $this->temp_prefix );
			file_put_contents( $tmp_css_file, $original_css );

			// Get purged CSS.
			$out     = '';
			$estring = "purgecss --css '" . $tmp_css_file . "' --content '" . $tmp_html_file . "'";
			$this->log( $estring );
			exec( $estring, $out );

			$this->log( 'Got output with ' . count( $out ) . ' lines' );

			// Get combined CSS output
			$combined_css  = '';
			$replace_check = array();
			$files_array   = array();
			foreach ( (array) $out as $json ) {
				$css_out = json_decode( $json );
				if ( isset( $css_out[0]->css ) && isset( $css_out[0]->file ) ) {
					$css_file      = $css_out[0]->file;
					$combined_css .= $css_out[0]->css;
				}
			}

			//General operations on page text
			$newHtml = $this->str_get_html( $file_contents );

			//Remove original CSS
			foreach ( $newHtml->find( "link[rel='stylesheet']" ) as $e ) {

				$rel = $e->getAttribute( 'rel' );
				if ( $rel == 'stylesheet' ) {
					$e->remove();
				}
			}

			// Replace links
			foreach ( $newHtml->find( 'style' ) as $inline_style ) {

				$rel = $inline_style->getAttribute( 'rel' );
				if ( $rel == 'noscript' ) {
					continue;
				}
				$inline_style->innertext = '';

			}

			// Tag links if a cached version is found on the server
			foreach ( $newHtml->find( 'a' ) as $link ) {

				$href      = $link->getAttribute( 'href' );
				$is_cached = $link->getAttribute( 'data-is-rocket-cached' );
				if ( ! $is_cached ) {
					if ( $this->is_rocket_cached( $href ) ) {
						$link->setAttribute( 'data-is-rocket-cached', true );
						$this->log( 'Marked as cached ' . $href );
					}
				}
			}

			//Lazyload the lazyload!
			$lazyload_script_source = '';
			foreach ( $newHtml->find( 'script' ) as $script ) {

				$src  = $script->getAttribute( 'src' );
				$type = $script->getAttribute( 'type' );

				//all js with src is deferred and lazyloaded
				if ( $src ) {

					if ( ! strstr( $src, 'jquery.min.js' ) ) {
						$script->setAttribute( 'defer', 'defer' );
						$script->setAttribute( 'type', 'rocketlazyloadscript' );
						$script->removeAttribute( 'src' );
						$script->removeAttribute( 'async' );
						$script->setAttribute( 'data-rocket-src', $src );
					}

					//Lazyload moved to top
					if ( strstr( $src, 'lazyload.min.js' ) ) {
						$lazyload_script_source = $script->outertext;
						$script->remove();
					}
				} elseif ( $type == 'rocketlazyloadscript' && strstr( $script->innertext, 'jQuery' ) && ! strstr( $script->innertext, 'RocketLazyLoadSScripts' ) ) {

					//inline deferred too if jQuery!
					$script->setAttribute( 'defer', 'defer' );
					$script->removeAttribute( 'async' );
					$script->setAttribute( 'data-rocket-src', 'data:text/javascript;base64,' . base64_encode( $script->innertext ) );
					$script->innertext = '';

				}
			}

			$file_contents = $newHtml;

			//Replace the default preload SVG
			if ( $_ENV['img_dataimg'] ) {

				foreach ( $newHtml->find( 'img' ) as $img ) {

					if ( strstr( $img->src, 'data:image/svg' ) ) {
						$img->src = $_ENV['img_dataimg'];
					}
				}
			}

			if ( $_ENV['logo_dark_selector'] ) {

				foreach ( $newHtml->find( $_ENV['logo_dark_selector'] ) as $img ) {

					$img->src = $_ENV['logo_dark_dataimg'];

				}
			}

			if ( $_ENV['logo_light_selector'] ) {

				foreach ( $newHtml->find( $_ENV['logo_light_selector'] ) as $img ) {

					$img->src = $_ENV['logo_light_dataimg'];

				}
			}

			//Double check on CSS replace, this sometimes fails (why?)
			foreach ( (array) $replace_check as $css_href ) {

				$this->log( 'CSS file replace check ' . $css_href );
				$file_contents = str_replace( $css_href, '', $file_contents );

			}

			// Check file contents.
			if ( false === $this->html_string_passes_checks( $file_contents ) ) {

				$this->log( 'Problem with file contents. STOP.' );
				$this->log( ' File contents issue ' . substr( $file_contents, 0, 15 ) );
				continue;

			}

			//Add in extra CSS from renderer
			$combined_css .= $extra_inline_css;

			// Hardcode addition of any CSS that we have manually found to be missing.
			$missing_css   = '/home/pptruser/forcecss.css';
			$combined_css .= file_get_contents( $missing_css );

			// Un-important comments to get them removed too
			$combined_css = str_replace( '/*!', '/*', $combined_css );

			$this->log( 'Combined CSS length ' . strlen( $combined_css ) );

			// Minify using the matthiasmullie minifier.
			$min              = new Minify\CSS( $combined_css );
			$minified_content = $min->minify();

			$this->log( 'New CSS length is ' . strlen( $minified_content ) . ' vs ' . $original_css_length . ' ' . number_format( ( 100 - ( strlen( $minified_content ) / $original_css_length ) * 100 ), 2 ) . '%' );

			// Replace style.
			$file_contents = str_replace( '</title>', '</title>' . "\n" . '<style>' . $minified_content . '</style>' . "\n", $file_contents );

			//Replace lazyload script source to top
			if ( $lazyload_script_source ) {
				$file_contents = str_replace( '</title>', '</title>' . $lazyload_script_source, $file_contents );
			}

			//Extract fonts info
			preg_match( '@<!-- FONTS(.*?)-->@', $rendered_html, $matches );

			if ( ! empty( $matches[0] ) ) {

				//Remove
				$rendered_html = str_replace( $matches[0], '', $rendered_html );

				//Preload
				$preloaders  = array();
				$fonts_array = json_decode( $matches[1] );
				$this->log( ' Font array is  ' . print_r( $fonts_array, true ) );
				foreach ( $fonts_array as $font ) {

					$font = preg_replace( '@\?[^$]+$@', '', $font );
					preg_match( '@(woff2|woff|ttf|otf)@', $font, $matches );
					$type                  = $matches[1];
					$font                  = str_replace( '.' . $type, '', $font );
					$preloaders[ $font ][] = $type;

				}

				$this->log( ' Preloaders are  ' . print_r( $preloaders, true ) );

				if ( count( $preloaders ) > 0 ) {

					$to_preload = array();

					foreach ( $preloaders as $file => $types ) {

						$chosen_type = '';
						foreach ( array( 'woff2', 'woff', 'otf', 'ttf' ) as $test_type ) {
							if ( in_array( $test_type, $types ) ) {
								$chosen_type = $test_type;
								break;
							}
						}

						$to_preload[] = $file . '.' . $chosen_type;

					}
				}

				if ( count( $to_preload ) > 0 ) {

					$preload_string = '';

					foreach ( $to_preload as $file ) {

						$crossorigin = 'crossorigin';

						$preload_string .= '<link rel="preload" href="' . $file . '" as="font" ' . $crossorigin . '>';

					}

					$file_contents = str_replace( '</title>', '</title>' . $preload_string, $file_contents );
				}

				$this->log( ' To preload is  ' . print_r( $to_preload, true ) );

			}

			//Extract fonts info
			preg_match( '@<!-- GOOGLEFONTS(.*?)-->@', $rendered_html, $matches );

			if ( ! empty( $matches[0] ) ) {

				//Remove
				$rendered_html = str_replace( $matches[0], '', $rendered_html );

				//Preload
				$fonts_hash = json_decode( $matches[1] );

				foreach ( $fonts_hash as $source => $expanded ) {

						$min              = new Minify\CSS( base64_decode( $expanded ) );
						$minified_fontcss = $min->minify();

						//$this->log( 'Replace ' . $source );
						$file_contents = str_replace( '@import url(' . $source . ');', $minified_fontcss, $file_contents );

				}
			}

			//Additional optimisation removals
			$remove = array( "background: url( '' )", "url( '' )" );
			foreach ( $remove as $string ) {
				$file_contents = str_replace( $string, '', $file_contents );
			}

			//Trigger preload after a few seconds
			if ( $_ENV['preload_delay'] >= 0 ) {
				$file_contents = str_replace( 'e._addUserInteractionListener(e)', 'e._addUserInteractionListener(e);setTimeout(function() {  if(typeof(document.onreadystatechange)!="function") { e._loadEverythingNow(); e._removeUserInteractionListener();} },' . $_ENV['preload_delay'] . ');', $file_contents );
			}

			//Add footprint
			$footprint     = '<!-- ' . $footer_comment . ' @' . time() . '-->';
			$file_contents = $file_contents . $footprint;

			// Save the cache file.
			if ( true == $this->html_string_passes_checks( $file_contents ) ) {

				$this->put_contents( $html_file, $file_contents );

				// Save gzip variant.
				if ( function_exists( 'gzencode' ) ) {
					$this->put_contents( $html_file . '_gzip', gzencode( $file_contents, 3 ) );
				}

				$this->log( ' Done ' . $check_count . '/' . count( $paths_to_check ) . ' files for optimisation' );

			} else {

				$this->log( $status_file, date( 'Y-m-d H:i:s' ) . ' FAIL ' . $check_count . '/' . count( $paths_to_check ) . ' with contents ' . $file_contents );

			}

			//Remove the tmp files
			unlink( $tmp_html_file );
			unlink( $tmp_css_file );
			$estring = 'find /tmp -name "' . $this->temp_prefix . '*" -exec rm -rf {} +';
			exec( $estring );

		}

	}

	/**
	* Examine HTML and return CSS paths
	*
	* @param string $html_file_contents The raw HTML
	*
	* @return array The array of paths to be checked
	*/
	private function get_css_paths( $html_file_contents ) {

		//Empty return array
		$paths_to_check = array();

		//Get CSS files from the source
		$html = $this->str_get_html( $html_file_contents );

		//Convert inline to stylesheets
		foreach ( $html->find( 'style' ) as $e ) {

			$rel = $e->getAttribute( 'rel' );
			if ( $rel == 'noscript' ) {
				continue;
			}

			$style = $e->innertext;

			$tmp_css_file = tempnam( sys_get_temp_dir(), $this->temp_prefix );
			file_put_contents( $tmp_css_file, $style );

			$this->log( 'Created tmp sheet of ' . $tmp_css_file );

			$link       = $html->createElement( 'link' );
			$link->href = $tmp_css_file;
			$link->setAttribute( 'rel', 'stylesheet' );

			$e->appendChild( $link );

		}

		//Get stylesheets
		foreach ( $html->find( "link[rel='stylesheet']" ) as $e ) {

			$this->log( $e->href );

			//Find file location
			$href = $e->href;
			$href = preg_replace( '@https:\/\/' . str_replace( '.', '\.', $_ENV['domain'] ) . '@', '', $href );
			$href = preg_replace( '@\?[^$]+$@', '', $href );

			$this->log( 'Check exists at ' . $this->wordpress_path . $href . ' and ' . $href );

			//Confirm file exists
			$file_path = '';

			$file_exists = file_exists( $this->wordpress_path . $href );
			if ( $file_exists ) {

				$file_path = $this->wordpress_path . $href;

			} else {

				$file_exists = file_exists( $href ); //TMP files will exist directly
				$file_path   = $href;

			}

			if ( $file_path ) {

				//Add to checking array
				$paths_to_check[] = $file_path;
				$e->setAttribute( 'rel', 'to_compress' );

			}
		}

		return $paths_to_check;

	}

	/**
	* Checks a string of RAW html and sees if the local
	* links in that string have been cached by WP Rocket
	* If so, it marks them as cached.
	*
	* This is for use by a pre-loader so that it only
	* preloads cached pages
	*
	* @param string $file_contents The RAW HTML string
	* @param string $html_file The absolute file path to the file being checked
	*
	* @return bool If links were updated or not
	*/
	private function mark_links_as_cached( $file_contents, $html_file ) {

		// Remark cached links
		$newHtml = $this->str_get_html( $file_contents );

		// Not HTML, stop process
		if ( ! is_object( $newHtml ) ) {
			$file_contents = '';
			return false;
		}

		// Tag links if a cached version is found on the server
		$updated_links = false;
		foreach ( $newHtml->find( 'a' ) as $link ) {

			$href      = $link->getAttribute( 'href' );
			$is_cached = $link->getAttribute( 'data-is-rocket-cached' );
			if ( ! $is_cached ) {
				if ( $this->is_rocket_cached( $href ) ) {
					$link->setAttribute( 'data-is-rocket-cached', true );
					$updated_links = true;
					$this->log( 'Marked as cached ' . $href );
				}
			}
		}

		if ( $updated_links === true ) {
			$file_contents = $newHtml;
			if ( true == $this->html_string_passes_checks( $file_contents ) ) {
				$this->put_contents( $html_file, $file_contents );
				//$this->log( 'Updated inlined' . $html_file );
			}
		} else {
			$this->log( 'Already inlined ' . $html_file );
			return false;
		}

		return true;

	}

	/**
	 * Use pupeteer to get rendered HTML
	 * this is for the unused CSS stripper so rendered elements are taken into account
	 *
	 * @param string $url The URL to get the rendered HTML for.
	 *
	 * @return string The rendered HTML.
	 */
	private function get_rendered_html( $url ) {

		try {
			$estring = 'timeout 30 node /home/pptruser/page-local.js "file://' . $url . '" ' . $this->host;
			exec( $estring, $out );
		} catch ( Exception $e ) {
			$this->log( 'Errror getting HTML ' . $e->getMessage() );
			return false;
		}

		return @implode( '', $out );

	}

	/**
	* Get a list of files to process
	*
	* @param string $file_path The absolute path to the WP Rocket directory
	*
	* @return array The array of paths to the cached files to be processed
	*/
	private function get_files( $file_path ) {

		$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $file_path ) );

		//Create array of pathnames
		$html = array();
		foreach ( $rii as $html_file ) {

			//No directories
			if ( $html_file->isDir() ) {
				continue;
			}

			$path_name = $html_file->getPathname();

			//Only files on this domain
			if ( ! strstr( $path_name, $this->domain . '/' ) || strstr( $path_name, '_gzip' ) || strstr( $path_name, '.' . $this->domain ) ) {
				continue;
			}

			//Check for zero-byte files and remove (safety check)
			$size = filesize( $path_name );
			if ( 0 === $size ) {
				$this->log( ' Zero byte found ' . ( $html_file ) );
				@unlink( $path_name );
				@unlink( $path_name . '_gzip' );
			}

			$html[] = $path_name;

		}

		//Sort by file length, most important files get processed first
		usort(
			$html,
			function( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);

		//Log amount of files
		$this->log( ' Got ' . count( $html ) . " files to optimise\n" );

		return $html;

	}

	/**
	 * From: https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-includes/functions.php
	 * Set the mbstring internal encoding to a binary safe encoding when func_overload
	 * is enabled.
	 *
	 * When mbstring.func_overload is in use for multi-byte encodings, the results from
	 * strlen() and similar functions respect the utf8 characters, causing binary data
	 * to return incorrect lengths.
	 *
	 * This function overrides the mbstring encoding to a binary-safe encoding, and
	 * resets it to the users expected encoding afterwards through the
	 * `reset_mbstring_encoding` function.
	 *
	 * It is safe to recursively call this function, however each
	 * `mbstring_binary_safe_encoding()` call must be followed up with an equal number
	 * of `reset_mbstring_encoding()` calls.
	 *
	 * @since 3.7.0
	 *
	 * @see reset_mbstring_encoding()
	 *
	 * @param bool $reset Optional. Whether to reset the encoding back to a previously-set encoding.
	 *                    Default false.
	 */
	function mbstring_binary_safe_encoding( $reset = false ) {
		static $encodings  = array();
		static $overloaded = null;

		if ( is_null( $overloaded ) ) {
			if ( function_exists( 'mb_internal_encoding' )
				&& ( (int) ini_get( 'mbstring.func_overload' ) & 2 ) // phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.mbstring_func_overloadDeprecated
			) {
				$overloaded = true;
			} else {
				$overloaded = false;
			}
		}

		if ( false === $overloaded ) {
			return;
		}

		if ( ! $reset ) {
			$encoding = mb_internal_encoding();
			array_push( $encodings, $encoding );
			mb_internal_encoding( 'ISO-8859-1' );
		}

		if ( $reset && $encodings ) {
			$encoding = array_pop( $encodings );
			mb_internal_encoding( $encoding );
		}
	}

	/**
	 * Reset the mbstring internal encoding to a users previously set encoding.
	 * From https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-includes/functions.php
	 *
	 * @see mbstring_binary_safe_encoding()
	 *
	 * @since 3.7.0
	 */
	function reset_mbstring_encoding() {
		$this->mbstring_binary_safe_encoding( true );
	}

	/**
	 * Writes a string to a file.
	 * From https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-admin/includes/class-wp-filesystem-direct.php
	 *
	 * @since WordPress 2.5.0
	 *
	 * @param string    $file     Remote path to the file where to write the data.
	 * @param string    $contents The data to write.
	 * @param int|false $mode     Optional. The file permissions as octal number, usually 0644.
	 *                            Default false.
	 * @return bool True on success, false on failure.
	 */
	public function put_contents( $file, $contents, $mode = false ) {
		$fp = @fopen( $file, 'wb' );

		if ( ! $fp ) {
			return false;
		}

		$this->mbstring_binary_safe_encoding();

		$data_length = strlen( $contents );

		$bytes_written = fwrite( $fp, $contents );

		$this->reset_mbstring_encoding();

		fclose( $fp );

		if ( $data_length !== $bytes_written ) {
			return false;
		}

		$this->chmod( $file, $mode );

		return true;
	}


	/**
	 * Changes filesystem permissions.
	 * From: https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-admin/includes/class-wp-filesystem-direct.php
	 * Amended so works for single files only
	 *
	 * @since 2.5.0
	 *
	 * @param string    $file      Path to the file.
	 * @param int|false $mode      Optional. The permissions as octal number, usually 0644 for files,
	 *                             0755 for directories. Default false.
	 * @param bool      $recursive Optional. If set to true, changes file permissions recursively.
	 *                             Default false.
	 * @return bool True on success, false on failure.
	 */
	public function chmod( $file, $mode = false, $recursive = false ) {

		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( 0755 & ~ umask() ) );
			define( 'FS_CHMOD_FILE', ( 0644 & ~ umask() ) );
		}

		if ( ! $mode ) {
			if ( @is_file( $file ) ) {
				$mode = FS_CHMOD_FILE;
			} elseif ( @is_dir( $file ) ) {
				$mode = FS_CHMOD_DIR;
			} else {
				return false;
			}
		}

		if ( ! $recursive || ! @is_dir( $file ) ) {
			return chmod( $file, $mode );
		}

	}


	/**
	 * Check that a string should be written as cache contents
	 *
	 * @param string $file_contents The string to check
	 *
	 * @return boolean Whether or not the string passes the tests
	 */
	function html_string_passes_checks( $file_contents ) {

		if ( strlen( $file_contents ) < 10000
		|| ! strstr( $file_contents, '<body' )
		|| ! strstr( $file_contents, '<style' )
		|| ! strstr( $file_contents, '</body>' )
		|| substr( trim( $file_contents ), 0, 14 ) != '<!DOCTYPE html'
		) {
			return false;
		}

		return true;

	}

	/**
	 * Check a local URL to see if WP Rocket has it in the cache
	 *
	 * @param string $url The URL to get the rendered HTML for.
	 *
	 * @return boolean Whether in cache or not
	 */

	function is_rocket_cached( $url ) {

		$path = $this->rocket_cache_path;
		$url  = preg_replace( '@https?:\/\/@', '', $url );
		$url  = preg_replace( '@\?[^$]+$@', '', $url );

		$path  = $path . '/' . $url;
		$path .= 'index';
		$path .= '-https';
		$path .= '.html';

		if ( file_exists( $path ) ) {
			$this->log( 'Found path exists for ' . $path );
			return true;
		} else {
			return false;
		}

	}

	/**
	* Function to get the nodes in the DOMDocument
	* See: https://github.com/axllent/simplehtmldom/blob/master/src/simple_html_dom.php
	*/
	function str_get_html(
	$str,
	$lowercase = true,
	$forceTagsClosed = true,
	$target_charset = 'UTF-8',
	$stripRN = true,
	$defaultBRText = "\r\n",
	$defaultSpanText = ' ' ) {
		$dom = new HtmlDocument(
			null,
			$lowercase,
			$forceTagsClosed,
			$target_charset,
			$stripRN,
			$defaultBRText,
			$defaultSpanText
		);

		if ( empty( $str ) || strlen( $str ) > 6000000 ) {
				$dom->clear();
				return false;
		}

		return $dom->load( $str, $lowercase, $stripRN );
	}

	/**
	* Write the log file
	*
	* @param string $msg The message to write to log
	*/
	private function log( $msg ) {

		if ( $_ENV['write_log'] != false && $_ENV['write_log'] != 'false' ) {
			file_put_contents( $this->status_file, date( 'Y-m-d H:i:s' ) . $msg . "\n", FILE_APPEND );
		}

	}

}

//Start the class and let's go!
$opt = new LCOptimise();
$opt->init();
