--TEST--
Trivial "Hello World" test
--FILE--
<?php

/**
 * A test function
 * 
 * @engine	qb
 * 
 * @return	void
 * 
 */
function test_function() {
	echo "Hello World";
}

qb_compile();

test_function();

?>
--EXPECT--
Hello World
