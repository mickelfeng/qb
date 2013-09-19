<?php

class Handler {
	protected static $typeDecls = array();
	protected static $compiler;

	protected $baseName;
	protected $operandType;
	protected $operandSize = 1;
	protected $addressMode;
	protected $multipleData = false;
	protected $unrolling = false;
	
	static public function getTypeDeclarations() {
		ksort(self::$typeDecls);
		return self::$typeDecls;
	}
	
	static public function setCompiler($compiler) {
		self::$compiler = $compiler;
	}
	
	static public function compare($op1, $op2) {
		if($op1 && $op2) {
			if($op1 == $op2) {
				return true;
			} else {
				if($op1->baseName == $op2->baseName && $op1->operandType == $op2->operandType && $op1->operandSize == $op2->operandSize && $op1->operandSize == $op2->operandSize) {
					return "variant";
				}
			}
		}
		return false;
	}
	
	public function getBaseName() {
		return $this->baseName;
	}

	public function getName() {
		$name = $this->baseName;
		
		// append the vector size
		if($this->operandSize > 1) {
			$name .= "_{$this->operandSize}X";
		}
		
		// append operand type to the name
		$opCount = $this->getOperandCount();
		for($i = 1; $i <= $opCount; $i++) {
			$type = $this->getOperandType($i);
			$name .= "_$type";
		}

		if($this->multipleData) {
			$name .= "_MIO";
		} else {
			// append the address mode
			if($this->addressMode) {
				if(in_array('MultipleAddressMode', class_uses($this))) {
					$name .= "_$this->addressMode";
				}
			}
		}
		return $name;
	}
	
	public function getAddressMode() {
		return $this->addressMode;
	}

	// return code for the op handler
	public function getCode() {
		$lines = array();
		$name = $this->getName();
		$instr = $this->getInstructionStructure();
		$action = 
		$opCount = $this->getOperandCount();
		$targetCount = $this->getJumpTargetCount();
		$lines[] = $this->getLabelCode($name);
		$lines[] = $this->getMacroDefinitions();
		if($targetCount == 2) {
			// assume the first branch is taken
			$lines[] = "{";
			$lines[] = 		"int32_t condition;";
			$lines[] = 		$this->getSetHandlerCode("INSTR->next_handler1");
			$lines[] = 		$this->getAction();
			$lines[] = 		"if(condition) {";
			$lines[] = 			"ip = INSTR->instruction_pointer1;";
			$lines[] = 		"} else {";
			$lines[] = 			$this->getSetHandlerCode("INSTR->next_handler2");
			$lines[] = 			"ip = INSTR->instruction_pointer2;";
			$lines[] = 		"}";
			$lines[] = "}";
			$lines[] = $this->getJumpCode();
		} else if($targetCount == 0 || $targetCount == 1) {
			// regular, non-jump instruction goes to the next instruction
			// a unconditional jump instruction goes to the jump target
			$lines[] = "{";
			$lines[] = 		$this->getSetHandlerCode("INSTR->next_handler");
			$lines[] = 		$this->getAction();
			if($this->isVariableLength()) {
				$lines[] = "ip += INSTR->length;";
			} else {
				$lines[] = "ip += sizeof($instr);";
			}
			$lines[] = "}";
			$lines[] = $this->getJumpCode();
		} else {
			// end of execution
			$lines[] = "{";
			$lines[] = 		$this->getAction();
			$lines[] = "}";
		}
		$lines[] = $this->getMacroUndefinitions();
		return $lines;
	}

	// return code for helper functions needed by the handler
	public function getHelperFunctions() {
	}
	
	// return the instruction structure for the op
	public function getInstructionStructure() {		
		$opCount = $this->getOperandCount();
		$targetCount = $this->getJumpTargetCount();
		$instr = "qb_instruction";
		
		if($targetCount == 2) {
			$instr .= "_branch";
		} else if($targetCount == 1) {
			$instr .= "_jump";
		}
		
		for($i = 1; $i <= $opCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			if(!$addressMode) {
				$class = get_class($this);
				die("Operand $i of $class has null address mode\n");
			}
			$instr .= "_{$addressMode}";
		}
		
		if($this->needsLineNumber()) {
			$instr .= "_lineno";
		}
		return $instr;
	}
	
	// return the instruction structure definition
	public function getInstructionStructureDefinition() {
		$instr = $this->getInstructionStructure();
		$targetCount = $this->getJumpTargetCount();
		$opCount = $this->getOperandCount();
		$lines = array();
		$lines[] = "typedef struct $instr {";
		if($targetCount == 2) {
			$lines[] = "void *next_handler1;";
			$lines[] = "int8_t *instruction_pointer1;";
			$lines[] = "void *next_handler2;";
			$lines[] = "int8_t *instruction_pointer2;";
		} else if($targetCount == 1) {
			$lines[] = "void *next_handler;";
			$lines[] = "int8_t *instruction_pointer;";
		} else {
			$lines[] = "void *next_handler;";
		}
		
		for($i = 1; $i <= $opCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			$lines[] = "qb_pointer_{$addressMode} operand{$i};";
		}
		
		if($this->needsLineNumber()) {
			$lines[] = "uint32_t line_number;";
		}
		$lines[] = "} $instr;";
		return $lines;
	}
	
	public function getInstructionFormat() {
		$srcCount = $this->getInputOperandCount();
		$dstCount = $this->getOutputOperandCount();
		$format = "";
		
		for($i = 1; $i <= $srcCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			switch($addressMode) {
				case 'SCA': $format .= 's'; break;
				case 'ELE': $format .= 'e'; break;
				case 'ARR': $format .= 'a'; break;
			}
		}

		for($i = 1; $i <= $dstCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			switch($addressMode) {
				case 'SCA': $format .= 'S'; break;
				case 'ELE': $format .= 'E'; break;
				case 'ARR': $format .= 'A'; break;
			}
		}
		return $format;
	}
	
	// return the number of input operands
	public function getInputOperandCount() {
		return 0;
	}
	
	// return the number of output operands 
	public function getOutputOperandCount() {
		return 0;
	}
	
	// return the number of jump targets
	public function getJumpTargetCount() {
		return 0;
	}

	// return the total number of operands
	public function getOperandCount() {
		return $this->getInputOperandCount() + $this->getOutputOperandCount(); 
	}
	
	// return the type of operand $i (starting from 1)
	// by default, all operands have the same type
	public function getOperandType($i) {
		return $this->operandType;
	}
	
	// return the C-type of operand $i
	public function getOperandCType($i) {
		static $cTypes = array(
			"I08" => "int8_t",		"I16" => "int16_t",			"I32" => "int32_t",			"I64" => "int64_t",
			"S08" => "int8_t",		"S16" => "int16_t",			"S32" => "int32_t",			"S64" => "int64_t",
			"U08" => "uint8_t",		"U16" => "uint16_t",		"U32" => "uint32_t",		"U64" => "uint64_t",
			"F32" => "float32_t",	"F64" => "float64_t",
		);
		$operandType = $this->getOperandType($i);
		if(!$operandType) {
			$className = get_class($this);
			throw new Exception("$i is not a valid index for $className");
		}
		return $cTypes[$operandType];
	}
	
	// return the number of elements that consist an operand 
	public function getOperandSize($i) {
		return $this->operandSize;
	}
	
	// return the address mode of operand $i
	// by default, all operands use the same address mode
	public function getOperandAddressMode($i) {
		return $this->addressMode;
	}
	
	public function isMultipleData() {
		return $this->multipleData;
	}	
	
	public function isMultithreaded() {
		return false;
	}	
	
	public function isVariableLength() {
		return false;
	}
	
	public function needsInterpreterContext() {
		return false;
	}
	
	public function needsUnrolling() {
		return $this->unrolling;
	}
	
	public function needsLineNumber() {
		return false;
	}
	
	public function performsWrapAround() {
		if($this->addressMode == "ARR" && !$this->isOverridden('getActionOnMultipleData')) {
			return true;
		}
		return false;
	}
	
	protected function getMacroDefinitions() {
		$instr = $this->getInstructionStructure();
		$srcCount = $this->getInputOperandCount();
		$opCount = $this->getOperandCount();
		$lines = array();
		$lines[] = "#define INSTR		(($instr *) ip)";
		if($this->needsLineNumber()) {
			$lines[] = "#define line_number		INSTR->line_number";
		}
		for($i = 1; $i <= $opCount; $i++) {
			$cType = $this->getOperandCType($i);
			$addressMode = $this->getOperandAddressMode($i);
			$operand = "INSTR->operand$i";
			$name = ($i <= $srcCount) ? "op{$i}" : "res";
			switch($addressMode) {
				case 'SCA':
					$lines[] = "#define $name	(($cType *) $operand.data_pointer)[0]";
					break;
				case 'ELE':
					$lines[] = "#define $name	(($cType *) $operand.data_pointer)[$operand.index_pointer[0]]";
					break;
				case 'ARR':
					$lines[] = "#define {$name}_ptr		((($cType *) $operand.data_pointer) + $operand.index_pointer[0])";
					$lines[] = "#define {$name}_count	$operand.count_pointer[0]";
					break;
			}
		}
		return $lines;
	}
	
	protected function getMacroUndefinitions() {
		$srcCount = $this->getInputOperandCount();
		$opCount = $this->getOperandCount();
		$lines = array();
		$lines[] = "#undef INSTR";
		if($this->needsLineNumber()) {
			$lines[] = "#undef line_number";
		}
		for($i = 1; $i <= $opCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			$name = ($i <= $srcCount) ? "op{$i}" : "res";
			switch($addressMode) {
				case 'SCA':
					$lines[] = "#undef $name";
					break;
				case 'ELE':
					$lines[] = "#undef $name";
					break;
				case 'ARR':
					$lines[] = "#undef {$name}_ptr";
					$lines[] = "#undef {$name}_count";
					break;
			}
		}
		return $lines;
	}
	
	public function getFunctionName($prefix) {
		$className = get_class($this);
		$opName = preg_replace("/([a-z])([A-Z])/", "$1_$2", $className);
		$opName = strtolower($opName);
		$name = "qb_{$prefix}_{$opName}";
		if($this->operandSize != 1 && is_int($this->operandSize)) {
			$name .= "_{$this->operandSize}x";
		}
		if($this->isMultipleData()) {
			$name .= "_multiple_times";
		}
		if($this->operandType) {
			$name .= "_{$this->operandType}";
		}
		return $name;
	}
	
	public function getHandlerFunctionName() {
		return $this->getFunctionName("do");
	}
	
	public function getHandlerFunctionType() {
		if(!$this->isOverridden('getAction') && !$this->isOverridden('getCode')) {
			if($this->isMultipleData()) {
				return 'extern';
			} else {
				$action = $this->getActionOnUnitData();
				$count = count($action, true);
				if($count > 16) {
					return 'extern';
				} else if($count == 1) {
					return 'inline';
				}
				
				$lines = array_linearize($action);
				$hasLoop = false;
				foreach($lines as $line) {
					if(preg_match('/\b(for|while)\b/', $line)) {
						$hasLoop = true;
					}
				}
				if($hasLoop) {
					return 'extern';
				} else {
					return 'inline';
				}
			}
		}
		return null;
	}

	public function getHandlerFunctionDefinition() {
		$functionType = $this->getHandlerFunctionType();
		if(!$functionType) {
			return null;
		}
		$function = $this->getHandlerFunctionName();
		$parameterList = $this->getHandlerFunctionParameterList(true);
		$expressions = $this->getActionExpressions();
		switch($functionType) {
			case 'inline': $typeDecl = "static zend_always_inline void"; break;
			case 'extern': $typeDecl = "void ZEND_FASTCALL"; break;
		}
		
		// replace op# with (*op#_ptr) for array operands and res with (*res_ptr)
		$expressions = array_linearize($expressions);
		$srcCount = $this->getInputOperandCount();
		$arrayOperands = array();
		for($i = 1; $i <= $srcCount; $i++) {
			if($this->getOperandAddressMode($i) == "ARR") {
				$arrayOperands[] = $i;
			}
		}
		if($arrayOperands) {
			$inputOperandRegExp = '/\bop(' . implode('|', $arrayOperands) . ')\b/';
		}
		foreach($expressions as &$expression) {
			if($arrayOperands) {
				$expression = preg_replace($inputOperandRegExp, '(*op\1_ptr)', $expression);
			}
			$expression = preg_replace('/\bres\b/', '(*res_ptr)', $expression);
		}
		
		$lines = array();			
		$lines[] = "$typeDecl $function($parameterList) {";
		$lines[] = $expressions;
		$lines[] = "}";
		return $lines;
	}
	
	public function getHandlerFunctionParameterList($forDeclaration) {
		$instr = $this->getInstructionStructure();
		$srcCount = $this->getInputOperandCount();
		$opCount = $this->getOperandCount();
		$params = array();
		if($this->needsInterpreterContext()) {
			if($forDeclaration) {
				$params[] = "qb_interpreter_context *__restrict cxt";
			} else {
				$params[] = "cxt";
			}
		} 
		for($i = 1; $i <= $opCount; $i++) {
			$cType = $this->getOperandCType($i);
			$addressMode = $this->getOperandAddressMode($i);
			$operand = "((($instr *) ip)->operand$i)";
			$name = ($i <= $srcCount) ? "op{$i}" : "res";
			switch($addressMode) {
				case 'SCA':
				case 'ELE':
					if($forDeclaration) {
						$params[] = ($i <= $srcCount) ? "$cType $name" : "$cType *{$name}_ptr";
					} else {
						$params[] = ($i <= $srcCount) ? "$name" : "&$name";
					}
					break;
				case 'ARR':
					if($forDeclaration) {
						$params[] = "$cType *{$name}_ptr";
						$params[] = "uint32_t {$name}_count";
					} else {
						$params[] = "{$name}_ptr";
						$params[] = "{$name}_count";
					}
					break;
			}
		}
		if($this->needsLineNumber()) {
			if($forDeclaration) {
				$params[] = "uint32_t line_number";
			} else {
				$params[] = "line_number";
			}
		} 
		return implode(", ", $params);
	}

	// return the name of the dispatcher function, which sends a instruction to multiple threads 
	protected function getDispatcherFunctionName() {
		$instr = $this->getInstructionStructure();
		$name = "qb_dispatch_" . substr($instr, 3);
		return $name;
	}
	
	// return the parameter list of the dispatcher function
	protected function getDispatcherFunctionParameterList($forDeclaration) {
		$instr = $this->getInstructionStructure();
		$params = array();
		if($forDeclaration) {
			$params[] = "qb_interpreter_context *__restrict cxt";
			$params[] = "void *control_func";
			$params[] = "$instr *__restrict instr";
		} else {
			$params[] = "cxt";
			$params[] = $this->getControllerFunctionName();
			$params[] = "($instr *) ip";
		}
		return implode(", ", $params);
	}
	
	// return the body list of the dispatcher function
	public function getDispatcherFunctionDefinition() {
		if(!$this->isMultipleData() || !$this->isMultithreaded()) {
			return null;
		}
		$instr = $this->getInstructionStructure();
		$dispatcherTypeDecl = "void ZEND_FASTCALL";
		$dispatcherFunction = $this->getDispatcherFunctionName();
		$dispatcherParameterList = $this->getDispatcherFunctionParameterList(true);
		$opCount = $this->getOperandCount();
		$lines = array();
		$lines[] = "$dispatcherTypeDecl $dispatcherFunction($dispatcherParameterList) {";
		$lines[] =		"uint32_t j;";
		$lines[] =		"$instr new_instr_list[MAX_THREAD_COUNT];";
		$lines[] =		"int8_t *new_ips[MAX_THREAD_COUNT];";
		$lines[] =		"for(j = 0; j < cxt->thread_count_for_next_op; j++) {";
		$lines[] =			"$instr *new_instr = &new_instr_list[j];";
		$lines[] =			"qb_pointer_adjustment *adj;";
		// create temporary instruction structures
		for($i = 1, $k = 0; $i < $opCount; $i++) {
			$addressMode = $this->getOperandAddressMode($i);
			$cType = $this->getOperandCType($i);
			if($addressMode == "ARR") {
				$lines[] = 	"adj = &cxt->adjustments_for_next_op[j][$k];";
				$lines[] = 	"new_instr->operand{$i}.data_pointer = instr->operand{$i}.data_pointer;";
				$lines[] = 	"new_instr->operand{$i}.index_pointer = &adj->index;";
				$lines[] = 	"new_instr->operand{$i}.count_pointer = &adj->count;";
				$k++;
			} else {
				$lines[] = 	"new_instr->operand{$i} = (($cType *) instr->operand{$i};";
			}
		}
		$lines[] =			"new_ips[j] = (int8_t *) new_instr;";
		$lines[] =		"}";
		$lines[] = "qb_dispatch_instruction_to_threads(cxt, control_func, new_ips);";
		$lines[] = "}";
		return $lines;
	}
	
	// return the body of the controller function, which decides whether to use multithreading or not
	protected function getControllerFunctionName() {
		return $this->getFunctionName("dispatch");
	}
	
	// return the parameter list of the controller function
	protected function getControllerFunctionParameterList($forDeclaration) {
		$params = array();
		if($forDeclaration) {
			$params[] = "qb_interpreter_context *__restrict cxt";
			$params[] = "int8_t *__restrict ip";
		} else {
			$params[] = "cxt";
			$params[] = "ip";
		}
		return implode(", ", $params);
	}
	
	// return the body of the controller function
	public function getControllerFunctionDefinition() {
		if(!$this->isMultipleData() || !$this->isMultithreaded()) {
			return null;
		}
		$opCount = $this->getOperandCount();
		$controllerTypeDecl = "void";
		$controllerFunction = $this->getControllerFunctionName();
		$controllerParameterList = $this->getControllerFunctionParameterList(true);
		$dispatcherFunction = $this->getDispatcherFunctionName();
		$dispatcherParameterList = $this->getDispatcherFunctionParameterList(false);
		$handlerFunction = $this->getHandlerFunctionName();
		$handlerParameterList = $this->getHandlerFunctionParameterList(false);
		$lines = array();
		$lines[] = "$controllerTypeDecl $controllerFunction($controllerParameterList) {";
		$lines[] =		"if(cxt->thread_count_for_next_op) {";
		$lines[] =			"$dispatcherFunction($dispatcherParameterList);";
		$lines[] = 		"} else {";
		$lines[] =			$this->getMacroDefinitions();
		$lines[] = 			"$handlerFunction($handlerParameterList);";
		$lines[] =			$this->getMacroUndefinitions();
		$lines[] =		"}";
		$lines[] = "}";
		return $lines;
	}
	
	// return codes that perform what the op is supposed to do
	public function getAction() {
		$functionType = $this->getHandlerFunctionType();
		if($functionType) {	
			if(!$this->isMultipleData() || !$this->isMultithreaded()) {
				// call the handler directly
				$function = $this->getHandlerFunctionName();
				$parameterList = $this->getHandlerFunctionParameterList(false);
			} else {
				// send instruction to the controller function, which will either
				// (1) call the dispatcher function, which then calls the controller function again from different threads
				// (2) call the handler function
				$function = $this->getControllerFunctionName();
				$parameterList = $this->getControllerFunctionParameterList(false);
			}
			return "$function($parameterList);";
		} else {
			// just insert the code, expanding the operands
			return $this->getActionExpressions();
		}
	}	
	
	// return an expression for handling a single unit of data (typically a scalar)
	protected function getActionOnUnitData() {
		return null;
	}

	// return an expression for handling multiple units of data
	protected function getActionOnMultipleData() {
		return null;
	}
	
	protected function getActionExpressions() {
		if($this->isMultipleData()) {
			$action = $this->getActionOnMultipleData();
			if(!$action) {
				// change the address mode temporarily
				$multipleAddressMode = in_array('MultipleAddressMode', class_uses($this));
				if($multipleAddressMode) {
					$originalAddressMode = $this->addressMode;
					$this->addressMode = "SCA";
				}
				$this->multipleData = false;
				$scalarExpression = $this->getAction();
				if($multipleAddressMode) {
					$this->addressMode = $originalAddressMode;
				}
				$this->multipleData = true;
				$action = $this->getIterationCode($scalarExpression);
			}
		} else {
			$action = $this->getActionOnUnitData();
			if($this->needsUnrolling()) {
				$action = $this->getUnrolledCode($action, $this->operandSize);
			}
		}
		return $action;
	}
	
	// return code for the handle label	
	protected function getLabelCode($name) {
		if(self::$compiler == "GCC") {
			return "label_$name:";
		} else if(self::$compiler == "MSVC") {
			return "case QB_$name:";
		}
	}
	
	// return code for setting the next op handler
	protected function getSetHandlerCode($value) {
		return "handler = $value;";
	}

	// return code for jumping to the next op handler
	protected function getJumpCode() {
		if(self::$compiler == "GCC") {
			return "goto *handler;";
		} else if(self::$compiler == "MSVC") {
			return "break;";
		}
	}
	
	// return code for timeout check (Windows only)
	protected function getTimeoutCode() {
		if(self::$compiler == "MSVC") {
			$lines = array();
			$lines[] = "#ifdef ZEND_WIN32";
			$lines[] = "if(windows_timeout_check_counter == 4096) {";
			$lines[] = 		"if(*windows_timed_out_pointer) {";
			$lines[] =			"zend_timeout(1);";
			$lines[] = 			"goto label_exit;";
			$lines[] =		"}";
			$lines[] = "} else {";
			$lines[] =		"windows_timeout_check_counter++;";
			$lines[] = "}";
			$lines[] = "#endif";
			return $lines;
		}
	}	
	
	// multiple a scalar operation multiple times
	protected function getUnrolledCode($expression, $count) {
		$srcCount = $this->getInputOperandCount();
		$arrayOperands = array();
		for($i = 1; $i <= $srcCount; $i++) {
			if($this->getOperandAddressMode($i) == "ARR") {
				$arrayOperands[] = $i;
			}
		}
		$nums = implode("|", $arrayOperands);
		$lines = array();
		for($i = 0; $i < $count; $i++) {
			$patterns = array('/\bres\b/', '/\bop(' . $nums . ')\b/');
			$replacements = array("res_ptr[{$i}]", "op\\1_ptr[{$i}]");
			if(is_array($expression)) {
				foreach($expression as $subexpression) {
					$lines[] = preg_replace($patterns, $replacements, $subexpression);
				}
			} else {
				$lines[] = preg_replace($patterns, $replacements, $expression);
			}
		}
		return $lines;
	}
	
	// return code for a loop that performs the same operation on all element of an array 
	protected function getIterationCode($expression) {
		$srcCount = $this->getInputOperandCount();
		$lines = array();		
		
		// make sure none of the input operands are empty		
		$condition = false;
		$operandCounts = array();
		for($i = 1; $i <= $srcCount; $i++) {
			$operandSize = $this->getOperandSize($i);
			if($this->getOperandAddressMode($i) == "ARR" && $operandSize !== 0) {
				$operandCounts[] = "op{$i}_count";
			}
		}
		$operandCounts[] = "res_count";
		// use bitwise AND here, just in case the compiler doesn't optimize correctly
		$condition = implode(" && ", $operandCounts);
		$lines[] = "if($condition) {";
		for($i = 1; $i <= $srcCount; $i++) {
			$cType = $this->getOperandCType($i);
			$operandSize = $this->getOperandSize($i);
			if($this->getOperandAddressMode($i) == "ARR" && $operandSize !== 0) {
				$lines[] =	"$cType *op{$i}_start = op{$i}_ptr, *op{$i}_end = op{$i}_ptr + op{$i}_count;";
			}
		}
		$cType = $this->getOperandCType($srcCount + 1);
		$lines[] =		"$cType *res_end = res_ptr + res_count;";
		$lines[] = 		"for(;;) {";
		$lines[] = 			$expression;
		$lines[] =			"";
		$operandSize = $this->getOperandSize($srcCount + 1);
		// group incrementations together since they can happen in parallel
		$lines[] = 			"res_ptr += $operandSize;";
		for($i = 1; $i <= $srcCount; $i++) {
			$operandSize = $this->getOperandSize($i);
			if($this->getOperandAddressMode($i) == "ARR" && $operandSize !== 0) {
				$lines[] =		"op{$i}_ptr += $operandSize;";
			}
		}
		$lines[] =			"if(res_ptr >= res_end) {";
		$lines[] =				"break;";
		$lines[] =			"}";
		for($i = 1; $i <= $srcCount; $i++) {
			$operandSize = $this->getOperandSize($i);
			if($this->getOperandAddressMode($i) == "ARR" && $operandSize !== 0) {
				$lines[] = 	"if(op{$i}_ptr >= op{$i}_end) {";
				$lines[] = 		"op{$i}_ptr = op{$i}_start;";
				$lines[] = 	"}";
			}
		}
		$lines[] = 		"}";	// end for
		$lines[] = "}"; 		// end if
		return $lines;
	}
	
	protected function isOverridden($methodName) {
		$child  = new ReflectionClass($this);
		$method = $child->getMethod($methodName);
		return ($method->class != 'Handler');
	}
}

function array_linearize($array) {
	$result = array();
	if(is_array($array)) {
		foreach($array as $element) {
			if($element !== NULL) {
				if(is_array($element)) {
					$sub_array = array_linearize($element);					
					array_splice($result, count($result), 0, $sub_array);
				} else {
					$result[] = $element;
				}
			}
		}
	} else {
		$result[] = $array;
	}
	return $result;
}

?>