#!/usr/bin/env python3
"""
Scope implicit column collation to direct refs (core/translate/collate.rs).

Turso's get_collseq_parts_from_expr walks the entire expression tree
and picks up column collation from nested Column refs. Per SQLite
rules, implicit column collation only inherits from a direct column
operand (possibly through unary +, CAST, parentheses, and COLLATE).
For compound expressions like CONCAT(col, 'str') the result should be
BINARY, not col's collation. Fix ORDER BY on UNION of computed expressions
(testComplexInformationSchemaQueries).
"""

import re
import sys

PATH = 'core/translate/collate.rs'

OLD = (
    'fn get_collseq_parts_from_expr_with_symbols(\n'
    '    top_expr: &Expr,\n'
    '    referenced_tables: &TableReferences,\n'
    '    symbol_table: Option<&SymbolTable>,\n'
    ') -> Result<(Option<CollationSeq>, Option<CollationSeq>)> {\n'
    '    let mut maybe_column_collseq = None;\n'
    '    let mut maybe_explicit_collseq = None;\n'
    '\n'
    '    walk_expr(top_expr, &mut |expr: &Expr| -> Result<WalkControl> {\n'
    '        match expr {\n'
    '            Expr::Collate(_, seq) => {\n'
    '                // Only store the first (leftmost) COLLATE operator we find\n'
    '                if maybe_explicit_collseq.is_none() {\n'
    '                    maybe_explicit_collseq = Some(\n'
    '                        resolve_collation_name(seq.as_str(), symbol_table).unwrap_or_default(),\n'
    '                    );\n'
    '                }\n'
    '                // Skip children since we\'ve found a COLLATE operator\n'
    '                return Ok(WalkControl::SkipChildren);\n'
)
NEW = (
    'fn get_collseq_parts_from_expr_with_symbols(\n'
    '    top_expr: &Expr,\n'
    '    referenced_tables: &TableReferences,\n'
    '    symbol_table: Option<&SymbolTable>,\n'
    ') -> Result<(Option<CollationSeq>, Option<CollationSeq>)> {\n'
    '    let mut maybe_column_collseq: Option<CollationSeq> = None;\n'
    '    let mut maybe_explicit_collseq: Option<CollationSeq> = None;\n'
    '\n'
    '    // Implicit column collation: only direct refs. Unary +, CAST,\n'
    '    // parentheses, and COLLATE wrappers still count as direct per\n'
    '    // SQLite. Walking into compound expressions (CONCAT,\n'
    '    // arithmetic, fn calls) picks up unrelated column collations\n'
    '    // which bleed into ORDER BY.\n'
    '    {\n'
    '        let mut cur = top_expr;\n'
    '        loop {\n'
    '            match cur {\n'
    '                Expr::Collate(inner, _) | Expr::Cast { expr: inner, .. } => {\n'
    '                    cur = inner.as_ref();\n'
    '                }\n'
    '                Expr::Unary(turso_parser::ast::UnaryOperator::Positive, inner) => {\n'
    '                    cur = inner.as_ref();\n'
    '                }\n'
    '                Expr::Parenthesized(exprs) if exprs.len() == 1 => {\n'
    '                    cur = exprs[0].as_ref();\n'
    '                }\n'
    '                _ => break,\n'
    '            }\n'
    '        }\n'
    '        match cur {\n'
    '            Expr::Column { table, column, .. } => {\n'
    '                if let Some((_, tref)) = referenced_tables.find_table_by_internal_id(*table) {\n'
    '                    if let Some(col) = tref.get_column_at(*column) {\n'
    '                        maybe_column_collseq = col.collation_opt();\n'
    '                    }\n'
    '                }\n'
    '            }\n'
    '            Expr::RowId { table, .. } => {\n'
    '                if let Some((_, tref)) = referenced_tables.find_table_by_internal_id(*table) {\n'
    '                    if let Some(btree) = tref.btree() {\n'
    '                        if let Some((_, rc)) = btree.get_rowid_alias_column() {\n'
    '                            maybe_column_collseq = rc.collation_opt();\n'
    '                        }\n'
    '                    }\n'
    '                }\n'
    '            }\n'
    '            _ => {}\n'
    '        }\n'
    '    }\n'
    '\n'
    '    // Explicit COLLATE at any nesting is still honoured per SQLite.\n'
    '    walk_expr(top_expr, &mut |expr: &Expr| -> Result<WalkControl> {\n'
    '        match expr {\n'
    '            Expr::Collate(_, seq) => {\n'
    '                if maybe_explicit_collseq.is_none() {\n'
    '                    maybe_explicit_collseq = Some(\n'
    '                        resolve_collation_name(seq.as_str(), symbol_table).unwrap_or_default(),\n'
    '                    );\n'
    '                }\n'
    '                return Ok(WalkControl::SkipChildren);\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: get_collseq_parts_from_expr start block not found')
s = s.replace(OLD, NEW, 1)

# Now delete the old Column/RowId walk blocks that used to set
# maybe_column_collseq (we've moved that to the top-level above).
col_block_pat = re.compile(
    r"            Expr::Column \{ table, column, \.\. \} => \{\n"
    r"                let \(_, table_ref\) = referenced_tables\n"
    r"                    \.find_table_by_internal_id\(\*table\)\n"
    r"                    \.ok_or_else\(\|\| crate::LimboError::ParseError\(\"table not found\"\.to_string\(\)\)\)\?;\n"
    r"                let column = table_ref\n"
    r"                    \.get_column_at\(\*column\)\n"
    r"                    \.ok_or_else\(\|\| crate::LimboError::ParseError\(\"column not found\"\.to_string\(\)\)\)\?;\n"
    r"                if maybe_column_collseq\.is_none\(\) \{\n"
    r"                    maybe_column_collseq = column\.collation_opt\(\);\n"
    r"                \}\n"
    r"                return Ok\(WalkControl::Continue\);\n"
    r"            \}\n"
    r"            Expr::RowId \{ table, \.\. \} => \{\n"
    r"                let \(_, table_ref\) = referenced_tables\n"
    r"                    \.find_table_by_internal_id\(\*table\)\n"
    r"                    \.ok_or_else\(\|\| crate::LimboError::ParseError\(\"table not found\"\.to_string\(\)\)\)\?;\n"
    r"                if let Some\(btree\) = table_ref\.btree\(\) \{\n"
    r"                    if let Some\(\(_, rowid_alias_col\)\) = btree\.get_rowid_alias_column\(\) \{\n"
    r"                        if maybe_column_collseq\.is_none\(\) \{\n"
    r"                            maybe_column_collseq = rowid_alias_col\.collation_opt\(\);\n"
    r"                        \}\n"
    r"                    \}\n"
    r"                \}\n"
    r"                return Ok\(WalkControl::Continue\);\n"
    r"            \}\n"
)
# Apply only inside the get_collseq_parts_from_expr function, which ends
# before the next `fn ` declaration.
func_start = s.find('fn get_collseq_parts_from_expr_with_symbols')
if func_start < 0:
    sys.exit(f'{PATH}: function header missing after first replace')
func_end = s.find('\n}\n', func_start) + 3
new_body = col_block_pat.sub('', s[func_start:func_end], count=1)
if new_body == s[func_start:func_end]:
    sys.exit(f'{PATH}: old Column/RowId walk blocks not found')
s = s[:func_start] + new_body + s[func_end:]
with open(PATH, 'w') as f:
    f.write(s)
print('patched collate.rs to scope column-collation to direct refs')
