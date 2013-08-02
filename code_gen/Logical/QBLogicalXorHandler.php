<?php

class QBLogicalXorHandler extends QBHandler {

	public function getInputOperandCount() {
		return 2;
	}

	public function getOperandAddressMode($i) {
		return ($i == 3) ? "VAR" : $this->addressMode;
	}

	protected function getActionOnUnitData() {
		return "res = !op1 != !op2;";
	}
}

?>