<?php

class QBDecrementHandler extends QBHandler {

	public function getInputOperandCount() {
		return 0;
	}

	protected function getActionOnUnitData() {
		return "--res;";
	}
}

?>