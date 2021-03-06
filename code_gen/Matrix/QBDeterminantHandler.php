<?php

// not affected by matrix order since determinant of M is the same as the determinant of the transpose of M

class QBDeterminantHandler extends QBMatrixHandler {

	public function getOperandAddressMode($i) {
		switch($i) {
			case 1: return "ARR";
			case 2: return $this->addressMode;
		}
	}

	public function getResultSizePossibilities() {
		if($this->addressMode == "ARR") {
			return "matrix1_count";
		}
	}
	
	public function getResultSizeCalculation() {
		if($this->addressMode == "ARR") {
			$matrixSize = $this->getOperandSize(1);
			return "matrix1_count = op1_count / $matrixSize;";
		}
	}
	
	public function getOperandSize($i) {
		if($i == 2) {
			return 1;
		} else {
			if($this->operandSize == "variable") {
				return "(MATRIX1_ROWS * MATRIX1_COLS)";
			} else {
				return $this->operandSize * $this->operandSize;
			}
		}
	}
	
	public function getActionOnUnitData() {
		$type = $this->getOperandType(2);
		$cType = $this->getOperandCType(2);
		$lines = array();
		if($this->operandSize == "variable") {
			$determinantHandler4X = new QBDeterminantHandler(NULL, $this->operandType, "VAR", 4);
			$determinantHandler = new QBDeterminantHandler(NULL, $this->operandType, "VAR", "variable");
			$determinantFunction4X = $determinantHandler4X->getFunctionName();
			$determinantFunction = $determinantHandler->getFunctionName();
			
			$lines[] = "if(MATRIX1_ROWS == 4) {";
			$lines[] = 		"$determinantFunction4X(op1_ptr, res_ptr);";
			$lines[] = "} else {";
			$lines[] = 		"ALLOCA_FLAG(use_heap)";
			$lines[] =		"uint32_t minor_size = (MATRIX1_ROWS - 1) * (MATRIX1_COLS - 1);";
			$lines[] = 		"$cType *__restrict minor = do_alloca(minor_size * sizeof($cType), use_heap);";
			$lines[] = 		"uint32_t i, j, k, m, n;";
			$lines[] = 		"$cType sign = 1, det = 0;";
			$lines[] = 		"for(m = 0; m < MATRIX1_ROWS; m++) {";
			$lines[] = 			"$cType a = op1_ptr[m];";
			$lines[] = 			"$cType minor_det;";
			$lines[] = 			"for(i = 1, n = 0, k = MATRIX1_ROWS; i < MATRIX1_ROWS; i++) {";
			$lines[] = 				"for(j = 0; j < MATRIX1_ROWS; j++, k++) {";
			$lines[] = 					"if(j != m) {";
			$lines[] = 						"minor[n] = op1_ptr[k];";
			$lines[] = 						"n++;";
			$lines[] = 					"}";
			$lines[] = 				"}";
			$lines[] = 			"}";
			$lines[] = 			"$determinantFunction(minor, MATRIX1_ROWS - 1, MATRIX1_COLS - 1, &minor_det);";
			$lines[] = 			"det += a * minor_det * sign;";
			$lines[] = 			"sign = -sign;";
			$lines[] = 		"}";
			$lines[] =		"free_alloca(minor, use_heap);";
			$lines[] = 		"res = det;";
			$lines[] = "}";
		} else {
			switch($this->operandSize) {
				case 2: {
					$lines[] = "res = (op1_ptr[0 * 2 + 0] * op1_ptr[1 * 2 + 1]) - (op1_ptr[0 * 2 + 1] * op1_ptr[1 * 2 + 0]);";
				}	break;
				case 3: {
					$lines[] = "res =	 (op1_ptr[0 * 3 + 0] * op1_ptr[1 * 3 + 1] * op1_ptr[2 * 3 + 2]) -";
					$lines[] = 			"(op1_ptr[0 * 3 + 0] * op1_ptr[1 * 3 + 2] * op1_ptr[2 * 3 + 1]) +";
					$lines[] = 			"(op1_ptr[0 * 3 + 1] * op1_ptr[1 * 3 + 2] * op1_ptr[2 * 3 + 0]) -";
					$lines[] = 			"(op1_ptr[0 * 3 + 1] * op1_ptr[1 * 3 + 0] * op1_ptr[2 * 3 + 2]) +";
					$lines[] = 			"(op1_ptr[0 * 3 + 2] * op1_ptr[1 * 3 + 0] * op1_ptr[2 * 3 + 1]) -";
					$lines[] =			"(op1_ptr[0 * 3 + 2] * op1_ptr[1 * 3 + 1] * op1_ptr[2 * 3 + 0]);";
				}	break;
				case 4: {
					$lines[] = "res = 	 ((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 0])) - ((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 0])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 0])) + ((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 0])) +";
					$lines[] = 			"((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 0])) - ((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 0])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 1])) + ((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 1])) +";
					$lines[] = 			"((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 1])) - ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 1])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 1])) + ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 1])) +";
					$lines[] = 			"((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 2])) - ((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 2])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 3] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 2])) + ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 3]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 2])) +";
					$lines[] = 			"((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 2])) - ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 3] * op1_ptr[3 * 4 + 2])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 3])) + ((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 0] * op1_ptr[3 * 4 + 3])) +";
					$lines[] = 			"((op1_ptr[0 * 4 + 2] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 3])) - ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 2]) * (op1_ptr[2 * 4 + 1] * op1_ptr[3 * 4 + 3])) -";
					$lines[] = 			"((op1_ptr[0 * 4 + 1] * op1_ptr[1 * 4 + 0]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 3])) + ((op1_ptr[0 * 4 + 0] * op1_ptr[1 * 4 + 1]) * (op1_ptr[2 * 4 + 2] * op1_ptr[3 * 4 + 3]));";
				}	break;
			}
		}
		return $lines;
	}
}

?>