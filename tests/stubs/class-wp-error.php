<?php
/**
 * Minimal WP_Error for unit tests.
 *
 * @package RiverConditions
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting
class WP_Error {

	public function __construct( private string $code = '', private string $message = '' ) {}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}
