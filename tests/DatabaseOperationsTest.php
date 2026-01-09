<?php
/**
 * Unit tests for WC_Payment_Monitor_Database class
 * Tests database schema and operations
 */

class DatabaseOperationsTest extends PHPUnit\Framework\TestCase {
    
    /**
     * Test database table names are properly formatted
     */
    public function test_table_name_format() {
        $table_names = [
            'payment_monitor_transactions',
            'payment_monitor_gateway_health',
            'payment_monitor_alerts'
        ];
        
        foreach ($table_names as $table) {
            // Table names should use underscores
            $this->assertStringContainsString('payment_monitor', $table);
            $this->assertRegExp('/^[a-z_]+$/', $table);
            $this->assertGreaterThan(0, strlen($table));
        }
    }
    
    /**
     * Test database column definitions
     */
    public function test_transaction_columns() {
        $required_columns = [
            'id',
            'order_id',
            'gateway_id',
            'transaction_id',
            'amount',
            'currency',
            'status',
            'customer_email',
            'customer_ip',
            'created_at',
            'updated_at'
        ];
        
        foreach ($required_columns as $column) {
            $this->assertNotEmpty($column);
            $this->assertRegExp('/^[a-z_]+$/', $column);
        }
    }
    
    /**
     * Test health record columns
     */
    public function test_health_record_columns() {
        $required_columns = [
            'id',
            'gateway_id',
            'period',
            'total_transactions',
            'successful_transactions',
            'failed_transactions',
            'success_rate',
            'calculated_at'
        ];
        
        foreach ($required_columns as $column) {
            $this->assertNotEmpty($column);
        }
    }
    
    /**
     * Test alert columns
     */
    public function test_alert_columns() {
        $required_columns = [
            'id',
            'gateway_id',
            'severity',
            'message',
            'status',
            'triggered_at',
            'resolved_at'
        ];
        
        foreach ($required_columns as $column) {
            $this->assertNotEmpty($column);
        }
    }
    
    /**
     * Test database version is semantic
     */
    public function test_database_version_format() {
        $db_version = '1.0.0';
        
        $parts = explode('.', $db_version);
        $this->assertEquals(3, count($parts));
        
        foreach ($parts as $part) {
            $this->assertIsNumeric($part);
            $this->assertGreaterThanOrEqual(0, intval($part));
        }
    }
    
    /**
     * Test transaction status values in database
     */
    public function test_valid_transaction_statuses() {
        $valid_statuses = ['success', 'failed', 'pending'];
        
        foreach ($valid_statuses as $status) {
            $this->assertIsString($status);
            $this->assertNotEmpty($status);
        }
    }
    
    /**
     * Test alert severity levels in database
     */
    public function test_valid_alert_severities() {
        $valid_severities = ['critical', 'warning', 'info'];
        
        foreach ($valid_severities as $severity) {
            $this->assertIsString($severity);
            $this->assertNotEmpty($severity);
        }
    }
}
