#!/usr/bin/env python3
"""
create_function_v2 — don't fire the old destroy callback on slot reuse (bindings/c/src/lib.rs).

Turso's create_function_v2 invokes the previous FuncSlot's destroy
callback when re-registering a UDF with the same name. In practice
(PHPUnit) this means:
  - setUp #1 opens PDO A, registers 44 UDFs, each with a destroy
    callback + p_app pointing to A.
  - tearDown closes PDO A — Turso's sqlite3_close doesn't clear those
    FuncSlots.
  - setUp #2 opens PDO B, re-registers the same 44 names. Turso invokes
    the OLD destroy callback with the now-dangling A p_app, which trips
    pdo_sqlite and hangs the process.

Discard the old slot without invoking its destroy callback. Since Turso
then has no safe point at which to invoke the callback, stop retaining it;
the callback still fires at real PDO-destruction time from the PHP side.
"""

import sys

PATH = 'bindings/c/src/lib.rs'
OLD_FIELD = (
    '    destroy: usize, // Option<unsafe extern "C" fn(*mut c_void)> stored as usize for Send\n'
)
OLD_APP_FIELD = '    p_app: usize,   // *mut c_void stored as usize for Send\n'
NEW_APP_FIELD = '    p_app: usize, // *mut c_void stored as usize for Send\n'
OLD_COMMENT = (
    '    // Allocate a bridge slot.  Reuse an existing slot with the same name\n'
    '    // (invoking the old destroy callback) before falling back to a free slot.\n'
)
NEW_COMMENT = (
    '    // Allocate a bridge slot. Reuse an existing slot with the same name\n'
    '    // before falling back to a free slot.\n'
)
OLD_CAST = (
    '    // Cast the destroy callback.\n'
    '    let destroy_fn: Option<unsafe extern "C" fn(*mut ffi::c_void)> =\n'
    '        _destroy.map(|f| std::mem::transmute(f));\n'
    '\n'
)
OLD = (
    '        // Reuse existing slot — invoke old destroy callback on old user data.\n'
    '        if let Some(old) = slots[id].take() {\n'
    '            if old.destroy != 0 {\n'
    '                let old_destroy: unsafe extern "C" fn(*mut ffi::c_void) =\n'
    '                    std::mem::transmute(old.destroy);\n'
    '                old_destroy(old.p_app as *mut ffi::c_void);\n'
    '            }\n'
    '        }\n'
)
NEW = (
    "        // Don't invoke the old destroy callback here — in PDO\n"
    "        // usage the previous slot's p_app often belongs to a db\n"
    '        // that has already been closed, so the callback UAFs.\n'
    '        let _ = slots[id].take();\n'
)
OLD_STORE = '        destroy: destroy_fn.map_or(0, |f| f as usize),\n'

with open(PATH) as f:
    s = f.read()
if OLD_FIELD not in s:
    sys.exit(f'{PATH}: FuncSlot destroy field not found')
s = s.replace(OLD_FIELD, '', 1)
if OLD_APP_FIELD not in s:
    sys.exit(f'{PATH}: FuncSlot p_app field not found')
s = s.replace(OLD_APP_FIELD, NEW_APP_FIELD, 1)
if OLD_COMMENT not in s:
    sys.exit(f'{PATH}: bridge slot allocation comment not found')
s = s.replace(OLD_COMMENT, NEW_COMMENT, 1)
if OLD_CAST not in s:
    sys.exit(f'{PATH}: destroy callback cast not found')
s = s.replace(OLD_CAST, '', 1)
if OLD not in s:
    sys.exit(f'{PATH}: slot destroy block not found')
s = s.replace(OLD, NEW, 1)
if OLD_STORE not in s:
    sys.exit(f'{PATH}: FuncSlot destroy assignment not found')
s = s.replace(OLD_STORE, '', 1)
with open(PATH, 'w') as f:
    f.write(s)
print('patched slot-reuse destroy invocation')
