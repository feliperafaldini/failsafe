#!/usr/bin/env bash
#
# Builds dist/failsafe.zip from src/, honouring .distignore.
#
# Usage: ./bin/build.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/src"
DIST="$ROOT/dist"
NAME="failsafe"

cd "$SRC"

if ! php -r 'exit( class_exists( "ZipArchive" ) ? 0 : 1 );'; then
	echo "ZipArchive is required to build the package." >&2
	exit 1
fi

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip"

php -r '
$root = getcwd();
$zip  = new ZipArchive();
$zip->open( $argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE );

$ignore = array_filter( array_map( "trim", file( $argv[2] ) ) );
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

	$zip->addFile( $path, $rel );
	$added++;
}

$zip->close();

printf( "%d files, %s bytes\n", $added, number_format( filesize( $argv[1] ) ) );
' "$DIST/$NAME.zip" "$ROOT/.distignore"

echo "Built $DIST/$NAME.zip"
