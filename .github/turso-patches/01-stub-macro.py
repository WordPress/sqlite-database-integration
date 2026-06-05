#!/usr/bin/env python3
"""
Neutralize Turso's stub! macro (bindings/c/src/lib.rs).

Many SQLite C API functions are stubbed out via `stub!()`, which expands
to `todo!("X is not implemented")`. pdo_sqlite hits one during PDO
construction (sqlite3_set_authorizer) and the panic aborts the PHP
process. Rewrite the body to return a zeroed value of the function's
return type (0 / SQLITE_OK for ints, NULL for pointers) instead.
"""

import sys

PATH = 'bindings/c/src/lib.rs'
OLD = 'todo!("{} is not implemented", stringify!($fn));'
NEW = 'return unsafe { std::mem::zeroed() };'

with open(PATH) as f:
    src = f.read()
if OLD not in src:
    sys.exit(f'{PATH}: stub! todo!() body not found')
n = src.count(OLD)
src = src.replace(OLD, NEW)
with open(PATH, 'w') as f:
    f.write(src)
print(f'patched stub! macro ({n} occurrence{"s" if n != 1 else ""})')
