#!/usr/bin/env bash
#
# Builds the release archives from src/, honouring .distignore.
#
#   dist/failsafe.zip          files at the root — for SVN trunk/
#   dist/failsafe-<version>.zip  wrapped in failsafe/ — for "Upload Plugin"
#
# Usage: ./bin/build.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/src"
DIST="$ROOT/dist"
NAME="failsafe"
VERSION="$(php -r '$s = file_get_contents( $argv[1] ); preg_match( "/Version:\\s*([0-9.]+)/", $s, $m ); echo $m[1] ?? "0.0.0";' "$SRC/failsafe.php")"

cd "$SRC"

if ! php -r 'exit( class_exists( "ZipArchive" ) ? 0 : 1 );'; then
	echo "ZipArchive is required to build the package." >&2
	exit 1
fi

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip" "$DIST/$NAME-$VERSION.zip"

build_zip() {
	php -r '
	$root    = getcwd();
	$prefix  = $argv[2];
	$zip     = new ZipArchive();
	$zip->open( $argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE );

	$ignore = array_filter( array_map( "trim", file( $argv[3] ) ) );
	$ignore = array_values( array_filter( $ignore, fn( $line ) => "" !== $line && "#" !== $line[0] ) );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	$added = 0;

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();
		$rel  = substr( $path, strlen( $root ) + 1 );

		foreach ( $ignore as $pattern ) {
			if ( fnmatch( $pattern, $rel ) || fnmatch( $pattern, basename( $rel ) ) ) {
				continue 2;
			}
		}

		$zip->addFile( $path, $prefix . $rel );
		$added++;
	}

	$zip->close();

	printf( "%d files, %s bytes\n", $added, number_format( filesize( $argv[1] ) ) );
	' "$@"
}

build_zip "$DIST/$NAME.zip" "" "$ROOT/.distignore"
echo "Built $DIST/$NAME.zip (plugin root layout, for trunk/)"

build_zip "$DIST/$NAME-$VERSION.zip" "$NAME/" "$ROOT/.distignore"
echo "Built $DIST/$NAME-$VERSION.zip (wrapped in $NAME/, for Upload Plugin)"
