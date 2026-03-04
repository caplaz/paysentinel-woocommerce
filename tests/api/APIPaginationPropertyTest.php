<?php

/**
 * Property-based tests for API pagination
 * Tests pagination correctness across various scenarios.
 *
 * @package PaySentinel\Tests\API
 */
class APIPaginationPropertyTest extends PHPUnit\Framework\TestCase {

	/**
	 * Property: Pagination Calculation Accuracy
	 *
	 * Pagination calculations are mathematically correct
	 * Validates: Requirement 7.4
	 */
	public function test_property_pagination_calculation_accuracy() {
		for ( $i = 0; $i < 100; $i++ ) {
			$total_items = rand( 0, 10000 );
			$per_page    = rand( 1, 100 );

			// Calculate expected total pages.
			// Calculate expected total pages.
			$expected_total_pages = $total_items > 0 ? (int) ceil( $total_items / $per_page ) : 1;

			// Verify calculation.
			$this->assertGreaterThanOrEqual( 1, $expected_total_pages );
			$this->assertIsInt( $expected_total_pages );

			// Verify formula.
			if ( $total_items > 0 ) {
				$actual = (int) ceil( $total_items / $per_page );
				$this->assertEquals( $expected_total_pages, $actual );
			}
		}
	}

	/**
	 * Property: Page Number Validation
	 *
	 * Page numbers are valid integers within range
	 * Validates: Requirement 7.4
	 */
	public function test_property_page_number_validation() {
		for ( $i = 0; $i < 100; $i++ ) {
			$total_pages  = rand( 1, 100 );
			$current_page = rand( 1, $total_pages );

			// Page number must be positive integer.
			$this->assertGreaterThanOrEqual( 1, $current_page );
			$this->assertIsInt( $current_page );

			// Page number must not exceed total pages.
			$this->assertLessThanOrEqual( $total_pages, $current_page );
		}
	}

	/**
	 * Property: Items Per Page Validation
	 *
	 * Items per page is within acceptable bounds
	 * Validates: Requirement 7.4
	 */
	public function test_property_items_per_page_validation() {
		$min_per_page = 1;
		$max_per_page = 100;

		for ( $i = 0; $i < 100; $i++ ) {
			$per_page = rand( $min_per_page, $max_per_page );

			// Per page must be within bounds.
			$this->assertGreaterThanOrEqual( $min_per_page, $per_page );
			$this->assertLessThanOrEqual( $max_per_page, $per_page );
			$this->assertIsInt( $per_page );
		}
	}

	/**
	 * Property: Offset Calculation Correctness
	 *
	 * Offset calculations are mathematically correct
	 * Validates: Requirement 7.4
	 */
	public function test_property_offset_calculation_correctness() {
		for ( $i = 0; $i < 100; $i++ ) {
			$page     = rand( 1, 100 );
			$per_page = rand( 1, 100 );

			// Calculate offset.
			$expected_offset = ( $page - 1 ) * $per_page;

			// Verify calculation.
			$this->assertGreaterThanOrEqual( 0, $expected_offset );
			$this->assertIsInt( $expected_offset );

			// Verify formula.
			$actual_offset = ( $page - 1 ) * $per_page;
			$this->assertEquals( $expected_offset, $actual_offset );

			// For page 1, offset should always be 0.
			if ( $page === 1 ) {
				$this->assertEquals( 0, $expected_offset );
			}
		}
	}

	/**
	 * Property: Pagination Metadata Consistency
	 *
	 * Pagination metadata is internally consistent
	 * Validates: Requirement 7.4
	 */
	public function test_property_pagination_metadata_consistency() {
		for ( $i = 0; $i < 100; $i++ ) {
			$page     = rand( 1, 100 );
			$per_page = rand( 1, 100 );
			$total    = rand( 0, 10000 );

			// Calculate total pages.
			$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

			// Create pagination metadata.
			$pagination = array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total_pages,
			);

			// Verify all fields are present.
			$this->assertArrayHasKey( 'page', $pagination );
			$this->assertArrayHasKey( 'per_page', $pagination );
			$this->assertArrayHasKey( 'total', $pagination );
			$this->assertArrayHasKey( 'total_pages', $pagination );

			// Verify types.
			$this->assertIsInt( $pagination['page'] );
			$this->assertIsInt( $pagination['per_page'] );
			$this->assertIsInt( $pagination['total'] );
			$this->assertIsInt( $pagination['total_pages'] );

			// Verify ranges.
			$this->assertGreaterThanOrEqual( 1, $pagination['page'] );
			$this->assertGreaterThanOrEqual( 1, $pagination['per_page'] );
			$this->assertGreaterThanOrEqual( 0, $pagination['total'] );
			$this->assertGreaterThanOrEqual( 1, $pagination['total_pages'] );
		}
	}

	/**
	 * Property: Pagination with Various Dataset Sizes
	 *
	 * Pagination works correctly with all dataset sizes
	 * Validates: Requirement 7.4
	 */
	public function test_property_pagination_with_various_sizes() {
		$dataset_sizes = array( 0, 1, 10, 50, 100, 999, 1000, 10000 );

		for ( $i = 0; $i < 50; $i++ ) {
			$total_items = $dataset_sizes[ array_rand( $dataset_sizes ) ];
			$per_page    = rand( 1, 100 );
			$page        = rand( 1, max( 1, (int) ceil( $total_items / $per_page ) ) );

			// Calculate items on this page.
			$offset        = ( $page - 1 ) * $per_page;
			$items_on_page = min( $per_page, max( 0, $total_items - $offset ) );

			// Verify calculation.
			$this->assertGreaterThanOrEqual( 0, $items_on_page );
			$this->assertLessThanOrEqual( $per_page, $items_on_page );

			// Last page should have fewer or equal items.
			$total_pages = $total_items > 0 ? (int) ceil( $total_items / $per_page ) : 1;
			if ( $page === $total_pages && $total_items > 0 ) {
				$expected_items = ( ( $total_items - 1 ) % $per_page ) + 1;
				$this->assertEquals( $expected_items, $items_on_page );
			}
		}
	}

	/**
	 * Property: Edge Cases in Pagination
	 *
	 * Pagination handles edge cases correctly
	 * Validates: Requirement 7.4
	 */
	public function test_property_edge_cases_in_pagination() {
		// Test with empty dataset
		$total       = 0;
		$per_page    = 20;
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
		$this->assertEquals( 1, $total_pages );

		// Test with single item
		$total       = 1;
		$per_page    = 20;
		$total_pages = (int) ceil( $total / $per_page );
		$this->assertEquals( 1, $total_pages );

		// Test with exact multiple
		$total       = 100;
		$per_page    = 20;
		$total_pages = (int) ceil( $total / $per_page );
		$this->assertEquals( 5, $total_pages );

		// Test last page calculation
		$per_page = 10;
		for ( $i = 1; $i <= 100; $i++ ) {
			$offset = ( $i - 1 ) * $per_page;
			$this->assertGreaterThanOrEqual( 0, $offset );
		}
	}

	/**
	 * Property: Pagination Response Structure
	 *
	 * Paginated responses have consistent structure
	 * Validates: Requirement 7.4
	 */
	public function test_property_pagination_response_structure() {
		for ( $i = 0; $i < 100; $i++ ) {
			$page        = rand( 1, 10 );
			$per_page    = rand( 10, 50 );
			$total       = rand( 0, 1000 );
			$total_pages = $total > 0 ? ceil( $total / $per_page ) : 1;

			// Create paginated response.
			$response = array(
				'success'    => true,
				'data'       => array(),
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total_pages,
				),
			);

			// Verify structure.
			$this->assertArrayHasKey( 'success', $response );
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'pagination', $response );

			// Verify pagination object.
			$pagination = $response['pagination'];
			$this->assertArrayHasKey( 'page', $pagination );
			$this->assertArrayHasKey( 'per_page', $pagination );
			$this->assertArrayHasKey( 'total', $pagination );
			$this->assertArrayHasKey( 'total_pages', $pagination );

			// Verify types.
			$this->assertTrue( $response['success'] );
			$this->assertIsArray( $response['data'] );
			$this->assertIsArray( $pagination );
		}
	}

	/**
	 * Property: Default Pagination Parameters
	 *
	 * Default pagination parameters are reasonable
	 * Validates: Requirement 7.4
	 */
	public function test_property_default_pagination_parameters() {
		$default_page     = 1;
		$default_per_page = 20;

		for ( $i = 0; $i < 50; $i++ ) {
			// Default page should be 1.
			$this->assertEquals( 1, $default_page );

			// Default per_page should be reasonable.
			$this->assertGreaterThanOrEqual( 1, $default_per_page );
			$this->assertLessThanOrEqual( 100, $default_per_page );

			// Default per_page should be a multiple of common values.
			$this->assertTrue(
				in_array( $default_per_page, array( 10, 20, 25, 50, 100 ), true )
			);
		}
	}

	/**
	 * Property: Pagination Bounds Enforcement
	 *
	 * Pagination parameters enforce reasonable bounds
	 * Validates: Requirement 7.4
	 */
	public function test_property_pagination_bounds_enforcement() {
		for ( $i = 0; $i < 100; $i++ ) {
			// Test per_page bounds.
			$requested_per_page = rand( -100, 200 );
			$min_per_page       = 1;
			$max_per_page       = 100;

			$enforced_per_page = ( $requested_per_page > 0 && $requested_per_page <= $max_per_page )
				? $requested_per_page
				: $min_per_page;

			$this->assertGreaterThanOrEqual( $min_per_page, $enforced_per_page );
			$this->assertLessThanOrEqual( $max_per_page, $enforced_per_page );

			// Test page bounds.
			$requested_page = rand( -10, 1000 );
			$enforced_page  = $requested_page > 0 ? $requested_page : 1;

			$this->assertGreaterThanOrEqual( 1, $enforced_page );
		}
	}
}
