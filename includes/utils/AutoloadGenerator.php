<?php

/**
 * Accepts a list of files and directories to search for
 * php files and generates $wgAutoloadLocalClasses or $wgAutoloadClasses
 * lines for all detected classes. These lines are written out
 * to an autoload.php file in the projects provided basedir.
 *
 * Usage:
 *
 *     $gen = new AutoloadGenerator( __DIR__ );
 *     $gen->readDir( __DIR__ . '/includes' );
 *     $gen->readFile( __DIR__ . '/foo.php' )
 *     $gen->getAutoload();
 */
class AutoloadGenerator {
	const FILETYPE_JSON = 'json';
	const FILETYPE_PHP = 'php';

	/**
	 * @var string Root path of the project being scanned for classes
	 */
	protected $basepath;

	/**
	 * @var ClassCollector Helper class extracts class names from php files
	 */
	protected $collector;

	/**
	 * @var array Map of file shortpath to list of FQCN detected within file
	 */
	protected $classes = [];

	/**
	 * @var string The global variable to write output to
	 */
	protected $variableName = 'wgAutoloadClasses';

	/**
	 * @var array Map of FQCN to relative path(from self::$basepath)
	 */
	protected $overrides = [];

	/**
	 * @param string $basepath Root path of the project being scanned for classes
	 * @param array|string $flags
	 *
	 *  local - If this flag is set $wgAutoloadLocalClasses will be build instead
	 *          of $wgAutoloadClasses
	 */
	public function __construct( $basepath, $flags = [] ) {
		if ( !is_array( $flags ) ) {
			$flags = [ $flags ];
		}
		$this->basepath = self::normalizePathSeparator( realpath( $basepath ) );
		$this->collector = new ClassCollector;
		if ( in_array( 'local', $flags ) ) {
			$this->variableName = 'wgAutoloadLocalClasses';
		}
	}

	/**
	 * Force a class to be autoloaded from a specific path, regardless of where
	 * or if it was detected.
	 *
	 * @param string $fqcn FQCN to force the location of
	 * @param string $inputPath Full path to the file containing the class
	 * @throws Exception
	 */
	public function forceClassPath( $fqcn, $inputPath ) {
		$path = self::normalizePathSeparator( realpath( $inputPath ) );
		if ( !$path ) {
			throw new \Exception( "Invalid path: $inputPath" );
		}
		$len = strlen( $this->basepath );
		if ( substr( $path, 0, $len ) !== $this->basepath ) {
			throw new \Exception( "Path is not within basepath: $inputPath" );
		}
		$shortpath = substr( $path, $len );
		$this->overrides[$fqcn] = $shortpath;
	}

	/**
	 * @param string $inputPath Path to a php file to find classes within
	 * @throws Exception
	 */
	public function readFile( $inputPath ) {
		// NOTE: do NOT expand $inputPath using realpath(). It is perfectly
		// reasonable for LocalSettings.php and similiar files to be symlinks
		// to files that are outside of $this->basepath.
		$inputPath = self::normalizePathSeparator( $inputPath );
		$len = strlen( $this->basepath );
		if ( substr( $inputPath, 0, $len ) !== $this->basepath ) {
			throw new \Exception( "Path is not within basepath: $inputPath" );
		}
		$result = $this->collector->getClasses(
			file_get_contents( $inputPath )
		);
		if ( $result ) {
			$shortpath = substr( $inputPath, $len );
			$this->classes[$shortpath] = $result;
		}
	}

	/**
	 * @param string $dir Path to a directory to recursively search
	 *  for php files with either .php or .inc extensions
	 */
	public function readDir( $dir ) {
		$it = new RecursiveDirectoryIterator(
			self::normalizePathSeparator( realpath( $dir ) ) );
		$it = new RecursiveIteratorIterator( $it );

		foreach ( $it as $path => $file ) {
			$ext = pathinfo( $path, PATHINFO_EXTENSION );
			// some older files in mw use .inc
			if ( $ext === 'php' || $ext === 'inc' ) {
				$this->readFile( $path );
			}
		}
	}

	/**
	 * Updates the AutoloadClasses field at the given
	 * filename.
	 *
	 * @param string $filename Filename of JSON
	 *  extension/skin registration file
	 * @return string Updated Json of the file given as the $filename parameter
	 */
	protected function generateJsonAutoload( $filename ) {
		$key = 'AutoloadClasses';
		$json = FormatJson::decode( file_get_contents( $filename ), true );
		unset( $json[$key] );
		// Inverting the key-value pairs so that they become of the
		// format class-name : path when they get converted into json.
		foreach ( $this->classes as $path => $contained ) {
			foreach ( $contained as $fqcn ) {
				// Using substr to remove the leading '/'
				$json[$key][$fqcn] = substr( $path, 1 );
			}
		}
		foreach ( $this->overrides as $path => $fqcn ) {
			// Using substr to remove the leading '/'
			$json[$key][$fqcn] = substr( $path, 1 );
		}

		// Sorting the list of autoload classes.
		ksort( $json[$key] );

		// Return the whole JSON file
		return FormatJson::encode( $json, "\t", FormatJson::ALL_OK ) . "\n";
	}

	/**
	 * Generates a PHP file setting up autoload information.
	 *
	 * @param string $commandName Command name to include in comment
	 * @param string $filename of PHP file to put autoload information in.
	 * @return string
	 */
	protected function generatePHPAutoload( $commandName, $filename ) {
		// No existing JSON file found; update/generate PHP file
		$content = [];

		// We need to generate a line each rather than exporting the
		// full array so __DIR__ can be prepended to all the paths
		$format = "%s => __DIR__ . %s,";
		foreach ( $this->classes as $path => $contained ) {
			$exportedPath = var_export( $path, true );
			foreach ( $contained as $fqcn ) {
				$content[$fqcn] = sprintf(
					$format,
					var_export( $fqcn, true ),
					$exportedPath
				);
			}
		}

		foreach ( $this->overrides as $fqcn => $path ) {
			$content[$fqcn] = sprintf(
				$format,
				var_export( $fqcn, true ),
				var_export( $path, true )
			);
		}

		// sort for stable output
		ksort( $content );

		// extensions using this generator are appending to the existing
		// autoload.
		if ( $this->variableName === 'wgAutoloadClasses' ) {
			$op = '+=';
		} else {
			$op = '=';
		}

		$output = implode( "\n\t", $content );
		return
			<<<EOD
<?php
// This file is generated by $commandName, do not adjust manually
// @codingStandardsIgnoreFile
global \${$this->variableName};

\${$this->variableName} {$op} [
	{$output}
];

EOD;
	}

	/**
	 * Returns all known classes as a string, which can be used to put into a target
	 * file (e.g. extension.json, skin.json or autoload.php)
	 *
	 * @param string $commandName Value used in file comment to direct
	 *  developers towards the appropriate way to update the autoload.
	 * @return string
	 */
	public function getAutoload( $commandName = 'AutoloadGenerator' ) {
		// We need to check whether an extenson.json or skin.json exists or not, and
		// incase it doesn't, update the autoload.php file.

		$fileinfo = $this->getTargetFileinfo();

		if ( $fileinfo['type'] === self::FILETYPE_JSON ) {
			return $this->generateJsonAutoload( $fileinfo['filename'] );
		} else {
			return $this->generatePHPAutoload( $commandName, $fileinfo['filename'] );
		}
	}

	/**
	 * Returns the filename of the extension.json of skin.json, if there's any, or
	 * otherwise the path to the autoload.php file in an array as the "filename"
	 * key and with the type (AutoloadGenerator::FILETYPE_JSON or AutoloadGenerator::FILETYPE_PHP)
	 * of the file as the "type" key.
	 *
	 * @return array
	 */
	public function getTargetFileinfo() {
		$fileinfo = [
			'filename' => $this->basepath . '/autoload.php',
			'type' => self::FILETYPE_PHP
		];
		if ( file_exists( $this->basepath . '/extension.json' ) ) {
			$fileinfo = [
				'filename' => $this->basepath . '/extension.json',
				'type' => self::FILETYPE_JSON
			];
		} elseif ( file_exists( $this->basepath . '/skin.json' ) ) {
			$fileinfo = [
				'filename' => $this->basepath . '/skin.json',
				'type' => self::FILETYPE_JSON
			];
		}

		return $fileinfo;
	}

	/**
	 * Ensure that Unix-style path separators ("/") are used in the path.
	 *
	 * @param string $path
	 * @return string
	 */
	protected static function normalizePathSeparator( $path ) {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Initialize the source files and directories which are used for the MediaWiki default
	 * autoloader in {mw-base-dir}/autoload.php including:
	 *  * includes/
	 *  * languages/
	 *  * maintenance/
	 *  * mw-config/
	 *  * /*.php
	 */
	public function initMediaWikiDefault() {
		foreach ( [ 'includes', 'languages', 'maintenance', 'mw-config' ] as $dir ) {
			$this->readDir( $this->basepath . '/' . $dir );
		}
		foreach ( glob( $this->basepath . '/*.php' ) as $file ) {
			$this->readFile( $file );
		}
	}
}

/**
 * Reads PHP code and returns the FQCN of every class defined within it.
 */
class ClassCollector {

	/**
	 * @var string Current namespace
	 */
	protected $namespace = '';

	/**
	 * @var array List of FQCN detected in this pass
	 */
	protected $classes;

	/**
	 * @var array Token from token_get_all() that started an expect sequence
	 */
	protected $startToken;

	/**
	 * @var array List of tokens that are members of the current expect sequence
	 */
	protected $tokens;

	/**
	 * @var array Class alias with target/name fields
	 */
	protected $alias;

	/**
	 * @param string $code PHP code (including <?php) to detect class names from
	 * @return array List of FQCN detected within the tokens
	 */
	public function getClasses( $code ) {
		$this->namespace = '';
		$this->classes = [];
		$this->startToken = null;
		$this->alias = null;
		$this->tokens = [];

		foreach ( token_get_all( $code ) as $token ) {
			if ( $this->startToken === null ) {
				$this->tryBeginExpect( $token );
			} else {
				$this->tryEndExpect( $token );
			}
		}

		return $this->classes;
	}

	/**
	 * Determine if $token begins the next expect sequence.
	 *
	 * @param array $token
	 */
	protected function tryBeginExpect( $token ) {
		if ( is_string( $token ) ) {
			return;
		}
		// Note: When changing class name discovery logic,
		// AutoLoaderTest.php may also need to be updated.
		switch ( $token[0] ) {
			case T_NAMESPACE:
			case T_CLASS:
			case T_INTERFACE:
			case T_TRAIT:
			case T_DOUBLE_COLON:
				$this->startToken = $token;
				break;
			case T_STRING:
				if ( $token[1] === 'class_alias' ) {
					$this->startToken = $token;
					$this->alias = [];
				}
		}
	}

	/**
	 * Accepts the next token in an expect sequence
	 *
	 * @param array $token
	 */
	protected function tryEndExpect( $token ) {
		switch ( $this->startToken[0] ) {
			case T_DOUBLE_COLON:
				// Skip over T_CLASS after T_DOUBLE_COLON because this is something like
				// "self::static" which accesses the class name. It doens't define a new class.
				$this->startToken = null;
				break;
			case T_NAMESPACE:
				if ( $token === ';' || $token === '{' ) {
					$this->namespace = $this->implodeTokens() . '\\';
				} else {
					$this->tokens[] = $token;
				}
				break;

			case T_STRING:
				if ( $this->alias !== null ) {
					// Flow 1 - Two string literals:
					// - T_STRING  class_alias
					// - '('
					// - T_CONSTANT_ENCAPSED_STRING 'TargetClass'
					// - ','
					// - T_WHITESPACE
					// - T_CONSTANT_ENCAPSED_STRING 'AliasName'
					// - ')'
					// Flow 2 - Use of ::class syntax for first parameter
					// - T_STRING  class_alias
					// - '('
					// - T_STRING TargetClass
					// - T_DOUBLE_COLON ::
					// - T_CLASS class
					// - ','
					// - T_WHITESPACE
					// - T_CONSTANT_ENCAPSED_STRING 'AliasName'
					// - ')'
					if ( $token === '(' ) {
						// Start of a function call to class_alias()
						$this->alias = [ 'target' => false, 'name' => false ];
					} elseif ( $token === ',' ) {
						// Record that we're past the first parameter
						if ( $this->alias['target'] === false ) {
							$this->alias['target'] = true;
						}
					} elseif ( is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
						if ( $this->alias['target'] === true ) {
							// We already saw a first argument, this must be the second.
							// Strip quotes from the string literal.
							$this->alias['name'] = substr( $token[1], 1, -1 );
						}
					} elseif ( $token === ')' ) {
						// End of function call
						$this->classes[] = $this->alias['name'];
						$this->alias = null;
						$this->startToken = null;
					} elseif ( !is_array( $token ) || (
						$token[0] !== T_STRING &&
						$token[0] !== T_DOUBLE_COLON &&
						$token[0] !== T_CLASS &&
						$token[0] !== T_WHITESPACE
					) ) {
						// Ignore this call to class_alias() - compat/Timestamp.php
						$this->alias = null;
						$this->startToken = null;
					}
				}
				break;

			case T_CLASS:
			case T_INTERFACE:
			case T_TRAIT:
				$this->tokens[] = $token;
				if ( is_array( $token ) && $token[0] === T_STRING ) {
					$this->classes[] = $this->namespace . $this->implodeTokens();
				}
		}
	}

	/**
	 * Returns the string representation of the tokens within the
	 * current expect sequence and resets the sequence.
	 *
	 * @return string
	 */
	protected function implodeTokens() {
		$content = [];
		foreach ( $this->tokens as $token ) {
			$content[] = is_string( $token ) ? $token : $token[1];
		}

		$this->tokens = [];
		$this->startToken = null;

		return trim( implode( '', $content ), " \n\t" );
	}
}
