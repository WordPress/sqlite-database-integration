#!/usr/bin/env node

const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const workflowDir = path.join( root, '.github', 'workflows' );
const failures = [];

function readWorkflow( filename ) {
	return fs.readFileSync( path.join( workflowDir, filename ), 'utf8' );
}

function fail( filename, message ) {
	failures.push( `${ filename }: ${ message }` );
}

function getRemoteDefaultBranch() {
	for ( const ref of [ 'refs/remotes/upstream/HEAD', 'refs/remotes/origin/HEAD' ] ) {
		try {
			const output = execSync( `git symbolic-ref --quiet --short ${ ref }`, {
				cwd: root,
				encoding: 'utf8',
				stdio: [ 'ignore', 'pipe', 'ignore' ],
			} ).trim();

			if ( output.includes( '/' ) ) {
				return output.split( '/' ).pop();
			}
		} catch ( error ) {
			// Fall through to the next ref or static default below.
		}
	}

	return 'trunk';
}

const defaultBranch = process.env.DEFAULT_BRANCH || getRemoteDefaultBranch();

function getPushBranches( contents ) {
	const lines = contents.split( /\r?\n/ );
	const branches = [];

	for ( let i = 0; i < lines.length; i++ ) {
		if ( lines[ i ] !== '  push:' ) {
			continue;
		}

		for ( i++; i < lines.length; i++ ) {
			const line = lines[ i ];
			if ( line.startsWith( '  ' ) && ! line.startsWith( '    ' ) ) {
				break;
			}

			if ( line !== '    branches:' ) {
				continue;
			}

			for ( i++; i < lines.length; i++ ) {
				const branchLine = lines[ i ];
				if ( ! branchLine.startsWith( '      - ' ) ) {
					break;
				}
				branches.push( branchLine.slice( 8 ).trim().replace( /^['"]|['"]$/g, '' ) );
			}

			return branches;
		}
	}

	return branches;
}

function getJobBlock( contents, jobName ) {
	const lines = contents.split( /\r?\n/ );
	const start = lines.findIndex( ( line ) => line === `  ${ jobName }:` );
	if ( -1 === start ) {
		return '';
	}

	let end = lines.length;
	for ( let i = start + 1; i < lines.length; i++ ) {
		if ( /^  [A-Za-z0-9_-]+:$/.test( lines[ i ] ) ) {
			end = i;
			break;
		}
	}

	return lines.slice( start, end ).join( '\n' );
}

function assertNoContinueOnError( filename ) {
	const contents = readWorkflow( filename );
	if ( contents.includes( 'continue-on-error' ) ) {
		fail( filename, 'must not use continue-on-error; failing jobs should fail CI.' );
	}
}

function assertDefaultBranchPush( filename ) {
	const contents = readWorkflow( filename );
	const branches = getPushBranches( contents );
	if ( ! branches.includes( defaultBranch ) ) {
		fail( filename, `push.branches must include repository default branch "${ defaultBranch }".` );
	}

	for ( const branch of [ 'main', 'trunk' ] ) {
		if ( ! branches.includes( branch ) ) {
			fail( filename, `push.branches must include "${ branch }" to avoid fork/upstream default-branch skips.` );
		}
	}
}

function assertJobHasNoTopLevelIf( filename, jobName ) {
	const block = getJobBlock( readWorkflow( filename ), jobName );
	if ( ! block ) {
		fail( filename, `missing ${ jobName } job.` );
		return;
	}

	if ( /^    if:/m.test( block ) ) {
		fail( filename, `${ jobName } job must not have a job-level if gate.` );
	}
}

function assertIncludes( filename, needle, message ) {
	if ( ! readWorkflow( filename ).includes( needle ) ) {
		fail( filename, message );
	}
}

for ( const filename of fs.readdirSync( workflowDir ).filter( ( file ) => file.endsWith( '.yml' ) ) ) {
	assertNoContinueOnError( filename );
}

for ( const filename of [
	'phpunit-tests.yml',
	'wp-tests-phpunit.yml',
	'end-to-end-tests.yml',
	'wp-tests-end-to-end.yml',
] ) {
	assertDefaultBranchPush( filename );
}

assertIncludes(
	'phpunit-tests.yml',
	'testsuite: postgresql',
	'package PHPUnit matrix must include the PostgreSQL testsuite lane.'
);
assertIncludes(
	'phpunit-tests.yml',
	'--testsuite postgresql',
	'package PHPUnit workflow must run the PostgreSQL testsuite.'
);

assertJobHasNoTopLevelIf( 'wp-tests-phpunit.yml', 'postgresql-test' );
assertIncludes(
	'wp-tests-phpunit.yml',
	'WP_TEST_DB_BACKEND: postgresql',
	'WordPress PostgreSQL PHPUnit job must set WP_TEST_DB_BACKEND=postgresql.'
);
assertIncludes(
	'wp-tests-phpunit.yml',
	'run: node .github/workflows/wp-tests-phpunit-run.js',
	'WordPress PostgreSQL PHPUnit job must run the PHPUnit helper.'
);

for ( const [ filename, composerCommand ] of [
	[ 'end-to-end-tests.yml', 'composer run test-e2e' ],
	[ 'wp-tests-end-to-end.yml', 'composer run wp-test-e2e' ],
] ) {
	assertJobHasNoTopLevelIf( filename, 'test' );
	assertIncludes( filename, '  pull_request:', 'e2e workflow must run for pull requests.' );
	assertIncludes( filename, `run: ${ composerCommand }`, `e2e workflow must run ${ composerCommand }.` );
}

const progressBlock = getJobBlock( readWorkflow( 'wp-tests-phpunit.yml' ), 'update-pr-description' );
if ( ! progressBlock ) {
	fail( 'wp-tests-phpunit.yml', 'missing update-pr-description job.' );
} else {
	for ( const needed of [ 'sqlite-test', 'postgresql-test' ] ) {
		if ( ! progressBlock.includes( `      - ${ needed }` ) ) {
			fail( 'wp-tests-phpunit.yml', `update-pr-description must need ${ needed }.` );
		}
	}

	if ( ! progressBlock.includes( "if: github.event_name == 'pull_request' && always()" ) ) {
		fail( 'wp-tests-phpunit.yml', 'update-pr-description must be PR-only and informational.' );
	}

	if ( /\n      (checks|statuses):\s*write/m.test( progressBlock ) ) {
		fail( 'wp-tests-phpunit.yml', 'update-pr-description must not write checks or commit statuses.' );
	}

	if ( ! progressBlock.includes( 'core.summary' ) || ! progressBlock.includes( 'github.rest.pulls.update' ) ) {
		fail( 'wp-tests-phpunit.yml', 'update-pr-description must only publish summary/PR body progress.' );
	}

	if ( /github\.rest\.(checks|repos\.createCommitStatus)/.test( progressBlock ) ) {
		fail( 'wp-tests-phpunit.yml', 'update-pr-description must not create checks or commit statuses.' );
	}
}

if ( failures.length > 0 ) {
	console.error( 'Workflow CI gate verification failed:' );
	for ( const failure of failures ) {
		console.error( `- ${ failure }` );
	}
	process.exit( 1 );
}

console.log( `Workflow CI gate verification passed for default branch "${ defaultBranch }".` );
