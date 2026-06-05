#!/usr/bin/env python3
"""
Refuse hash-join when the current query block is correlated (UPSTREAM).

The join planner picks hash-join for tables inside a correlated
subquery (current block has non-empty outer_query_refs). Hash-build
runs once before outer column refs are bound, so equality predicates
against outer columns evaluate against NULL, producing 0 rows in the
hash table. Every probe then misses, the scalar subquery returns NULL,
and an outer `WHERE alias > N` filter discards everything.

Repro: testInformationSchemaTablesFilterByAutoIncrement —
the cols × sqlite_sequence join inside the AUTO_INCREMENT correlated
subquery.

Fix: refuse hash-join when the current query block is itself
correlated (has outer_query_refs). Falls back to nested-loop, which
re-evaluates per outer row.

Worth reporting upstream against tursodatabase/turso.
"""

import sys

PATH = 'core/translate/optimizer/join.rs'

OLD = (
    '            let allow_hash_join = !rhs_has_selective_seek\n'
    '                && !probe_table_is_prior_build\n'
    '                && (!build_has_prior_constraints || build_has_rowid)\n'
    '                && !chaining_across_outer;\n'
)
NEW = (
    '            // Refuse hash-join when the current query block is itself a\n'
    '            // correlated subquery (has outer_query_refs). The hash build\n'
    '            // would materialize once with outer column refs unbound,\n'
    '            // yielding 0 rows for any predicate against an outer column.\n'
    '            let allow_hash_join = !rhs_has_selective_seek\n'
    '                && !probe_table_is_prior_build\n'
    '                && (!build_has_prior_constraints || build_has_rowid)\n'
    '                && !chaining_across_outer\n'
    '                && table_references.outer_query_refs().is_empty();\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: hash-join allow_hash_join block not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched join.rs to refuse hash-join in correlated query blocks')
