--TEST--
Array unshift test
--FILE--
<?php

/**
 * A test function
 * 
 * @engine	qb
 * @local	int32[*]		$a
 * @local	int32			$b
 * @local	float32[*][2]	$c
 * @local	int32[2]		$d;
 *
 * @return	void
 * 
 */
function test_function() {
	$a = array(1, 2, 3);
	$b = 42;
	echo array_unshift($a, 3 + 1, $b, $b + 3), "\n";
	echo "$a\n";
	
	$d = array(1, 2);
	array_unshift($c, $d, array(3.3, 4.4));
	echo "$c\n";
	array_unshift($c, array(5.55, 6.66), array(7.77, 8.88));
	echo "$c\n";
}

qb_compile();

test_function();

?>
--EXPECT--
6
[4, 42, 45, 1, 2, 3]
[[1, 2], [3.3, 4.4]]
[[5.55, 6.66], [7.77, 8.88], [1, 2], [3.3, 4.4]]
