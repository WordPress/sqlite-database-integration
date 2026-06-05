#!/usr/bin/env python3
"""
sqlite3_column_* — early-return when no row is present (bindings/c/src/lib.rs).

The sqlite3_column_* functions `.expect()` that a row is present, but
pdo_sqlite legitimately calls them on statements that have not yet
stepped to SQLITE_ROW (e.g. for column metadata). Replace the expect
with an early return of the type's "null" value, matching SQLite's
actual behaviour.
"""

import re
import sys

PATH = 'bindings/c/src/lib.rs'

DEFAULTS = {
    'sqlite3_column_type':   'SQLITE_NULL',
    'sqlite3_column_int':    '0',
    'sqlite3_column_int64':  '0',
    'sqlite3_column_double': '0.0',
    'sqlite3_column_blob':   'std::ptr::null()',
    'sqlite3_column_bytes':  '0',
    'sqlite3_column_text':   'std::ptr::null()',
}

PATTERN = re.compile(
    r'(pub unsafe extern "C" fn (sqlite3_column_\w+)\([^)]*\)[^{]*\{)'
    r'((?:[^{}]|\{[^{}]*\})*?)'
    r'(let row = stmt\s*\.stmt\s*\.row\(\)\s*'
    r'\.expect\("Function should only be called after `SQLITE_ROW`"\);)',
    re.DOTALL,
)


def repl(m):
    header, name, body, _ = m.group(1), m.group(2), m.group(3), m.group(4)
    default = DEFAULTS.get(name, '0')
    guarded = (
        'let row = match stmt.stmt.row() {\n'
        '        Some(r) => r,\n'
        f'        None => return {default},\n'
        '    };'
    )
    return header + body + guarded


with open(PATH) as f:
    src = f.read()
src, n = PATTERN.subn(repl, src)
if n == 0:
    sys.exit(f'{PATH}: no sqlite3_column_* expect-row blocks matched')
with open(PATH, 'w') as f:
    f.write(src)
print(f'patched {n} sqlite3_column_* functions')
