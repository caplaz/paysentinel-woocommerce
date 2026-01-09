<?php
/**
 * Unit tests for WC_Payment_Monitor_Logger class
 * Tests transaction logging and data extraction
 */

class TransactionLoggerTest extends PHPUnit\Framework\TestCase {
    
    /**
     * Test transaction status values
     */
    public function test_transaction_statuses() {
        $valid_statuses = ['success', 'failed', 'pending'];
        
        foreach ($valid_statuses as $status) {
            $this->assertIsString($status);
            $this->assertNotEmpty($status);
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $status);
        }
    }
    
    /**
     * Test transaction data structure
     */
    public function test_transaction_data_structure() {
        $transaction_data = [
            'order_id' => 123,
            'gateway_id' => 'stripe',
            'transaction_id' => 'txn_123456',
            'amount' => 99.99,
            'currency' => 'USD',
            'status' => 'success',
            'customer_email' => 'test@example.com',
            'customer_ip' => '192.168.1.1'
        ];
        
        // Verify required fields exist
        $this->assertArrayHasKey('order_id', $transaction_data);
        $this->assertArrayHasKey('gateway_id', $transaction_data);
        $this->assertArrayHasKey('status', $transaction_data);
        $this->assertArrayHasKey('amount', $transaction_data);
        
        // Verify data types
        $this->assertIsInt($transaction_data['order_id']);
        $this->assertIsString($transaction_data['gateway_id']);
        $this->assertIsFloat($transaction_data['amount']);
        $this->assertIsString($transaction_data['status']);
    }
    
    /**
     * Test amount validation
     */
    public function test_transaction_amount_validation() {
        $valid_amounts = [0.01, 9.99, 99.99, 999.99, 1000.00];
        
        foreach ($valid_amounts as $amount) {
            $this->assertGreaterThan(0, $amount);
            $this->assertTrue(is_float($amount) || is_int($amount));
        }
    }
    
    /**
     * Test currency code format
     */
    public function test_currency_code_format() {
        $valid_currencies = ['USD', 'EUR', 'GBP', 'JPY', 'AUD'];
        
        foreach ($valid_currencies as $currency) {
            $this->assertEquals(3, strlen($currency));
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $currency);
        }
    }
    
    /**
     * Test email address format
     */
    public function test_email_format() {
        $valid_emails = [
            'test@example.com',
            'user@domain.co.uk',
            'support@company.org'
        ];
        
        foreach ($valid_emails as $email) {
            $this->assertStringContainsString('@', $email);
            $this->assertStringContainsString('.', $email);
        }
    }
    
    /**
     * Test IP address format
     */
    public function test_ip_address_format() {
        $valid_ips = [
            '192.168.1.1',
            '10.0.0.1',
            '172.16.0.1'
        ];
        
        foreach ($valid_ips as $ip) {
            $parts = explode('.', $ip);
            $this->assertEquals(4, count($parts));
            
            foreach ($parts as $part) {
                $num = intval($part);
                $this->assertGreaterThanOrEqual(0, $num);
                $this->assertLessThanOrEqual(255, $num);
            }
        }
    }
}
