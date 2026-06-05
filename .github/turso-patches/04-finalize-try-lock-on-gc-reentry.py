#!/usr/bin/env python3
"""
sqlite3_finalize / stmt_run_to_completion — try_lock to dodge GC re-entry deadlock.

sqlite3_finalize uses a non-reentrant std::sync::Mutex on the db. PHP's
cycle GC can fire a PDO statement destructor while another statement's
sqlite3_step is in progress (i.e., from inside a UDF callback whose
bridge re-enters PHP). The outer step holds the mutex, the inner
finalize blocks on it, and we deadlock.

Fix: use try_lock; on contention, skip the stmt_list unlink and the
drain. The list is only traversed by sqlite3_next_stmt (which
pdo_sqlite doesn't call) and dropped on sqlite3_close, so a stale entry
is harmless; the stmt's own Box is still freed below.
"""

import sys

PATH = 'bindings/c/src/lib.rs'

OLD_FINALIZE = (
    '    if !stmt_ref.db.is_null() {\n'
    '        let db = &mut *stmt_ref.db;\n'
    '        let mut db_inner = db.inner.lock().unwrap();\n'
    '\n'
    '        if db_inner.stmt_list == stmt {\n'
    '            db_inner.stmt_list = stmt_ref.next;\n'
    '        } else {\n'
    '            let mut current = db_inner.stmt_list;\n'
    '            while !current.is_null() {\n'
    '                let current_ref = &mut *current;\n'
    '                if current_ref.next == stmt {\n'
    '                    current_ref.next = stmt_ref.next;\n'
    '                    break;\n'
    '                }\n'
    '                current = current_ref.next;\n'
    '            }\n'
    '        }\n'
    '    }\n'
)
NEW_FINALIZE = (
    '    if !stmt_ref.db.is_null() {\n'
    '        let db = &mut *stmt_ref.db;\n'
    '        // try_lock to avoid deadlock when finalize is invoked\n'
    '        // re-entrantly (GC destructor during UDF callback).\n'
    '        if let Ok(mut db_inner) = db.inner.try_lock() {\n'
    '            if db_inner.stmt_list == stmt {\n'
    '                db_inner.stmt_list = stmt_ref.next;\n'
    '            } else {\n'
    '                let mut current = db_inner.stmt_list;\n'
    '                while !current.is_null() {\n'
    '                    let current_ref = &mut *current;\n'
    '                    if current_ref.next == stmt {\n'
    '                        current_ref.next = stmt_ref.next;\n'
    '                        break;\n'
    '                    }\n'
    '                    current = current_ref.next;\n'
    '                }\n'
    '            }\n'
    '        }\n'
    '    }\n'
)

OLD_DRAIN = (
    'unsafe fn stmt_run_to_completion(stmt: *mut sqlite3_stmt) -> ffi::c_int {\n'
    '    let stmt_ref = &mut *stmt;\n'
    '    while stmt_ref.stmt.execution_state().is_running() {\n'
    '        let result = sqlite3_step(stmt);\n'
    '        if result != SQLITE_DONE && result != SQLITE_ROW {\n'
    '            return result;\n'
    '        }\n'
    '    }\n'
    '    SQLITE_OK\n'
    '}\n'
)
NEW_DRAIN = (
    'unsafe fn stmt_run_to_completion(stmt: *mut sqlite3_stmt) -> ffi::c_int {\n'
    '    let stmt_ref = &mut *stmt;\n'
    "    // Skip drain if we can't acquire the db mutex: we're\n"
    "    // re-entering from a UDF callback's GC destructor, and\n"
    '    // sqlite3_step would block forever. The stmt will be\n'
    '    // freed anyway by the caller.\n'
    '    if !stmt_ref.db.is_null() {\n'
    '        let db = &*stmt_ref.db;\n'
    '        if db.inner.try_lock().is_err() {\n'
    '            return SQLITE_OK;\n'
    '        }\n'
    '    }\n'
    '    while stmt_ref.stmt.execution_state().is_running() {\n'
    '        let result = sqlite3_step(stmt);\n'
    '        if result != SQLITE_DONE && result != SQLITE_ROW {\n'
    '            return result;\n'
    '        }\n'
    '    }\n'
    '    SQLITE_OK\n'
    '}\n'
)

with open(PATH) as f:
    s = f.read()
if OLD_FINALIZE not in s:
    sys.exit(f'{PATH}: sqlite3_finalize stmt_list block not found')
s = s.replace(OLD_FINALIZE, NEW_FINALIZE, 1)
if OLD_DRAIN not in s:
    sys.exit(f'{PATH}: stmt_run_to_completion block not found')
s = s.replace(OLD_DRAIN, NEW_DRAIN, 1)
with open(PATH, 'w') as f:
    f.write(s)
print('patched sqlite3_finalize + stmt_run_to_completion for GC re-entry')
