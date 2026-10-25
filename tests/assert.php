<?php
/**
 * Minimal assertion helpers for the Failsafe test scripts.
 *
 * @package Failsafe
 */

$GLOBALS['failsafe_failures'] = 0;
$GLOBALS['failsafe_checks']   = 0;

/**
 * Records a boolean assertion.
 *
 * @param bool   $condition Result to check.
 * @param string $message   Description shown on failure.
 * @return void
 */
function failsafe_assert( $condition, $message ) {
	$GLOBALS['failsafe_checks']++;

	if ( $condition ) {
		echo "  ok   {$message}\n";
		return;
	}

	$GLOBALS['failsafe_failures']++;
	echo "  FAIL {$message}\n";
}

/**
 * Prints the summary and exits with a non-zero status when something failed.
 *
 * @return void
 */
function failsafe_report() {
	$checks   = $GLOBALS['failsafe_checks'];
	$failures = $GLOBALS['failsafe_failures'];

	printf( "\n%d checks, %d failures\n", $checks, $failures );
	exit( $failures > 0 ? 1 : 0 );
}
