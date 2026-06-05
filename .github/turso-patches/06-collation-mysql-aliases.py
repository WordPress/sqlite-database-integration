#!/usr/bin/env python3
"""
Alias MySQL collation names to the nearest Turso built-in (core/translate/collate.rs).

Turso recognizes built-in and ICU locale collation names. Driver-emitted
SQL references MySQL collations like `utf8mb4_bin` (byte-compare) and
`utf8mb4_0900_ai_ci` (case-insensitive), which are not ICU locale names.
Map these to the closest built-in at lookup time.
"""

import sys

PATH = 'core/translate/collate.rs'

OLD = (
    '    pub fn new(collation: &str) -> crate::Result<Self> {\n'
    '        match crate::util::normalize_ident(collation).as_str() {\n'
    '            "binary" => return Ok(Self::Binary),\n'
    '            "nocase" => return Ok(Self::NoCase),\n'
    '            "rtrim" => return Ok(Self::Rtrim),\n'
    '            _ => {}\n'
    '        }\n'
    '\n'
    '        LocaleCollationRegistry::global()\n'
    '            .get_or_register(collation)\n'
    '            .map(Self::Locale)\n'
    '    }\n'
)
NEW = (
    '    pub fn new(collation: &str) -> crate::Result<Self> {\n'
    '        // Alias common MySQL collation names to the nearest\n'
    '        // Turso built-in before ICU locale parsing rejects them.\n'
    '        match crate::util::normalize_ident(collation).as_str() {\n'
    '            "binary" | "utf8mb4_bin" | "utf8_bin" | "ascii_bin" | "latin1_bin" => {\n'
    '                return Ok(Self::Binary)\n'
    '            }\n'
    '            "nocase" | "utf8mb4_0900_ai_ci" | "utf8mb4_general_ci" | "utf8_general_ci"\n'
    '            | "latin1_general_ci" | "latin1_swedish_ci" => return Ok(Self::NoCase),\n'
    '            "rtrim" => return Ok(Self::Rtrim),\n'
    '            _ => {}\n'
    '        }\n'
    '\n'
    '        LocaleCollationRegistry::global()\n'
    '            .get_or_register(collation)\n'
    '            .map(Self::Locale)\n'
    '    }\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: CollationSeq::new body not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched CollationSeq to alias MySQL collations')
