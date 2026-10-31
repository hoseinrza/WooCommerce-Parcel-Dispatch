<?php

namespace WCPD\Tests\Integration;

use WCPD\Database\DatabaseInstaller;

/**
 * Runs database tests against the real (non-temporary) dispatch table so that
 * DDL and the UNIQUE constraint are exercised exactly as in production.
 */
abstract class DatabaseTestCase extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		( new DatabaseInstaller() )->install();
	}
}
