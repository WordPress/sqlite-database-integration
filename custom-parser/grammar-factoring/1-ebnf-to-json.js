import { Grammars, Parser } from 'ebnf';
import fs from 'fs';

const filePath = process.argv[2] || 'MySQLFull.ebnf';
let grammar = fs.readFileSync(filePath, 'utf8');
let RULES = Grammars.W3C.getRules(grammar);

console.log(JSON.stringify(RULES, null, 2));