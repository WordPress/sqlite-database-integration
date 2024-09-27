import io
import json
import sys
import argparse
from contextlib import redirect_stdout
from ebnfutils import eliminate_left_recursion, encode_as_ebnf, factor_common_prefixes, expand_grammar, hash_grammar, ParseTableBuilder

class CustomArgumentParser(argparse.ArgumentParser):
    def error(self, message):
        self.print_help(sys.stderr)
        self.exit(2, f"{self.prog}: error: {message}\n")

parser = CustomArgumentParser(description="Detects conflicts in the provided grammar.")

# Add the filename positional argument
parser.add_argument(
    'filename',
    type=str,
    help='Specify the filename.'
)

# Parse the arguments
args = parser.parse_args()

try:
    with open(args.filename) as fp:
        grammar = json.load(fp)
except Exception as e:
    print(e, file=sys.stderr)
    print(f"Failed to load grammar from {args.filename}", file=sys.stderr)
    sys.exit(1)

f = io.StringIO()
with redirect_stdout(f):
    productions = hash_grammar(grammar)
    pt = ParseTableBuilder(productions)

conflicts = f.getvalue()

conflict_map = {}
for conflict in conflicts.split("\n"):
		conflict = conflict.strip()
		if conflict == "":
				continue
		prefix, suffix = conflict.split(" with terminal ")
		terminal = suffix.strip(".")
		if prefix in conflict_map:
				conflict_map[prefix].append(terminal)
		else:
				conflict_map[prefix] = [terminal]

conflict_list = sorted_by_second = sorted(conflict_map.items(), key=lambda tup: tup[0])
for i, (prefix, suffixes) in enumerate(conflict_list):
		print(f"[{i + 1}] {prefix} with terminals:")
		suffixes.sort()
		print(", ".join(suffixes))
		print("")
