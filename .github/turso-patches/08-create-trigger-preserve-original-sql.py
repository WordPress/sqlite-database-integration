#!/usr/bin/env python3
"""
Preserve original CREATE TRIGGER text instead of reconstructing from AST (core/translate/mod.rs).

CREATE TRIGGER: Turso reconstructs the stored sqlite_master.sql by
serializing the AST (trigger::create_trigger_to_sql), which loses
user-provided whitespace/formatting. Real SQLite preserves the
original text. testColumnWithOnUpdate asserts on the stored text;
use the raw input SQL that was already threaded into translate_inner.
"""

import sys

PATH = 'core/translate/mod.rs'

OLD = (
    '            // Reconstruct SQL for storage\n'
    '            let sql = trigger::create_trigger_to_sql(\n'
    '                temporary,\n'
    '                if_not_exists,\n'
    '                &trigger_name,\n'
    '                time,\n'
    '                &event,\n'
    '                &tbl_name,\n'
    '                for_each_row,\n'
    '                when_clause.as_deref(),\n'
    '                &commands,\n'
    '            );\n'
)
NEW = (
    '            // Preserve original SQL text (matches real SQLite);\n'
    '            // avoid AST reconstruction which loses whitespace.\n'
    '            let _ = (&event, for_each_row, when_clause.as_deref(), &commands);\n'
    '            let sql = input.to_string();\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: CreateTrigger reconstruction block not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched CreateTrigger to preserve original SQL text')
