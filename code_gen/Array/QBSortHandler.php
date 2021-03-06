<?php

class QBSortHandler extends QBHandler {

	protected $direction = "ascending";

	public function getOperandType($i) {
		switch($i) {
			case 1: return "U32";
			case 2: return $this->operandType;
		}
	}
	
	public function getOperandAddressMode($i) {
		switch($i) {
			case 1: return "VAR";
			case 2: return "ARR";
		}
	}
	
	public function getFunctionType() {
		return "extern";
	}
	
	public function getHelperFunctions() {
		$type = $this->getOperandType(2);
		$cType = $this->getOperandCType(2);
		$dir = $this->direction;

		if($this->direction == 'ascending') {
			$a1 = "p1"; $a2 = "p2";
			$b1 = "p2"; $b2 = "p3";
		} else {
			// swap the parameters around when we want descending order
			$a1 = "p2"; $a2 = "p3";
			$b1 = "p1"; $b2 = "p2";
		}
		$functions = array(
			array(
				"int qb_compare_{$dir}_{$type}(const void *p1, const void *p2) {",
					"if(*(($cType *) $a1) < *(($cType *) $b1)) {",
						"return -1;",
					"} else if(*(($cType *) $a1) > *(($cType *) $b1)) {",
						"return 1;",
					"} else {",
						"return 0;",
					"}",
				"}",
			),
			array(
				"int qb_compare_{$dir}_{$type}_array(const void *p1, const void *p2, const void *p3) {",
					"#ifdef __linux__",
					"// the GNU version of qsort_r expects the extra parameter to come last",
					"uint32_t len = *((const uint32_t *) p3);",
					"return qb_compare_array_$type(($cType *) $a1, len, ($cType *) $b1, len);",
					"#else",
					"uint32_t len = *((const uint32_t *) p1);",
					"return qb_compare_array_$type(($cType *) $a2, len, ($cType *) $b2, len);",
					"#endif",
				"}",
			),
		);
		return $functions;
	}

	public function getActionOnUnitData() {
		$type = $this->getOperandType(2);
		$cType = $this->getOperandCType(2);
		$dir = $this->direction;
		$lines = array();
		$lines[] = "if(op1 == 1) {";
		$lines[] = 		"qsort(res_ptr, res_count, sizeof($cType) * 1, qb_compare_{$dir}_{$type});";
		$lines[] = "} else {";
		$lines[] = "#if defined(__linux__)";
		$lines[] = 		"qsort_r(res_ptr, res_count / op1, sizeof($cType) * op1, qb_compare_{$dir}_{$type}_array, &op1);";
		$lines[] = "#elif defined(_MSC_VER)";		
		$lines[] = 		"qsort_s(res_ptr, res_count / op1, sizeof($cType) * op1, qb_compare_{$dir}_{$type}_array, &op1);";
		$lines[] = "#else";
		$lines[] = 		"qsort_r(res_ptr, res_count / op1, sizeof($cType) * op1, &op1, qb_compare_{$dir}_{$type}_array);";
		$lines[] = "#endif";
		$lines[] = "}";
		return $lines;
	}
}

?>