<?php
use PHPUnit\Framework\TestCase;
use DataMetric\Includes\Api;

class TestApi extends TestCase {

	protected function setUp(): void {
		// Mock encryption salt
		update_option( 'datametric_secure_salt', str_repeat( 's', 64 ) );
	}

	public function test_encryption_decryption() {
		$secret = '{"type": "service_account", "private_key": "mysecretkey"}';
		
		$encrypted = Api::encrypt_data( $secret );
		$this->assertNotEmpty( $encrypted );
		$this->assertNotEquals( $secret, $encrypted );

		$decrypted = Api::decrypt_data( $encrypted );
		$this->assertEquals( $secret, $decrypted );
	}

	public function test_empty_encryption() {
		$this->assertEquals( '', Api::encrypt_data( '' ) );
		$this->assertEquals( '', Api::decrypt_data( '' ) );
	}

	public function test_invalid_decryption() {
		$this->assertEquals( '', Api::decrypt_data( 'invalidbase64data' ) );
	}
}
