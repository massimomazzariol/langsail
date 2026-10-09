<?php
/**
 * Loading a translation map: compiled PHP file (with and without OPcache) against a serialized file.
 * Plain PHP, no WordPress. Run twice to compare:
 *   php tests/opcache-benchmark.php <temp dir>
 *   php -d zend_extension=opcache -d opcache.enable_cli=1 tests/opcache-benchmark.php <temp dir>
 *
 * @package LangSail
 */
$dir = $argv[1];
@mkdir( $dir, 0777, true );
$rows = array();
foreach ( array( 200, 5000, 50000 ) as $n ) {
	$map = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$map[ md5( "LangSail bench $i: a sentence of ordinary length on a web page." ) ] = "Traduzione: LangSail bench $i: a sentence of ordinary length on a web page.";
	}
	$php = "$dir/dict-$n.php";
	$ser = "$dir/dict-$n.ser";
	file_put_contents( $php, '<?php return ' . var_export( $map, true ) . ';' );
	file_put_contents( $ser, serialize( $map ) );
	touch( $php, time() - 60 );
	unset( $map );
	clearstatcache();
	include $php; // First load: compile (and store in OPcache when enabled).
	$times = 20;
	$m0    = memory_get_usage();
	$t     = hrtime( true );
	for ( $i = 0; $i < $times; $i++ ) {
		$a = include $php;
	}
	$inc = ( hrtime( true ) - $t ) / 1e6 / $times;
	$im  = memory_get_usage() - $m0;
	unset( $a );
	$m0 = memory_get_usage();
	$t  = hrtime( true );
	for ( $i = 0; $i < $times; $i++ ) {
		$b = unserialize( file_get_contents( $ser ) );
	}
	$uns = ( hrtime( true ) - $t ) / 1e6 / $times;
	$um  = memory_get_usage() - $m0;
	unset( $b );
	printf( "%6d texts | include %8.3f ms (+%6d KB) | unserialize %8.3f ms (+%6d KB) | opcache %s\n", $n, $inc, $im / 1024, $uns, $um / 1024, function_exists( 'opcache_is_script_cached' ) && opcache_is_script_cached( $php ) ? 'yes' : 'no' );
}
