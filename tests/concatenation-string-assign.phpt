--TEST--
String concatenation assignment test
--FILE--
<?php

/**
 * A test function
 * 
 * @engine	qb
 * @local	string	$.*
 * 
 * @return	void
 * 
 */
function test_function() {
	$a = "hello";
	$a .= " world\n";
	echo $a;
}

qb_compile();

test_function();

?>
--EXPECT--
hello world
