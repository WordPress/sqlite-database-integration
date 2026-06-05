#!/usr/bin/env python3
"""
Bump MAX_CUSTOM_FUNCS from 32 to 64 (bindings/c/src/lib.rs).

Turso's custom-function registry is capped at 32 pre-generated bridge
trampolines; the driver registers 44 UDFs, so the last 12 silently fail.
Bump to 64 by adding 32 more func_bridge!/FUNC_BRIDGES entries.
"""

import re
import sys

PATH = 'bindings/c/src/lib.rs'

with open(PATH) as f:
    s = f.read()

OLD_MAX = 'const MAX_CUSTOM_FUNCS: usize = 32;'
NEW_MAX = 'const MAX_CUSTOM_FUNCS: usize = 64;'
if OLD_MAX not in s:
    sys.exit(f'{PATH}: MAX_CUSTOM_FUNCS not found')
s = s.replace(OLD_MAX, NEW_MAX, 1)

# Inject 32 more func_bridge! declarations after func_bridge_31.
bridge_marker = 'func_bridge!(31, func_bridge_31);\n'
if bridge_marker not in s:
    sys.exit(f'{PATH}: func_bridge_31 marker not found')
extra_bridges = ''.join(
    f'func_bridge!({i}, func_bridge_{i});\n' for i in range(32, 64)
)
s = s.replace(bridge_marker, bridge_marker + extra_bridges, 1)

# Extend the FUNC_BRIDGES array: find the closing `];` of the static
# and inject the extra entries before it.
pat = re.compile(
    r'(static FUNC_BRIDGES: \[ScalarFunction; MAX_CUSTOM_FUNCS\] = \[\n'
    r'(?:\s*func_bridge_\d+,\n)+)(\];\n)'
)
m = pat.search(s)
if m is None:
    sys.exit(f'{PATH}: FUNC_BRIDGES array not found')
extra_entries = ''.join(f'    func_bridge_{i},\n' for i in range(32, 64))
s = s[:m.start(2)] + extra_entries + s[m.start(2):]

with open(PATH, 'w') as f:
    f.write(s)
print('patched MAX_CUSTOM_FUNCS 32 -> 64')
