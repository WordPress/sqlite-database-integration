import json
import sys
import argparse
from ebnfutils import eliminate_left_recursion, encode_as_ebnf, factor_common_prefixes, expand_grammar

class CustomArgumentParser(argparse.ArgumentParser):
    def error(self, message):
        self.print_help(sys.stderr)
        self.exit(2, f"{self.prog}: error: {message}\n")

parser = CustomArgumentParser(description="Processes the parser grammar.")

# Add the mode positional argument
parser.add_argument(
    'mode',
    type=str,
    choices=['lr', 'expand', 'cp', 'all'],
    help=(
        'Specify the mode. Options are:\n'
        "* 'lr' for left recursion elimination\n"
        "* 'cp' for factoring common prefixes\n"
        "* 'all' for both\n"
    )
)

# Add the filename positional argument
parser.add_argument(
    'filename',
    type=str,
    help='Specify the filename.'
)

# Add the format argument (optional flag)
parser.add_argument(
    '--format',
    type=str,
    choices=['json', 'ebnf'],
    default='json',
    required=False,
    help='Specify the output format. Options are: json, ebnf.'
)

# Parse the arguments
args = parser.parse_args()

# Print the parsed values
# print(f"Selected format: {args.format}")
# print(f"Selected mode: {args.mode}")
# print(f"Filename: {args.filename}")

if args.filename is None or args.mode not in ["expand", "lr", "cp", "all"]:
    print("Usage: python ebnf-to-right-recursive.py <mode> <filename> [--format json|ebnf]")
    print("Mode can be one of:")
    print("* 'expand' for expansion of * ? + symbols")
    print("* 'lr' for left recursion elimination")
    print("* 'cp' for factoring common prefixes")
    print("* 'all' for both")
    print("")
    print("Filename is the path to the JSON file containing the parsed EBNF grammar")
    print("")
    sys.exit(1)

try:
    with open(args.filename) as fp:
        input_grammar = json.load(fp)
except Exception as e:
    print(e, file=sys.stderr)
    print(f"Failed to load grammar from {args.filename}", file=sys.stderr)
    sys.exit(1)

updated_grammar = input_grammar
if args.mode == "expand" or args.mode == "all":
    grammar, new_rules = expand_grammar(updated_grammar)
    updated_grammar = grammar

if args.mode == "lr" or args.mode == "all":
    updated_grammar = eliminate_left_recursion(updated_grammar)

# if args.mode == "cp" or args.mode == "all":
#     updated_grammar = factor_common_prefixes(updated_grammar, passes=1)

if args.format == "json":
    print(json.dumps(updated_grammar, indent=2))
else:
    print(encode_as_ebnf(updated_grammar))
