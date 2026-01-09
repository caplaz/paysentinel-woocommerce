<?php
/**
 * Minimal test to check if the issue is in TestLoggerTest structure
 */

require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';

class MinimalLoggerTest extends WC_Payment_Monitor_Test_Case {
    
    public function test_minimal() {
        $this->assertTrue(true);
    }
}
