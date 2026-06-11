# MySQL Parser

A fast, table-driven parser for MySQL, generated directly from the **official
MySQL 8.4 LTS grammar** (`sql/sql_yacc.yy`) and keyword table (`sql/lex.h`)
shipped in the [mysql-server](https://github.com/mysql/mysql-server) sources.

The package treats MySQL's own Bison grammar as the source of truth and
compiles it, unmodified, into a compact LALR(1) parse table that a small
pure-PHP runtime executes. The lexer emits the grammar's own token numbers and
its keyword table is generated from `lex.h`, so the accepted language tracks a
real MySQL release exactly, with no hand-maintained grammar to drift.

## How it works

The grammar is compiled ahead of time by the tooling in `tools/`. The runtime
ships only the compiled artifacts and a thin driver:

```
mysql-server sources (pinned tag)
  sql/sql_yacc.yy ─┐
                   ├─▶ Bison 3.8.2 ──▶ automaton.xml ──▶ generate-parse-table.php ──▶ src/grammar/parse-table.php
  sql/lex.h ───────┘                          │
                                              └──────────▶ generate-tokens.php ──────▶ src/grammar/tokens.php

MySQL query ──▶ WP_MySQL_Lexer ──▶ grammar tokens ──▶ WP_MySQL_Parser ──▶ WP_Parser_Node AST
                   (tokens.php)                       (parse-table.php)
```

- **`tools/`** compiles the grammar. It fetches the pinned MySQL sources
  (checksum-verified), runs the exact Bison version MySQL uses (3.8.2, in
  Docker, version-asserted), and compacts Bison's `--xml` automaton into plain
  PHP ACTION/GOTO tables: per-state default reductions with sparse exception
  rows, identical rows shared between states, near-identical keyword rows
  patch-encoded against a base row, and per-nonterminal GOTO defaults — about
  7% of the cells of the dense table. A second generator
  derives the token-level data — the keyword table, the paren-gated function
  keywords, and the token constants the scanner refers to — from `lex.h` and
  the automaton, resolving every terminal by name.
- **`src/`** is the runtime: the MySQL lexer, the parser, and the two generated
  grammar artifacts.

The MySQL 8.4 grammar is unambiguous for an LALR(1) parser (Bison resolves its
59 shift/reduce conflicts by precedence and reports zero reduce/reduce
conflicts), so the runtime is a plain deterministic shift-reduce loop — no GLR,
backtracking, or conflict tables.

### The AST

`parse()` returns a `WP_Parser_Node` tree in which each node carries the
grammar rule name it was reduced by. By default every rule materialises a node,
including MySQL's deep single-child wrapper chains
(`expr → bool_pri → predicate → bit_expr → ...`).

## Package layout

```
mysql-parser/
├── bin/build-grammar              Orchestrates the full grammar build.
├── tools/
│   ├── fetch-mysql-grammar.sh     Fetch sql_yacc.yy + lex.h at the pinned tag.
│   ├── run-bison.sh               Run Bison 3.8.2 (Docker) -> automaton.xml.
│   ├── generate-parse-table.php   automaton.xml -> src/grammar/parse-table.php.
│   └── generate-tokens.php        lex.h -> src/grammar/tokens.php.
├── src/
│   ├── parser/                    Generic parse-tree primitives (token, node).
│   ├── class-wp-mysql-token.php   MySQL token leaf.
│   ├── class-wp-mysql-lexer.php   MySQL lexer.
│   ├── class-wp-mysql-parser.php  The LALR(1) runtime.
│   └── grammar/                   Generated parse table + token data.
└── tests/                         PHPUnit suite + corpus benchmark.
```

The runtime requires **PHP 7.2+** and no PHP extensions.

## Using the parser

```php
require_once __DIR__ . '/src/load.php';

$parser = new WP_MySQL_Parser( require __DIR__ . '/src/grammar/parse-table.php' );
$tokens = ( new WP_MySQL_Lexer( 'SELECT 1 + 2' ) )->remaining_tokens();
$ast    = $parser->parse( $tokens );   // WP_Parser_Node, or null on a syntax error.
```

## Building the grammar

The compiled artifacts under `src/grammar/` are committed, so the parser works
out of the box. To regenerate them from the MySQL sources, run from this
package's directory:

```bash
composer run build-grammar
```

This requires `bash`, `curl`, `docker`, and `php`. It fetches the grammar,
runs Bison in Docker, and rewrites `src/grammar/parse-table.php` and
`src/grammar/tokens.php`; re-running it reproduces the committed artifacts
byte for byte. Both artifacts are plain PHP arrays. The fetched sources and the (large) automaton dump land in
`build/`, which is gitignored.

## Pinned MySQL version

The grammar is pinned to **`mysql-8.4.3`** (the version the committed artifacts
were generated from), with the source checksums pinned in
`tools/fetch-mysql-grammar.sh`. The tag is overridable for the build:

```bash
MYSQL_TAG=mysql-8.4.4 composer run build-grammar
```

Bumping the tag regenerates `src/grammar/*`; the script prints the new source
checksums to re-pin. Follow up by re-running the test suite and benchmark to
confirm the corpus parse rate.
