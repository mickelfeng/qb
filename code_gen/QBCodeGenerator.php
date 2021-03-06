<?php

class QBCodeGenerator {
	protected $compiler;
	protected $handlers = array();
	protected $elementTypes = array("S32", "U32", "S08", "U08", "S16", "U16", "S64", "U64", "F32", "F64");
	protected $floatTypes = array("F32", "F64");
	protected $addressModes = array("VAR", "ELV", "ARR");
	protected $scalarAddressModes = array("VAR", "ELV");
	
	protected $currentIndentationLevel;
	
	public function __construct() {
		if(!$this->handlers) {
			$this->addHandlers();
		}
	}
	
	public function writeTypeDeclarations($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;
	
		fwrite($handle, "#pragma pack(push,1)\n\n");
		$structs = $this->getStructureDefinitions();
		foreach($structs as $struct) {
			$this->writeCode($handle, $struct);
			fwrite($handle, "\n");
		}
		fwrite($handle, "#pragma pack(pop)\n\n");

		$lines = array();
		$lines[] = "extern void *op_handlers[];";
		$lines[] = "";
		$this->writeCode($handle, $lines);
	}
	
	public function writeFunctionPrototypes($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;

		$functions = $this->getFunctionDefinitions();
		$inlinePattern = '/\b(inline|zend_always_inline)\b/';
		
		// add non-inline functions first
		foreach($functions as $function) {
			$line1 = $function[0];
			
			if(!preg_match($inlinePattern, $line1)) {
				$prototype = preg_replace('/\s*{\s*$/', ';', $line1);
				fwrite($handle, "$prototype\n");
			}
		}
		fwrite($handle, "\n");
		
		// add inline-functions
		foreach($functions as $function) {
			$line1 = $function[0];
			
			if(preg_match($inlinePattern, $line1)) {
				$this->writeCode($handle, $function);
				fwrite($handle, "\n");
			}
		}
	}

	public function writeFunctionDefinitions($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;
		
		$functions = $this->getFunctionDefinitions();
		foreach($functions as $function) {
			$line1 = $function[0];
		
			if(!preg_match('/\b(inline|zend_always_inline)\b/', $line1)) {
				$this->writeCode($handle, $function);
				fwrite($handle, "\n");
			}
		}
	}
	
	public function writeMainLoop($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;
		
		$lines = array();
		for($i = 1; $i < 6; $i++) {
			$lines[] = "#define op$i	(*op{$i}_ptr)";
		}
		$lines[] = "#define res		(*res_ptr)";
		$lines[] = "";			
		$lines[] = "void ZEND_FASTCALL qb_run(qb_interpreter_context *__restrict cxt) {";
		$lines[] =		"";
		$lines[] = 		"if(cxt) {";
		$lines[] = 			"register void *__restrict op_handler;";
		$lines[] = 			"register int8_t *__restrict instruction_pointer;";
		$lines[] = 			"int8_t *__restrict segments[MAX_SEGMENT_COUNT];";
		$lines[] = 			"int8_t *__restrict segment0;";
		$lines[] =			"int32_t segment_expandable[MAX_SEGMENT_COUNT];";			 
		$lines[] = 			"uint32_t segment_element_counts[MAX_SEGMENT_COUNT];";
		$lines[] =			"uint32_t selector, index, index_index, size_index;";
		$lines[] = 			"uint32_t string_length;";
		$lines[] = 			"uint32_t vector_count, matrix1_count, matrix2_count, mmult_res_count;";
		$lines[] = 			"uint32_t op1_start_index, op2_start_index, op3_start_index, op4_start_index, op5_start_index;";
		$lines[] =			"uint32_t op1_count, op2_count, op3_count, op4_count, op5_count;";
		$lines[] = 			"uint32_t res_start_index, res_count, res_count_before;";
		
		if($compiler == "MSVC") {
			$lines[] =		"uint32_t windows_timeout_check_counter = 0;";
			$lines[] = 		"volatile zend_bool *windows_timed_out_pointer = cxt->windows_timed_out_pointer;";
		}
		$lines[] =			"USE_TSRM";
		$lines[] = 			"";
		$lines[] = 			"{";
		$lines[] = 				"uint32_t i;";
		$lines[] = 				"instruction_pointer = cxt->function->instructions;";
		$lines[] = 				"op_handler = *((void **) instruction_pointer);";
		$lines[] = 				"instruction_pointer += sizeof(void *);";
		$lines[] =				"// copy values from cxt onto the stack so they can be accessed without two second deferences";
		$lines[] = 				"for(i = 0; i < cxt->storage->segment_count; i++) {";
		$lines[] = 					"qb_memory_segment *segment = &cxt->storage->segments[i];";
		$lines[] = 					"segments[i] = segment->memory;";
		$lines[] = 					"segment_element_counts[i] = *segment->array_size_pointer;";
		$lines[] = 					"segment_expandable[i] = (segment->flags & QB_SEGMENT_EXPANDABLE);";
		$lines[] =					"// set the pointers in the segment structure to local variables here so we can update";
		$lines[] =					"// them as well when we expand the segment";
		$lines[] =					"segment->stack_ref_memory = &segments[i];";
		$lines[] =					"segment->stack_ref_element_count = &segment_element_counts[i];";
		$lines[] = 				"}";
		$lines[] =				"// store pointer to segment 0 in a separate variable to enable better optimization";
		$lines[] =				"// since segment 0 and 1 will never be enlarged, we don't have to worry about it changing";
		$lines[] =				"segment0 = segments[0];";			
		if($compiler == "GCC") {
			$lines[] = 			"goto *op_handler;";
		}
		$lines[] = 			"}";
		$lines[] = "";
		if($compiler == "MSVC") {
			// Visual C doesn't support computed goto so we have to use a giant switch() statement instead
			$lines[] = 		"do {";
			$lines[] = 			"switch((int) op_handler) {";
		}
		$this->writeCode($handle, $lines);
		
		foreach($this->handlers as $handler) {
			$lines = $handler->getCode();
			$lines[] = "";
			$this->writeCode($handle, $lines);
		}
		
		$lines = array();
		if($compiler == "MSVC") {
			$lines[] = 			"default:";
			$lines[] = 				"__assume(0);";
			$lines[] = 			"}";
			$lines[] = 		"} while(1);";
		}
		$lines[] = 			"label_exit:";
		$lines[] = 			"{";
		$lines[] = 				"uint32_t i;";
		$lines[] =				"// point the stack_ref pointer back to variables in the structure";
		$lines[] = 				"for(i = 0; i < cxt->storage->segment_count; i++) {";
		$lines[] = 					"qb_memory_segment *segment = &cxt->storage->segments[i];";
		$lines[] =					"segment->stack_ref_memory = &segment->memory;";
		$lines[] =					"segment->stack_ref_element_count = &segment->element_count;";
		$lines[] = 				"}";
		$lines[] = 				"return;";
		$lines[] = 			"}";
		if($compiler == "GCC") {
			$lines[] = 	"} else {";
			foreach($this->handlers as $handler) {
				$name = $handler->getName();
				$lines[] = 		"op_handlers[QB_$name] = &&label_$name;";
			}
		}
		$lines[] = 		"}";
		$this->writeCode($handle, $lines);

		$lines = array();
		$lines[] =		"";
		$lines[] = 	"}";
		$lines[] = 	"";
		if($compiler == "GCC") {
			$lines[] = 	"void *op_handlers[QB_OPCODE_COUNT];";
		}
		$this->writeCode($handle, $lines);
	}
	
	public function writeOpCodes($handle) {
		$this->currentIndentationLevel = 0;
		
		$lines = array();
		$lines[] = "enum qb_opcode {";
		foreach($this->handlers as $handler) {
			$name = $handler->getName();
			// if an op has a name ending in _VAR, there will also be ones ending in _ELV, and possible _ARR
			if(preg_match("/(\w+)_VAR$/", $name, $m)) {
				$baseName = $m[1];
				$lines[] = "QB_$baseName,";
				$lines[] = "QB_$name = QB_$baseName,";
			} else {
				$lines[] = "QB_$name,";
			}
		}
		$lines[] = "QB_OPCODE_COUNT";
		$lines[] = "};";
		$this->writeCode($handle, $lines);
	}
	
	public function writeOpFlags($handle) {
		$this->currentIndentationLevel = 0;
		
		$lines = array();
		$lines[] = "uint32_t global_op_flags[] = {";
		foreach($this->handlers as $handler) {
			$flags = array();
			if($handler->isVectorized()) {
				// op handles vectors
				$flags[] = "QB_OP_VECTORIZED";
			}
			if($handler->needsMatrixDimensions()) {
				// op needs matrix dimensions
				$flags[] = "QB_OP_NEED_MATRIX_DIMENSIONS";
			}
			if($handler->hasAlternativeAddressModes()) {
				// op can be employed in different address modes
				$flags[] = "QB_OP_MULTI_ADDRESS";
			}
			if($handler->getJumpTargetCount() != 0) {
				// op will redirect execution to another location 
				$flags[] = "QB_OP_JUMP";
			}
			if($handler->needsLineNumber()) {
				// the line number needs to be packed into the instruction (for error reporting)
				$flags[] = "QB_OP_NEED_LINE_NUMBER";
			}
			if($handler instanceof QBIssetHandler) {
				// the behavior of isset and unset are somewhat unique, namely that they can to access an out-of-bound element 
				$flags[] = "QB_OP_ISSET";
			}
			if($handler instanceof QBUnsetHandler) {
				$flags[] = "QB_OP_UNSET";
			}
			if($handler->isVariableLength()) {
				$flags[] = "QB_OP_VARIABLE_LENGTH";
			}
			if($handler->performsWrapAround()) {
				$flags[] = "QB_OP_PERFORM_WRAP_AROUND";
			}
			
			$combined = ($flags) ? implode(" | ", $flags) : "0";
			$name = $handler->getName();
			$lines[] = "// $name";
			$lines[] = "$combined,";
		}
		$lines[] = "};";
		$lines[] = "";
		$lines[] = "uint32_t global_operand_flags[] = {";
		foreach($this->handlers as $handler) {
			$name = $handler->getName();
			$shift = 0;
			$flags = array();
			$jumpTargetCount = $handler->getJumpTargetCount();

			// add jump targets
			for($i = 1; $i <= $jumpTargetCount; $i++, $shift += 4) {
				$flags[] = "(QB_OPERAND_JUMP_TARGET << $shift)";
			}
			
			// add operands
			$opCount = $handler->getOperandCount();
			$srcCount = $handler->getInputOperandCount();
			for($i = 1; $i <= $opCount; $i++, $shift += 4) {
				$mode = $handler->getOperandAddressMode($i);
				$operandFlags = "QB_OPERAND_ADDRESS_$mode";
				if($i > $srcCount) {
					$operandFlags = "($operandFlags | QB_OPERAND_WRITABLE)";
				}
				$flags[] = "($operandFlags << $shift)";
			}

			// sanity check				
			if(count($flags) > 8) {
				$count = count($flags);
				throw new Exception("$name has too many operands ($count)! We only got 32 bits to store the operand flags!");
			}
			$combined = ($flags) ? implode(" | ", $flags) : "0";
			$lines[] = "// $name";
			$lines[] = "$combined,";
		}
		$lines[] = "};";
		$lines[] = "";
		
		$this->writeCode($handle, $lines);
	}
	
	public function writeOpNames($handle) {
		$this->currentIndentationLevel = 0;
		
		$opnames = array();
		foreach($this->handlers as $handler) {
			$opnames[] = $handler->getName();
		}
		$folder = dirname(__FILE__);
		$zend_opnames = file("$folder/zend_op_names.txt", FILE_IGNORE_NEW_LINES);
		$pbj_opnames = file("$folder/pixel_bender_op_names.txt", FILE_IGNORE_NEW_LINES);

		fwrite($handle, "#ifdef HAVE_ZLIB\n");
		$this->writeCompressTable($handle, "compressed_table_op_names", $opnames, true, true);
		$this->writeCompressTable($handle, "compressed_table_zend_op_names", $zend_opnames, true, true);
		$this->writeCompressTable($handle, "compressed_table_pbj_op_names", $pbj_opnames, true, true);
		fwrite($handle, "#else\n");
		$this->writeCompressTable($handle, "compressed_table_op_names", $opnames, false, true);
		$this->writeCompressTable($handle, "compressed_table_zend_op_names", $zend_opnames, false, true);
		$this->writeCompressTable($handle, "compressed_table_pbj_op_names", $pbj_opnames, false, true);
		fwrite($handle, "#endif\n");
	}

	public function writeNativeCodeTables($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;
		
		$actions = array();
		foreach($this->handlers as $index => $handler) {
			$code = null;
			try {
				$lines = $handler->getAction();
				if($lines) {
					$code = $this->formatCode($lines);
				}
			} catch(Exception $e) {
			}
			$actions[$index] = $code;
		}

		$resultSizePossibilities = array();
		foreach($this->handlers as $index => $handler) {
			$expressions = $handler->getResultSizePossibilities();
			if($expressions) {
				if($expressions) {
					if(is_array($expressions)) {
						$list = "";
						foreach($expressions as $expr) {
							$list .= $expr . "\x00";
						}
					} else {
						$list = $expressions . "\x00";
					}
					$resultSizePossibilities[$index] = $list;
				}
			}
		}
		
		$resultSizeCalculations = array();
		foreach($this->handlers as $index => $handler) {
			$lines = $handler->getResultSizeCalculation();
			if($lines) {
				$resultSizeCalculations[$index] = $this->formatCode($lines);
			} else {
				$resultSizeCalculations[$index] = null;
			}
		}
		
		// create function declaration table and reverse look-up table
		$functionDeclarations = $this->getFunctionDeclarations();
		$functionIndices = array();
		$functionRefCounts = array();
		foreach($functionDeclarations as $index => &$decl) {
			if(preg_match('/(\w+)\s*\(/', $decl, $m)) {
				$functionName = $m[1];
				
				// get rid of ZEND_FASTCALL and ZEND_MACRO
				$decl = preg_replace('/\b(ZEND_FASTCALL|ZEND_MACRO)\s*/', '', $decl);
				$functionIndices[$functionName] = $index;
				$functionRefCounts[$index] = 0;
			}
		}
		unset($decl);
		
		// set up the function reference table
		$references = array();
		foreach($this->handlers as $handler) {
			$indices = array();
			$action = $handler->getCode();
			if($action) {
				if(is_scalar($action)) {
					$action = array($action);
				} else {
					$action = array_linearize($action);
				}
				foreach($action as $line) {
					// look for tokens that might be function calls
					$line = preg_replace('/#define \w+\s*/', '', $line);
					if(preg_match_all('/(\w+)\s*\(/', $line, $m)) {
						$names = $m[1];
						while($names) {
							$name = array_shift($names);
							if(isset($functionIndices[$name])) {
								$index = $functionIndices[$name];
								if(!in_array($index, $indices)) {
									$indices[] = $index;
									$functionRefCounts[$index]++;
								
									// if it's calling an inline function, we'll need to declare
									// functions called by the inline function
									$decl = $functionDeclarations[$index];
									if(strpos($decl, '{') !== false) {
										if(preg_match_all('/(\w+)\s*\(/', $decl, $m)) {
												$names = array_merge($names, $m[1]);
										}
									}
								}
							} else {
								switch($name) {
									case 'if':
									case 'for':
									case 'while':
									case 'sizeof':
									case 'return':
									case 'QB_G':
									case 'EG':
									case 'SWAP_LE_I16':
									case 'SWAP_LE_I32':
									case 'SWAP_LE_I64':
									case 'SWAP_BE_I16':
									case 'SWAP_BE_I32':
									case 'SWAP_BE_I64':
									case 'EXPECTED':
									case 'UNEXPECTED':
									case 'USE_TSRM':
										break;
									default:
										throw new Exception("Missing function declaration for $name");
								}
							}
						}
					}
				}
			}
			// indicate the end of the list
			$indices[] = 0xFFFFFFFF;
			$record = "";
			foreach($indices as $index) {
				$record .= pack("V", $index);
			}
			$references[] = $record;
		}
		
		// if a function is never called, then make it empty
		foreach($functionDeclarations as $index => &$decl) {
			if(!$functionRefCounts[$index]) {
				$decl = "";
			}
		}
		unset($decl);
		
		fwrite($handle, "#ifdef HAVE_ZLIB\n");
		$this->writeCompressTable($handle, "compressed_table_native_actions", $actions, true, true);
		$this->writeCompressTable($handle, "compressed_table_native_result_size_calculations", $resultSizeCalculations, true, true);
		$this->writeCompressTable($handle, "compressed_table_native_result_size_possibilities", $resultSizePossibilities, true, true);
		$this->writeCompressTable($handle, "compressed_table_native_prototypes", $functionDeclarations, true, true);
		$this->writeCompressTable($handle, "compressed_table_native_references", $references, true, false);
		fwrite($handle, "#else\n");
		$this->writeCompressTable($handle, "compressed_table_native_actions", $actions, false, true);
		$this->writeCompressTable($handle, "compressed_table_native_result_size_calculations", $resultSizeCalculations, false, true);
		$this->writeCompressTable($handle, "compressed_table_native_result_size_possibilities", $resultSizePossibilities, false, true);
		$this->writeCompressTable($handle, "compressed_table_native_prototypes", $functionDeclarations, false, true);
		$this->writeCompressTable($handle, "compressed_table_native_references", $references, false, false);
		fwrite($handle, "#endif\n");
	}
	
	public function writeNativeSymbolTable($handle, $compiler) {
		$this->setCompiler($compiler);
		$this->currentIndentationLevel = 0;
		
		// parse for declaration of helper functions 
		$functionDeclarations = $this->getFunctionDeclarations();
		
		// add intrinsics
		$folder = dirname(__FILE__);
		$intrinsic = file(($compiler == "MSVC") ? "$folder/intrinsic_functions_msvc.txt" : "$folder/intrinsic_functions_gcc.txt", FILE_IGNORE_NEW_LINES);
		foreach($intrinsic as $decl) {
			$functionDeclarations[] = $decl;
		}

		// figure out what the symbols are
		$symbols = array();
		foreach($functionDeclarations as $decl) {
			if(preg_match('/^\s*(.*?)\s*(\w+)\s*\(([^)]*)\)/', $decl, $m)) {
				$modifiers = $m[1];
				$functionName = $m[2];
				$target = null;
				
				if(strpos($decl, "{") === false) {
					if(preg_match('/\b(ZEND_FASTCALL|ZEND_MACRO)\b/', $modifiers)) {
						$isMacro = preg_match('/\b(ZEND_MACRO)\b/', $modifiers);
						$returnType = trim(preg_replace('/\b(ZEND_FASTCALL|ZEND_MACRO|NO_RETURN)\b/', '', $modifiers));
						$parameterDecls = $m[3];
						$target = "{$functionName}_symbol";
						if($parameterDecls) {
							$parameterDecls = preg_split('/\s*,\s+/', $parameterDecls);
							$parameters = array();
							foreach($parameterDecls as $parameterDecl) {
								$parameterDecl = trim($parameterDecl);
								if($parameterDecl == 'void') {
									// none
								} else if(preg_match('/\w+$/', $parameterDecl, $m)) {
									$parameters[] = $m[0];
								} else if(preg_match('/.../', $parameterDecl, $m)) {
									$parameters[] = $m[0];
								} else {
									throw new Exception("Unable to parse parameter declaration: $parameterDecl");
								}
							}
							$parameterList = implode(", ", $parameters);
							$parameterDecls = implode(", ", $parameterDecls);
						} else {
							$parameterList = "";
							$parameterDecls = "void";
						}
					
						// link name of function to itself when fastcall matches cdecl
						if(!$isMacro) {
							fwrite($handle, "#ifdef FASTCALL_MATCHES_CDECL\n");
							fwrite($handle, "#define $target	$functionName\n");
							fwrite($handle, "#else\n");
						}
						// create a cdecl wrapper for it
						fwrite($handle, "$returnType $target($parameterDecls) {\n");
						if($returnType != 'void') {
							fwrite($handle, "	return $functionName($parameterList);\n");
						} else {
							fwrite($handle, "	$functionName($parameterList);\n");
						}
						fwrite($handle, "}\n");
						if(!$isMacro) {
							fwrite($handle, "#endif\n");
						}
						fwrite($handle, "\n");
					} else {
						$target = $functionName;
					}
				} else {
					// a function body could be generated inside the object file for the inline function
					// need to indicate that the symbol is known 
					$target = "(void*) -1";
				}
				$symbols[$functionName] = $target;
			}
		}
		ksort($symbols);
		
		if($this->compiler == "MSVC") {
			// floor() is not constant in MSVC when intrinsic are used
			// it therefore cannot be used as an initializer
			// need it get the address at runtime instead
			$symbols["floor"] = "(void*) -1";
		}
	
		// print out prototypes of intrinsics
		foreach($intrinsic as $line) {
			fwrite($handle, "$line\n");
		}
		fwrite($handle, "\n");
		
		fwrite($handle, "qb_native_symbol global_native_symbols[] = {\n");
		foreach($symbols as $name => $symbol) {
			fwrite($handle, "	{	0,	\"$name\",	$symbol	},\n");
		}
		fwrite($handle, "};\n\n");
		$count = count($symbols);
		fwrite($handle, "uint32_t global_native_symbol_count = $count;\n\n");
	}

	public function writeNativeDebugStub($handle) {
		fwrite($handle, "#if NATIVE_COMPILE_ENABLED && ZEND_DEBUG\n");
		fwrite($handle, "#include \"qb_native_proc_debug.c\"\n");
		fwrite($handle, "#ifdef HAVE_NATIVE_PROC_RECORDS\n");
		fwrite($handle, "qb_native_proc_record *native_proc_table = native_proc_records;\n");
		fwrite($handle, "uint32_t native_proc_table_size = sizeof(native_proc_records) / sizeof(qb_native_proc_record);\n");
		fwrite($handle, "#else\n");
		fwrite($handle, "qb_native_proc_record *native_proc_table = NULL;\n");
		fwrite($handle, "uint32_t native_proc_table_size = 0;\n");
		fwrite($handle, "#endif\n");
		fwrite($handle, "#endif\n");
	}

	protected function setCompiler($compiler) {
		QBHandler::setCompiler($compiler);
		$this->compiler = $compiler;
	}
	
	protected function getFunctionDefinitions() {
		// add helper functions first
		$helperFunctions = array();
		foreach($this->handlers as $handler) {
			$helpers = $handler->getHelperFunctions();
			if($helpers) {
				foreach($helpers as $helper) {
					$line1 = $helper[0];
					if(!isset($helperFunctions[$line1])) {
						$helperFunctions[$line1] = $helper;
					}
				}
			}
		}
		ksort($helperFunctions);

		// add handler functions
		$handlerFunctions = array();
		foreach($this->handlers as $handler) {
			$function = $handler->getFunctionDefinition();
			if($function) {
				$line1 = $function[0];
				if(!isset($handlerFunctions[$line1])) {
					$handlerFunctions[$line1] = $function;
				}
			}
		}
		ksort($handlerFunctions);
		
		return array_merge(array_values($helperFunctions), array_values($handlerFunctions));
	}
	
	protected function getFunctionDeclarations() {
		$functionDeclarations = array();
		
		// add the generated function first
		$functionDefinitions = $this->getFunctionDefinitions();
		
		// then prepend the list with ones that aren't generated
		$folder = dirname(__FILE__);
		$common = file("$folder/function_prototypes.txt", FILE_IGNORE_NEW_LINES);
		$compilerSpecific = file(($this->compiler == "MSVC") ? "$folder/function_prototypes_msvc.txt" : "$folder/function_prototypes_gcc.txt", FILE_IGNORE_NEW_LINES);
		foreach(array_merge($common, $compilerSpecific) as $line) {
			array_unshift($functionDefinitions, array($line));
		}						

		// declare non-inlined functions first
		$inlinePattern = '/\b(inline|zend_always_inline)\b/';
		foreach($functionDefinitions as $lines) {
			$line1 = $lines[0];
			if(!preg_match($inlinePattern, $line1)) {
				// just the prototype--replace { and everything after it with ;
				$prototype = preg_replace('/\s*{.*$/', ';', $line1);					
				$functionDeclarations[] = $prototype;
			}
		}
		// then inlined functions
		foreach($functionDefinitions as $lines) {
			$line1 = $lines[0];
			if(preg_match($inlinePattern, $line1)) {
				// need all the lines
				$functionDeclarations[] = $this->formatCode($lines);
			}
		}
		return $functionDeclarations;
	}

	protected function getStructureDefinitions() {
		$structs = array();
		foreach($this->handlers as $handler) {
			$struct = $handler->getInstructionStructureDefinition();
			$line1 = $struct[0];
			if(!isset($structs[$line1])) {
				$structs[$line1] = $struct;
			}
		}
		ksort($structs);
		return array_values($structs);
	}

	protected function writeCode($handle, $lines) {
		foreach($lines as $line) {
			if($line !== null) {
				if(is_array($line)) {
					$this->writeCode($handle, $line);
				} else {
					$this->currentIndentationLevel -= substr_count($line, "}");
					if(!preg_match('/^#/', $line)) {
						$line = str_repeat("\t", $this->currentIndentationLevel) . $line;
					}
					fwrite($handle, "$line\n");
					if($this->currentIndentationLevel < 0) {
						throw new Exception("There's a problem with the code indentation\n");
					}
					$this->currentIndentationLevel += substr_count($line, "{");
				}
			}
		}
	}
	
	protected function formatCode($lines) {
		if(is_array($lines)) {
			$this->currentIndentationLevel = 0;
			$code = $this->formatLines($lines);
			return $code;
		} else {
			return "$lines\n";
		}
	}
	
	protected function formatLines($lines) {
		$code = "";
		foreach($lines as $line) {
			if($line !== null) {
				if(is_array($line)) {
					$code .= $this->formatLines($line);
				} else {
					$closeBracketCount = substr_count($line, "}");
					$openBracketCount = substr_count($line, "{");
					if($closeBracketCount == $openBracketCount) {
						$closeBracketCount = $openBracketCount = 0;
					}
					$this->currentIndentationLevel -= $closeBracketCount;
					if(!preg_match('/^#/', $line)) {
						$line = str_repeat("\t", $this->currentIndentationLevel) . $line;
					}
					$code .= "$line\n";
					if($this->currentIndentationLevel < 0) {
						throw new Exception("There's a problem with the code indentation\n");
					}
					$this->currentIndentationLevel += $openBracketCount;
				}
			}
		}
		return $code;
	}

	protected function writeCompressTable($handle, $name, $table, $deflate, $includeZeroTerminator) {
		// find out to which indices each item belong (multiple associations are expected)
		$refTable = array();
		$maxIndex = 0;
		foreach($table as $index => $item) {
			$refTable[$item][] = $index;
			if($index > $maxIndex) {
				$maxIndex = $index;
			}
		}
		
		// pack the records
		$records = array();	
		$totalDataLength = 0;
		foreach($refTable as $data => $indices) {
			$indexCount = count($indices);
			$dataLength = strlen($data);
			if($includeZeroTerminator) {
				$dataLength++;
				$data .= "\0";
			}
			// little endian 32-bit  integer
			$record = pack("V", $indexCount);
			foreach($indices as $index) {
				$record .= pack("V", $index);
			}
			$record .= pack("V", $dataLength);
			$record .= $data;
			$records[] = $record;
			$totalDataLength += $dataLength;
		}
		$data = implode("", $records);
		$uncompressedLength = strlen($data);
		if($deflate) {
			$compressedData = gzdeflate($data, 9);
			$compressedLength = strlen($compressedData);
			if((double) $compressedLength / $uncompressedLength < 0.75) {
				// use the deflated version only if the compression ratio is better than 75%
				$data = $compressedData;
			} else {
				$compressedLength = $uncompressedLength;
			}
		} else {
			$compressedLength = $uncompressedLength;
		}
		$itemCount = $maxIndex + 1;
		$tableData = pack("V*", $compressedLength, $uncompressedLength, $totalDataLength, $itemCount) . $data;
		
		$len = strlen($tableData) + 1;
		fwrite($handle, "const char {$name}[{$len}] = \"");
		for($i = 0, $len = strlen($tableData); $i < $len; $i++) {
			fwrite($handle, sprintf("\x%02X", ord($tableData[$i])));
		}
		fwrite($handle, "\";\n");
	}
	
	protected function addHandlers() {
		foreach($this->elementTypes as $elementType) {
			$this->addFlowControlHandlers($elementType);
			$this->addArithmeticHandlers($elementType);
			$this->addAssignmentHandlers($elementType);
			$this->addIncrementDecrementHandlers($elementType);
			$this->addComparisonHandlers($elementType);
			$this->addBitwiseHandlers($elementType);
			$this->addLogicalHandlers($elementType);
			$this->addCastHandlers($elementType);
			$this->addMathHandlers($elementType);
			$this->addStringHandlers($elementType);
			$this->addArrayHandlers($elementType);
			$this->addSamplingHandlers($elementType);
			$this->addMatrixHandlers($elementType);
			$this->addComplexNumberHandlers($elementType);
		}
		$this->addDebugHandlers();
	}

	protected function addArithmeticHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBAddHandler("ADD", $elementTypeNoSign, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSubtractHandler("SUB", $elementTypeNoSign, $addressMode);
			}
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, $addressMode);
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, $addressMode);
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, $addressMode);
		}
		foreach($this->addressModes as $addressMode) {
			$width = (int) substr($elementType, 1);
			if($width >= 32) {
				$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, $addressMode);
			}
		}
		if($float) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBFlooredDivisionModuloHandler("MOD_FLR", $elementType, $addressMode);
			}		
		}
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBNegateHandler("NEG", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addAssignmentHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBCopyHandler("MOV", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addIncrementDecrementHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBIncrementHandler("INC", $elementTypeNoSign, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDecrementHandler("DEC", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addComparisonHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBEqualHandler("EQ", $elementTypeNoSign, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBNotEqualHandler("NE", $elementTypeNoSign, $addressMode);
			}
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBLessThanHandler("LT", $elementType, $addressMode);
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBLessThanOrEqualHandler("LE", $elementType, $addressMode);
		}
		if(!$unsigned) {
			$this->handlers[] = new QBEqualVectorHandler("EQ_SET", $elementTypeNoSign);
			$this->handlers[] = new QBNotEqualVectorHandler("NE_SET", $elementTypeNoSign);
		}
		$this->handlers[] = new QBLessThanVectorHandler("LT_SET", $elementType);
		$this->handlers[] = new QBLessThanOrEqualVectorHandler("LE_SET", $elementType);
		if($elementTypeNoSign == "I32") {
			$this->handlers[] = new QBNotVectorHandler("NOT_SET", $elementTypeNoSign);
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBAnyHandler("ANY", $elementTypeNoSign, $addressMode);
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBAllHandler("ALL", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addBitwiseHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		if(!$float) {
			$unsigned = preg_match('/^U/', $elementType);
			$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
			if(!$unsigned) {
				foreach($this->addressModes as $addressMode) {
					$this->handlers[] = new QBBitwiseAndHandler("BW_AND", $elementTypeNoSign, $addressMode);
				}
				foreach($this->addressModes as $addressMode) {
					$this->handlers[] = new QBBitwiseOrHandler("BW_OR", $elementTypeNoSign, $addressMode);
				}
				foreach($this->addressModes as $addressMode) {
					$this->handlers[] = new QBBitwiseXorHandler("BW_XOR", $elementTypeNoSign, $addressMode);
				}
				foreach($this->addressModes as $addressMode) {
					$this->handlers[] = new QBBitwiseNotHandler("BW_NOT", $elementTypeNoSign, $addressMode);
				}
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBShiftLeftHandler("SHL", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBShiftRightHandler("SHR", $elementType, $addressMode);
			}
		}
	}

	protected function addLogicalHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!$unsigned) {
			if($elementTypeNoSign == "I32") {
				$this->handlers[] = new QBLogicalAndHandler("AND", $elementTypeNoSign, "VAR");
				$this->handlers[] = new QBLogicalOrHandler("OR", $elementTypeNoSign, "VAR");
				$this->handlers[] = new QBLogicalXorHandler("XOR", $elementTypeNoSign, "VAR");
				$this->handlers[] = new QBLogicalNotHandler("NOT", $elementTypeNoSign, "VAR");
			}
			$this->handlers[] = new QBIssetHandler("ISSET", $elementTypeNoSign, "ELV");
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBUnsetHandler("UNSET", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addCastHandlers($elementType) {
		$elementUnsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		foreach($this->elementTypes as $destElementType) {
			$destUnsigned = preg_match('/^U/', $destElementType);
			$destTypeNoSign = preg_replace('/^S/', "I", $destElementType);
			if($elementType != $destElementType && $elementTypeNoSign != $destTypeNoSign) {
				$float = preg_match('/^F/', $elementType) || preg_match('/^F/', $destElementType);
				$promotion = (int) substr($elementType, 1) < (int) substr($destTypeNoSign, 1);
				if($float) {
					// sign matters when floating points are involved
					foreach($this->addressModes as $addressMode) {
						$this->handlers[] = new QBCastHandler("MOV", $elementType, $destElementType, $addressMode);
					}
				} else if($promotion && !$destUnsigned) {
					// sign matters when promoting from lower-rank integer type to higher-rank type
					foreach($this->addressModes as $addressMode) {
						$this->handlers[] = new QBCastHandler("MOV", $elementType, $destTypeNoSign, $addressMode);
					}
				} else if(!$elementUnsigned && !$destUnsigned) {
					// sign doesn't matter
					foreach($this->addressModes as $addressMode) {
						$this->handlers[] = new QBCastHandler("MOV", $elementTypeNoSign, $destTypeNoSign, $addressMode);
					}
				}
			}
		}
		if(!$elementUnsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBBooleanCastHandler("BOOL", $elementTypeNoSign, $addressMode);
			}
		}
	}

	protected function addMathHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		$elementUnsigned = preg_match('/^U/', $elementType);
		if(!$elementUnsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBAbsHandler("ABS", $elementType, $addressMode);
			}
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBMinHandler("MIN", $elementType, $addressMode);
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBMaxHandler("MAX", $elementType, $addressMode);
		}
		if($float) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSinHandler("SIN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBASinHandler("ASIN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBCosHandler("COS", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBACosHandler("ACOS", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBTanHandler("TAN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBATanHandler("ATAN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBATan2Handler("ATAN2", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSinhHandler("SINH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBASinhHandler("ASINH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBCoshHandler("COSH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBACoshHandler("ACOSH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBTanhHandler("TANH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBATanhHandler("ATANH", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBCeilHandler("CEIL", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBFloorHandler("FLOOR", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBRoundHandler("ROUND", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLogHandler("LOG", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLog1PHandler("LOG1P", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLog2Handler("LOG2", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLog10Handler("LOG10", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBExpHandler("EXP", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBExpM1Handler("EXPM1", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBExp2Handler("EXP2", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBPowHandler("POW", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSqrtHandler("SQRT", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBHypotHandler("HYPOT", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLCGHandler("LCG", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBIsFiniteHandler("FIN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBIsInfiniteHandler("INF", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBIsNaNHandler("NAN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBRSqrtHandler("RSQRT", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBClampHandler("CLAMP", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBFractHandler("FRACT", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBMixHandler("MIX", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSignHandler("SIGN", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBStepHandler("STEP", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSmoothStepHandler("SSTEP", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBRadianToDegreeHandler("RAD2DEG", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDegreeToRadianHandler("DEG2RAD", $elementType, $addressMode);
			}
		} else {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBRandomHandler("RAND", $elementType, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBRandomMTHandler("MT_RAND", $elementType, $addressMode);
			}
		}
	}

	protected function addFlowControlHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		if(!in_array("QBJumpHandler", array_map("get_class", $this->handlers))) {
			$this->handlers[] = new QBNOPHandler("NOP");
			$this->handlers[] = new QBJumpHandler("JMP");
			$this->handlers[] = new QBReturnHandler("RET");
			$this->handlers[] = new QBExitHandler("EXIT");
			$this->handlers[] = new QBFunctionCallHandler("FCALL", "VAR");
			$this->handlers[] = new QBFunctionCallHandler("FCALL", "MIX");
			$this->handlers[] = new QBBranchIfStaticVariablesInitializedHandler("IF_INIT");
		}
		$branchHandlers = array();
		if(!$unsigned) {
			if($elementTypeNoSign == "I32") {
				foreach($this->scalarAddressModes as $addressMode) {
					$this->handlers[] = new QBBranchOnTrueHandler("IF_T", $elementTypeNoSign, $addressMode);
				}
				foreach($this->scalarAddressModes as $addressMode) {
					$this->handlers[] = new QBBranchOnFalseHandler("IF_F", $elementTypeNoSign, $addressMode);
				}
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBBranchOnEqualHandler("IF_EQ", $elementTypeNoSign, $addressMode);
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBBranchOnNotEqualHandler("IF_NE", $elementTypeNoSign, $addressMode);
			}
		}
		foreach($this->scalarAddressModes as $addressMode) {
			$this->handlers[] = new QBBranchOnLessThanHandler("IF_LT", $elementType, $addressMode);
		}
		foreach($this->scalarAddressModes as $addressMode) {
			$this->handlers[] = new QBBranchOnGreaterThanHandler("IF_GT", $elementType, $addressMode);
		}
		foreach($this->scalarAddressModes as $addressMode) {
			$this->handlers[] = new QBBranchOnLessThanOrEqualHandler("IF_LE", $elementType, $addressMode);
		}
		foreach($this->scalarAddressModes as $addressMode) {
			$this->handlers[] = new QBBranchOnGreaterThanOrEqualHandler("IF_GE", $elementType, $addressMode);
		}
	}

	protected function addStringHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		$float = preg_match('/^F/', $elementType);
		if($elementType == "U08") {
			$this->handlers[] = new QBPrintStringHandler("PRN_STR", $elementType);
			$this->handlers[] = new QBConcatStringHandler("CAT_STR", $elementType);
			
		}
		if($elementType == "U16" || $elementType == "U32") {
			$this->handlers[] = new QBUTF8DecodeHandler("UTF8_DEC", $elementType);
			$this->handlers[] = new QBUTF8EncodeHandler("UTF8_ENC", $elementType);
		}
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBPrintVariableHandler("PRN", $elementType, $addressMode);
		}
		$this->handlers[] = new QBPrintMultidimensionalVariableHandler("PRN_DIM", $elementType);
		foreach($this->addressModes as $addressMode) {
			$this->handlers[] = new QBConcatVariableHandler("CAT", $elementType, $addressMode);
		}
		$this->handlers[] = new QBConcatMultidimensionalVariableHandler("CAT_DIM", $elementType);
		
		if(!$unsigned && $elementTypeNoSign != "I08") {
			foreach($this->scalarAddressModes as $addressMode) {		
				$this->handlers[] = new QBPackHandler("PACK_LE", $elementTypeNoSign, $addressMode, "LE");
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBPackHandler("PACK_BE", $elementTypeNoSign, $addressMode, "BE");
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBUnpackHandler("UNPACK_LE", $elementTypeNoSign, $addressMode, "LE");
			}
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBUnpackHandler("UNPACK_BE", $elementTypeNoSign, $addressMode, "BE");
			} 
		}
	}
	
	protected function addArrayHandlers($elementType) {
		$unsigned = preg_match('/^U/', $elementType);
		$elementTypeNoSign = preg_replace('/^S/', "I", $elementType);
		$this->handlers[] = new QBSortHandler("SORT", $elementType);
		$this->handlers[] = new QBReverseSortHandler("RSORT", $elementType);
		foreach($this->scalarAddressModes as $addressMode) {
			$this->handlers[] = new QBArrayMinHandler("AMIN", $elementType, $addressMode);
			$this->handlers[] = new QBArrayMaxHandler("AMAX", $elementType, $addressMode);
			$this->handlers[] = new QBArrayProductHandler("APROD", $elementType, $addressMode);
			$this->handlers[] = new QBArraySumHandler("ASUM", $elementType, $addressMode);
			$this->handlers[] = new QBRangeHandler("RANGE", $elementType, $addressMode);
		}
		if(!$unsigned) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBArraySearchHandler("AFIND_IDX", $elementTypeNoSign, $addressMode);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBInArrayHandler("AFIND", $elementTypeNoSign, $addressMode);
			}
			$this->handlers[] = new QBSubarrayPositionHandler("APOS", $elementTypeNoSign);
			$this->handlers[] = new QBSubarrayPositionFromEndHandler("ARPOS", $elementTypeNoSign);
			$this->handlers[] = new QBArrayReverseHandler("AREV", $elementTypeNoSign);
			$this->handlers[] = new QBArrayInsertHandler("AINS", $elementTypeNoSign);
			$this->handlers[] = new QBArrayUniqueHandler("AUNIQ", $elementTypeNoSign);
			$this->handlers[] = new QBArrayColumnHandler("ACOL", $elementTypeNoSign);
			$this->handlers[] = new QBArrayDifferenceHandler("ADIFF", $elementTypeNoSign);
			$this->handlers[] = new QBArrayIntersectHandler("AISECT", $elementTypeNoSign);
			$this->handlers[] = new QBShuffleHandler("SHUFFLE", $elementTypeNoSign);
			$this->handlers[] = new QBArrayResizeHandler("ARESIZE", $elementTypeNoSign);
			$this->handlers[] = new QBArrayPadHandler("APAD", $elementTypeNoSign);
		}
		if($elementType == 'U32') {
			foreach($this->scalarAddressModes as $addressMode) {
				$this->handlers[] = new QBArrayRandomHandler("ARAND", null, $addressMode);
			}
		}
	}
	
	protected function addSamplingHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		if($float) {
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSampleNearestHandler("SAMPLE_NN", $elementType, $addressMode, 4);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSampleNearestHandler("SAMPLE_NN", $elementType, $addressMode, 3);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSampleBilinearHandler("SAMPLE_BL", $elementType, $addressMode, 4);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBSampleBilinearHandler("SAMPLE_BL", $elementType, $addressMode, 3);
			}
			$this->handlers[] = new QBAlphaBlendHandler("BLEND", $elementType);
			$this->handlers[] = new QBApplyPremultiplicationHandler("PREMULT", $elementType, null);
			$this->handlers[] = new QBApplyPremultiplicationHandler("PREMULT", $elementType, "ARR");
			$this->handlers[] = new QBRemovePremultiplicationHandler("UNPREMULT", $elementType, null);
			$this->handlers[] = new QBRemovePremultiplicationHandler("UNPREMULT", $elementType, "ARR");
			$this->handlers[] = new QBRGB2HSVHandler("RGB2HSV", $elementType, null, 3);
			$this->handlers[] = new QBRGB2HSVHandler("RGB2HSV", $elementType, "ARR", 3);
			$this->handlers[] = new QBRGB2HSVHandler("RGB2HSV", $elementType, null, 4);
			$this->handlers[] = new QBRGB2HSVHandler("RGB2HSV", $elementType, "ARR", 4);
			$this->handlers[] = new QBHSV2RGBHandler("HSV2RGB", $elementType, null, 3);
			$this->handlers[] = new QBHSV2RGBHandler("HSV2RGB", $elementType, "ARR", 3);
			$this->handlers[] = new QBHSV2RGBHandler("HSV2RGB", $elementType, null, 4);
			$this->handlers[] = new QBHSV2RGBHandler("HSV2RGB", $elementType, "ARR", 4);
			$this->handlers[] = new QBRGB2HSLHandler("RGB2HSL", $elementType, null, 3);
			$this->handlers[] = new QBRGB2HSLHandler("RGB2HSL", $elementType, "ARR", 3);
			$this->handlers[] = new QBRGB2HSLHandler("RGB2HSL", $elementType, null, 4);
			$this->handlers[] = new QBRGB2HSLHandler("RGB2HSL", $elementType, "ARR", 4);
			$this->handlers[] = new QBHSL2RGBHandler("HSL2RGB", $elementType, null, 3);
			$this->handlers[] = new QBHSL2RGBHandler("HSL2RGB", $elementType, "ARR", 3);
			$this->handlers[] = new QBHSL2RGBHandler("HSL2RGB", $elementType, null, 4);
			$this->handlers[] = new QBHSL2RGBHandler("HSL2RGB", $elementType, "ARR", 4);
		}
	}
	
	protected function addMatrixHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		if($float) {
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, null, 4, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, "ARR", 4, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, null, 4, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, "ARR", 4, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, null, 4, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, "ARR", 4, "column-major");
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, null, 4);
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, "ARR", 4);
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDeterminantHandler("MDET", $elementType, $addressMode, 4);
			}
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, null, 4);
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, "ARR", 4);
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDotProductHandler("DOT", $elementType, $addressMode, 4);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLengthHandler("LEN", $elementType, $addressMode, 4);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDistanceHandler("DIS", $elementType, $addressMode, 4);
			}
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, null, 4);
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, "ARR", 4);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, null, 4);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, "ARR", 4);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, null, 4);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, "ARR", 4);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, null, 4);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, "ARR", 4);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, null, 4);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, "ARR", 4);
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, null, 4, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, "ARR", 4, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, null, 4, "row-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, "ARR", 4, "row-major");
			
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, null, 4);
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, "ARR", 4);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, null, 4);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, "ARR", 4);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, null, 4);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, "ARR", 4);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, null, 4);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, "ARR", 4);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, null, 4);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, "ARR", 4);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, null, 4);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, "ARR", 4);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, null, 4);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, "ARR", 4);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, null, 4);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, "ARR", 4);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, null, 4);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, "ARR", 4);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, null, 4);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, "ARR", 4);

			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, null, 3, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, "ARR", 3, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, null, 3, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, "ARR", 3, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, null, 3, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, "ARR", 3, "column-major");
			if($elementType == "F32") {
				// these are used by Pixel Bender only
				$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, null, "3+P", "column-major");
				$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, null, "3+P", "column-major");
				$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, null, "3+P", "column-major");
			}
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, null, 3);
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, "ARR", 3);
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, null, 3);
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, "ARR", 3);
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDeterminantHandler("MDET", $elementType, $addressMode, 3);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDotProductHandler("DOT", $elementType, $addressMode, 3);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLengthHandler("LEN", $elementType, $addressMode, 3);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDistanceHandler("DIS", $elementType, $addressMode, 3);
			}
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, null, 3);
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, "ARR", 3);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, null, 3);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, "ARR", 3);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, null, 3);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, "ARR", 3);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, null, 3);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, "ARR", 3);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, null, 3);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, "ARR", 3);
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, null, 3, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, "ARR", 3, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, null, 3, "row-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, "ARR", 3, "row-major");
			
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, null, 3);
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, "ARR", 3);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, null, 3);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, "ARR", 3);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, null, 3);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, "ARR", 3);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, null, 3);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, "ARR", 3);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, null, 3);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, "ARR", 3);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, null, 3);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, "ARR", 3);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, null, 3);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, "ARR", 3);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, null, 3);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, "ARR", 3);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, null, 3);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, "ARR", 3);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, null, 3);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, "ARR", 3);
			
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, null, 2, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, "ARR", 2, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, null, 2, "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, "ARR", 2, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, null, 2, "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, "ARR", 2, "column-major");
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, null, 2);
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, "ARR", 2);
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, null, 2);
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, "ARR", 2);
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDeterminantHandler("MDET", $elementType, $addressMode, 2);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDotProductHandler("DOT", $elementType, $addressMode, 2);
			}
			foreach($this->addressModes as $addressMode) {			
				$this->handlers[] = new QBLengthHandler("LEN", $elementType, $addressMode, 2);
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDistanceHandler("DIS", $elementType, $addressMode, 2);
			}
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, null, 2);
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, "ARR", 2);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, null, 2);
			$this->handlers[] = new QBCrossProductHandler("CROSS", $elementType, "ARR", 2);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, null, 2);
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, "ARR", 2);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, null, 2);
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, "ARR", 2);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, null, 2);
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, "ARR", 2);
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, null, 2, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN", $elementType, "ARR", 2, "column-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, null, 2, "row-major");
			$this->handlers[] = new QBTransformVectorHandler("TRAN_RM", $elementType, "ARR", 2, "row-major");
			
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, null, 2);
			$this->handlers[] = new QBCopyHandler("MOV", $elementType, "ARR", 2);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, null, 2);
			$this->handlers[] = new QBAddHandler("ADD", $elementType, "ARR", 2);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, null, 2);
			$this->handlers[] = new QBSubtractHandler("SUB", $elementType, "ARR", 2);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, null, 2);
			$this->handlers[] = new QBMultiplyHandler("MUL", $elementType, "ARR", 2);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, null, 2);
			$this->handlers[] = new QBDivideHandler("DIV", $elementType, "ARR", 2);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, null, 2);
			$this->handlers[] = new QBModuloHandler("MOD", $elementType, "ARR", 2);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, null, 2);
			$this->handlers[] = new QBNegateHandler("NEG", $elementType, "ARR", 2);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, null, 2);
			$this->handlers[] = new QBIncrementHandler("INC", $elementType, "ARR", 2);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, null, 2);
			$this->handlers[] = new QBDecrementHandler("DEC", $elementType, "ARR", 2);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, null, 2);
			$this->handlers[] = new QBMultiplyAccumulateHandler("MAC", $elementType, "ARR", 2);
			
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, null, "variable", "column-major");
			$this->handlers[] = new QBMultiplyMatrixByMatrixHandler("MUL_MM", $elementType, "ARR", "variable", "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, null, "variable", "column-major");
			$this->handlers[] = new QBMultiplyMatrixByVectorHandler("MUL_MV", $elementType, "ARR", "variable", "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, null, "variable", "column-major");
			$this->handlers[] = new QBMultiplyVectorByMatrixHandler("MUL_VM", $elementType, "ARR", "variable", "column-major");
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, null, "variable");
			$this->handlers[] = new QBTransposeMatrixHandler("MTRAN", $elementType, "ARR", "variable");
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, null, "variable");
			$this->handlers[] = new QBInvertMatrixHandler("MINV", $elementType, "ARR", "variable");
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDeterminantHandler("MDET", $elementType, $addressMode, "variable");
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDotProductHandler("DOT", $elementType, $addressMode, "variable");
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBLengthHandler("LEN", $elementType, $addressMode, "variable");
			}
			foreach($this->addressModes as $addressMode) {
				$this->handlers[] = new QBDistanceHandler("DIS", $elementType, $addressMode, "variable");
			}
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, null, "variable");
			$this->handlers[] = new QBNormalizeHandler("NORM", $elementType, "ARR", "variable");
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, null, "variable");
			$this->handlers[] = new QBFaceForwardHandler("FORE", $elementType, "ARR", "variable");
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, null, "variable");
			$this->handlers[] = new QBReflectHandler("REFL", $elementType, "ARR", "variable");
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, null, "variable");
			$this->handlers[] = new QBRefractHandler("REFR", $elementType, "ARR", "variable");
		}
	}

	protected function addComplexNumberHandlers($elementType) {
		$float = preg_match('/^F/', $elementType);
		if($float) {
			$this->handlers[] = new QBComplexAbsHandler("CABS", $elementType, null);
			$this->handlers[] = new QBComplexAbsHandler("CABS", $elementType, "ARR");
			$this->handlers[] = new QBComplexArgumentHandler("CARG", $elementType, null);
			$this->handlers[] = new QBComplexArgumentHandler("CARG", $elementType, "ARR");
			$this->handlers[] = new QBComplexMultiplyHandler("CMUL", $elementType, null);
			$this->handlers[] = new QBComplexMultiplyHandler("CMUL", $elementType, "ARR");
			$this->handlers[] = new QBComplexDivideHandler("CDIV", $elementType, null);
			$this->handlers[] = new QBComplexDivideHandler("CDIV", $elementType, "ARR");
			$this->handlers[] = new QBComplexExpHandler("CEXP", $elementType, null);
			$this->handlers[] = new QBComplexExpHandler("CEXP", $elementType, "ARR");
			$this->handlers[] = new QBComplexLogHandler("CLOG", $elementType, null);
			$this->handlers[] = new QBComplexLogHandler("CLOG", $elementType, "ARR");
			$this->handlers[] = new QBComplexSquareRootHandler("CSQRT", $elementType, null);
			$this->handlers[] = new QBComplexSquareRootHandler("CSQRT", $elementType, "ARR");
			$this->handlers[] = new QBComplexPowHandler("CPOW", $elementType, null);
			$this->handlers[] = new QBComplexPowHandler("CPOW", $elementType, "ARR");
			
			$this->handlers[] = new QBComplexSinHandler("CSIN", $elementType, null);
			$this->handlers[] = new QBComplexSinHandler("CSIN", $elementType, "ARR");
			$this->handlers[] = new QBComplexCosHandler("CCOS", $elementType, null);
			$this->handlers[] = new QBComplexCosHandler("CCOS", $elementType, "ARR");
			$this->handlers[] = new QBComplexTanHandler("CTAN", $elementType, null);
			$this->handlers[] = new QBComplexTanHandler("CTAN", $elementType, "ARR");
			$this->handlers[] = new QBComplexSinhHandler("CSINH", $elementType, null);
			$this->handlers[] = new QBComplexSinhHandler("CSINH", $elementType, "ARR");
			$this->handlers[] = new QBComplexCoshHandler("CCOSH", $elementType, null);
			$this->handlers[] = new QBComplexCoshHandler("CCOSH", $elementType, "ARR");
			$this->handlers[] = new QBComplexTanhHandler("CTANH", $elementType, null);
			$this->handlers[] = new QBComplexTanhHandler("CTANH", $elementType, "ARR");
		}
	}
	
	protected function addDebugHandlers() {
		$this->handlers[] = new QBExtensionOpHandler("EXT");
	}
}

?>