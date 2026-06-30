# MySQL Parser

A fast and complete **MySQL parser** in pure PHP with zero dependencies, generated
directly from the **official MySQL grammar**.

The runtime requires **PHP 7.2+** with no extensions.

## How it works

The grammar is compiled ahead of time from the official MySQL grammar sources.
It outputs a compact parse table and a list of MySQL tokens for the lexer:

```
mysql-server sources
  sql/sql_yacc.yy ──▶ Bison 3.8.2 ──▶ automaton.xml ─┬─▶ generate-parse-table.php ──▶ mysql-parse-table.php
                                                     |
  sql/lex.h ─────────────────────────────────────────┴─▶ generate-tokens.php ───────▶ class-wp-mysql-tokens.php
```

The runtime ships only the compiled artifacts and a thin parser implementation.
In this package, the runtime lives under `src` and the grammar tooling in `tools`.

A MySQL query is processed using the following pipeline:
```
MySQL query ──────────▶ WP_MySQL_Lexer ──────────▶ WP_Parser ──────────▶ AST
              string                 WP_MySQL_Token[]               WP_Parser_Node(s)
                                                                    WP_MySQL_Token(s)
```

## Usage

```php
require_once __DIR__ . '/vendor/autoload.php';

$parser = WP_MySQL_Parser_Factory::create_parser();
$tokens = ( new WP_MySQL_Lexer( 'SELECT 1 + 2' ) )->remaining_tokens();
$ast    = $parser->parse( $tokens );
```

## Development
This package includes the full grammar compilation pipeline. The generated artifacts
are committed to the repository, and they only need to be regenerated on grammar
changes or changes to the compilation pipeline itself. The grammar comes with a
testing query corpus extracted from the MySQL server test suite.

### Building the grammar
To regenerate the compiled MySQL artifacts from MySQL sources, use:

```bash
composer run build-grammar
```

This requires `bash`, `curl`, `docker`, and `php` and executes the following steps:
1. Fetch the grammar from https://github.com/mysql/mysql-server/.
2. Run Bison in a Docker container to generate `build/automaton.xml`.
3. Extract and compact the grammar and tokens, and save them in `src`.

### Building the query corpus
This package includes a testing corpus of about 70,000 MySQL queries extracted
from the MySQL release that corresponds to the generated MySQL grammar version.
To regenerate it, use:

```bash
composer run build-corpus
```

This requires `bash`, `git`, and `php`. It performs the following steps:
1. Shallow-clone the `mysql-test` directory from MySQL server into `build/`.
2. Extract SQL queries from the MySQL test files.
3. Store the queries under `data/mysql-server-query-corpus/mysql-latest.csv`.

## Tests and benchmarks
To run lexer and parser tests and benchmarks, use:

```bash
composer run test        # PHPUnit suite, including the query corpus tests
composer run benchmark   # Query throughput with and without JIT on the whole corpus
```
