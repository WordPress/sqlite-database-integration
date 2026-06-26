#!/usr/bin/env node

const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const workflowDir = path.join( root, '.github', 'workflows' );
const failures = [];
const missingJobs = new Set();

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

function getRequiredJobBlock( filename, jobName ) {
	const block = getJobBlock( readWorkflow( filename ), jobName );
	if ( block ) {
		return block;
	}

	const key = `${ filename }:${ jobName }`;
	if ( ! missingJobs.has( key ) ) {
		fail( filename, `missing ${ jobName } job.` );
		missingJobs.add( key );
	}

	return '';
}

function getStepBlockContaining( jobBlock, needle ) {
	const lines = jobBlock.split( /\r?\n/ );
	const needleIndex = lines.findIndex( ( line ) => line.includes( needle ) );
	if ( -1 === needleIndex ) {
		return '';
	}

	let start = needleIndex;
	for ( ; start >= 0; start-- ) {
		if ( /^      - /.test( lines[ start ] ) ) {
			break;
		}
	}

	if ( start < 0 ) {
		return lines[ needleIndex ];
	}

	let end = lines.length;
	for ( let i = start + 1; i < lines.length; i++ ) {
		if ( /^      - /.test( lines[ i ] ) ) {
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
	const block = getRequiredJobBlock( filename, jobName );
	if ( ! block ) {
		return;
	}

	if ( /^    if:/m.test( block ) ) {
		fail( filename, `${ jobName } job must not have a job-level if gate.` );
	}
}

function assertJobIncludes( filename, jobName, needle, message ) {
	const block = getRequiredJobBlock( filename, jobName );
	if ( ! block ) {
		return;
	}

	if ( ! block.includes( needle ) ) {
		fail( filename, message );
	}
}

function assertJobRunStepHasNoIf( filename, jobName, command, message ) {
	const block = getRequiredJobBlock( filename, jobName );
	if ( ! block ) {
		return;
	}

	const stepBlock = getStepBlockContaining( block, command );
	if ( ! stepBlock || ! /^(      - run:|        run:)/m.test( stepBlock ) ) {
		fail( filename, message );
		return;
	}

	if ( /^(      - if:|        if:)/m.test( stepBlock ) ) {
		fail( filename, `${ jobName } run step for "${ command }" must not have a step-level if gate.` );
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

assertJobHasNoTopLevelIf( 'phpunit-tests.yml', 'postgresql-test' );
assertJobIncludes(
	'phpunit-tests.yml',
	'postgresql-test',
	'image: postgres:16',
	'package PostgreSQL PHPUnit job must define a PostgreSQL service.'
);
assertJobIncludes(
	'phpunit-tests.yml',
	'postgresql-test',
	'extensions: pdo_pgsql',
	'package PostgreSQL PHPUnit job must install the pdo_pgsql extension.'
);
assertJobIncludes(
	'phpunit-tests.yml',
	'postgresql-test',
	'PGSQL_TEST_DSN: pgsql:host=127.0.0.1;port=5432;dbname=wordpress_develop',
	'package PostgreSQL PHPUnit job must set PGSQL_TEST_DSN.'
);
assertJobRunStepHasNoIf(
	'phpunit-tests.yml',
	'postgresql-test',
	'composer run test-postgresql',
	'package PostgreSQL PHPUnit job must run composer run test-postgresql.'
);

assertJobHasNoTopLevelIf( 'wp-tests-phpunit.yml', 'postgresql-test' );
assertJobIncludes(
	'wp-tests-phpunit.yml',
	'postgresql-test',
	'WP_TEST_DB_BACKEND: postgresql',
	'WordPress PostgreSQL PHPUnit job must set WP_TEST_DB_BACKEND=postgresql.'
);
assertJobRunStepHasNoIf(
	'wp-tests-phpunit.yml',
	'postgresql-test',
	'node .github/workflows/wp-tests-phpunit-run.js',
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

assertIncludes(
	'end-to-end-tests.yml',
	'WP_TEST_DB_BACKEND: sqlite',
	'plugin Query Monitor e2e workflow must explicitly run the SQLite backend.'
);
assertIncludes(
	'wp-tests-end-to-end.yml',
	'backend:',
	'WordPress e2e workflow must define a database backend matrix.'
);
assertIncludes(
	'wp-tests-end-to-end.yml',
	'- postgresql',
	'WordPress e2e workflow matrix must include PostgreSQL.'
);
assertIncludes(
	'wp-tests-end-to-end.yml',
	'WP_TEST_DB_BACKEND: ${{ matrix.backend }}',
	'WordPress e2e workflow must pass the selected database backend to composer.'
);

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
