<?php

class QBPrintStringHandler extends QBHandler {

	public function getOperandAddressMode($i) {
		return "ARR";
	}

	public function getAction() {
		return "php_write(op1_ptr, op1_count TSRMLS_CC);";
	}
}

?>