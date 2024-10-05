import { Grammars, Parser } from 'ebnf';
import fs from 'fs';

const filePath = process.argv[2] || 'MySQLFull.ebnf';
let grammar = fs.readFileSync(filePath, 'utf8');
grammar = grammar.replaceAll(/\/\*[\s\S]*?\*\/$/gm, ''); // remove comments (the "ebnf" package fails on some)
grammar = grammar.replaceAll('%', 'fragment__F')
let RULES = Grammars.W3C.getRules(grammar);
console.log(JSON.stringify(RULES, null, 2).replaceAll('fragment__F', '%'));