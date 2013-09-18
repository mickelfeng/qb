<?php

class SetAny extends Handler {

	use ArrayAddressMode, UnaryOperator, BooleanResult;
	
	protected function getActionOnUnitData() {
		$cType = $this->getOperandCType(1);
		$lines[] = array();
		$lines[] = "$cType *op1_end = op1_ptr + op1_count;";
		$lines[] = "res = 0;";
		$lines[] = "while(op1_ptr != op1_end) {";
		$lines[] = 		"if(op1) {";
		$lines[] =			"res = 1;";
		$lines[] =			"break;";
		$lines[] = 		"}";
		$lines[] =		"op1_ptr++;";
		$lines[] = "}";
		return $lines;
	}
}

?>