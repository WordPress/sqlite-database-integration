const assert = require( 'node:assert/strict' );
const { after, before, test } = require( 'node:test' );
const { Miniflare, convertV4MiniflareOptions } = require( 'miniflare' );

const runtime = new Miniflare( convertV4MiniflareOptions( {
	cf: false,
	modules: true,
	script: 'export default {}',
	compatibilityDate: '2026-11-19',
	d1Databases: [ 'DB' ],
} ) );

let db;

before( async () => {
	db = await runtime.getD1Database( 'DB' );
	await db.prepare(
		'CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, title TEXT NOT NULL)'
	).run();
} );

after( async () => {
	await runtime.dispose();
} );

test( 'prepared statements preserve post content and report insert metadata', async () => {
	const title = "WordPress post with an apostrophe: it's published";
	const result = await db.prepare( 'INSERT INTO posts (slug, title) VALUES (?, ?)' )
		.bind( 'published', title )
		.run();

	assert.equal( result.meta.changes, 1 );
	assert.ok( result.meta.last_row_id > 0 );
	assert.deepEqual(
		await db.prepare( 'SELECT slug, title FROM posts WHERE id = ?' ).bind( result.meta.last_row_id ).first(),
		{ slug: 'published', title }
	);
} );

test( 'a failed D1 batch rolls back earlier statements', async () => {
	const insert = db.prepare( 'INSERT INTO posts (slug, title) VALUES (?, ?)' );

	await assert.rejects(
		db.batch( [ insert.bind( 'duplicate', 'First write' ), insert.bind( 'duplicate', 'Second write' ) ] ),
		/UNIQUE constraint failed/i
	);
	assert.equal(
		await db.prepare( 'SELECT title FROM posts WHERE slug = ?' ).bind( 'duplicate' ).first(),
		null
	);
} );

test( 'an atomic batch applies a post change and reads it back', async () => {
	const [ write, read ] = await db.batch( [
		db.prepare( 'INSERT INTO posts (slug, title) VALUES (?, ?)' ).bind( 'batched-post', 'Batch write' ),
		db.prepare( 'SELECT title FROM posts WHERE slug = ?' ).bind( 'batched-post' ),
	] );

	assert.equal( write.meta.changes, 1 );
	assert.deepEqual( read.results, [ { title: 'Batch write' } ] );
} );

test( 'D1 rejects the transaction commands used by the local PDO driver', async () => {
	await assert.rejects( db.prepare( 'BEGIN IMMEDIATE' ).run(), /transaction/i );
	await assert.rejects( db.prepare( 'SAVEPOINT nested' ).run(), /transaction|savepoint/i );
} );

test( 'D1 lacks the MD5 function registered by the local PDO driver', async () => {
	await assert.rejects(
		db.prepare( 'SELECT md5(?)' ).bind( 'sample' ).run(),
		/no such function: md5/i
	);
} );
