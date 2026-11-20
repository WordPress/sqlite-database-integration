<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the D1 connection and its statement wrapper.
 */
class WP_D1_Connection_Tests extends TestCase {
	public function testQueryExecutesBeforeReturning(): void {
		$sql        = 'INSERT INTO posts (title) VALUES (?)';
		$connection = $this->mock_connection();
		$connection->expects( $this->once() )
			->method( 'execute_remote' )
			->with( $sql, array( 'Title' ) )
			->willReturn( array( 'meta' => array( 'changes' => 1 ) ) );

		$statement = $connection->query( $sql, array( 'Title' ) );
		$this->assertSame( 1, $statement->rowCount() );
	}

	public function testBindingsAreSentInPlaceholderOrder(): void {
		$sql        = 'INSERT INTO posts (title, slug) VALUES (?, ?)';
		$connection = $this->mock_connection();
		$connection->expects( $this->once() )
			->method( 'execute_remote' )
			->with( $sql, array( 'Title', 'slug' ) )
			->willReturn( array() );

		$statement = $connection->prepare( $sql );
		$this->assertSame( 0, $statement->rowCount() );
		$statement->bind( '2', 'slug' );
		$statement->bindValue( 1, 'Title' );
		$this->assertTrue( $statement->execute() );
	}

	public function testExecutePreservesExplicitParameterOrder(): void {
		$sql        = 'INSERT INTO posts (title, slug) VALUES (?, ?)';
		$connection = $this->mock_connection();
		$connection->expects( $this->once() )
			->method( 'execute_remote' )
			->with( $sql, array( 'Title', 'slug' ) )
			->willReturn( array() );

		$statement = $connection->prepare( $sql );
		$statement->bindValue( 2, 'old-slug' );
		$this->assertTrue( $statement->execute( array( 'Title', 'slug' ) ) );
	}

	public function testSparseBindingsAreRejectedBeforeSending(): void {
		$connection = $this->mock_connection();
		$connection->expects( $this->never() )->method( 'execute_remote' );

		$statement = $connection->prepare( 'SELECT ?, ?' );
		$statement->bindValue( 2, 'second' );
		$this->expectException( InvalidArgumentException::class );
		$statement->execute();
	}

	public function testExplicitNullBindingSendsNull(): void {
		$connection = $this->mock_connection();
		$connection->expects( $this->once() )
			->method( 'execute_remote' )
			->with( 'SELECT ?', array( null ) )
			->willReturn( array() );

		$statement = $connection->prepare( 'SELECT ?' );
		$statement->bindValue( 1, 'unused', PDO::PARAM_NULL );
		$statement->execute();
	}

	public function testUnsupportedBindingTypeIsRejected(): void {
		$statement = $this->mock_connection()->prepare( 'SELECT ?' );
		$this->expectException( InvalidArgumentException::class );
		$statement->bindValue( 1, '123', PDO::PARAM_INT );
	}

	public function testFetchMethodsShareCursorAndExecutionResetsIt(): void {
		$connection = $this->mock_connection();
		$rows       = array(
			array( 'id' => 1 ),
			array( 'id' => 2 ),
			array( 'id' => 3 ),
		);
		$connection->expects( $this->exactly( 2 ) )
			->method( 'execute_remote' )
			->willReturn( array( 'results' => $rows ) );

		$statement = $connection->query( 'SELECT id FROM posts ORDER BY id' );
		$this->assertSame( 1, $statement->fetchColumn() );
		$this->assertSame( 2, $statement->fetchColumn() );
		$this->assertSame( array( $rows[2] ), $statement->fetchAll() );
		$this->assertFalse( $statement->fetchColumn() );
		$this->assertSame( array(), $statement->fetchAll() );
		$this->assertSame( 1, $statement->columnCount() );

		$statement->execute();
		$this->assertSame( 1, $statement->fetchColumn() );
	}

	public function testFailedExecutionClearsResultsWithoutRetrying(): void {
		$connection = $this->mock_connection();
		$connection->expects( $this->exactly( 2 ) )
			->method( 'execute_remote' )
			->willReturnOnConsecutiveCalls(
				array(
					'results' => array( array( 'id' => 1 ) ),
					'meta'    => array( 'changes' => 1 ),
				),
				$this->throwException( new RuntimeException( 'D1 request failed' ) )
			);

		$statement = $connection->query( 'SELECT 1 AS id' );
		$this->assertSame( 1, $statement->columnCount() );
		try {
			$statement->execute();
			$this->fail( 'Expected the second execution to fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'D1 request failed', $error->getMessage() );
		}
		$this->assertSame( 0, $statement->columnCount() );
		$this->assertSame( 0, $statement->rowCount() );
		$this->assertFalse( $statement->fetchColumn() );
		$this->assertSame( array(), $statement->fetchAll() );
	}

	public function testColumnCountDoesNotExecutePreparedStatement(): void {
		$connection = $this->mock_connection();
		$connection->expects( $this->never() )->method( 'execute_remote' );

		$statement = $connection->prepare( 'INSERT INTO posts (title) VALUES (?)' );
		$statement->bindValue( 1, 'Title' );
		$this->assertSame( 0, $statement->columnCount() );
	}

	public function testFetchAllSupportsExplicitModes(): void {
		$connection = $this->mock_connection();
		$rows       = array(
			array( 'id' => 1 ),
			array( 'id' => null ),
			array( 'id' => 3 ),
		);
		$connection->method( 'execute_remote' )->willReturn( array( 'results' => $rows ) );

		$statement = $connection->query( 'SELECT id FROM posts' );
		$this->assertSame( 1, $statement->fetchColumn() );
		$this->assertSame( array( null, 3 ), $statement->fetchAll( PDO::FETCH_COLUMN ) );
		$this->assertSame( array(), $statement->fetchAll( PDO::FETCH_ASSOC ) );
		$statement->execute();
		$this->assertSame( $rows, $statement->fetchAll( PDO::FETCH_ASSOC ) );
	}

	public function testFetchAllRejectsUnsupportedModeBeforeExecuting(): void {
		$connection = $this->mock_connection();
		$connection->expects( $this->never() )->method( 'execute_remote' );

		$statement = $connection->prepare( 'SELECT 1' );
		$this->expectException( InvalidArgumentException::class );
		$statement->fetchAll( PDO::FETCH_OBJ );
	}

	public function testColumnCountUsesMetadataForEmptyResults(): void {
		$connection = $this->mock_connection();
		$connection->method( 'execute_remote' )->willReturn(
			array(
				'column_count' => 1,
				'results'      => array(),
			)
		);

		$statement = $connection->query( 'SELECT 1 AS id WHERE 0' );
		$this->assertSame( 1, $statement->columnCount() );
		$this->assertSame( array(), $statement->fetchAll() );
	}

	private function mock_connection(): WP_D1_Connection {
		return $this->getMockBuilder( WP_D1_Connection::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute_remote' ) )
			->getMock();
	}
}
