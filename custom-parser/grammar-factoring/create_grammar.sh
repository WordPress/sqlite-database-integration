#!/bin/bash

bun 1-ebnf-to-json.js ./MySQLParser-reordered.ebnf > MySQLParser-reordered.json
python 2-cli.py lr ./MySQLParser-reordered.json --format=json > ./MySQLParser-factored.json
php 3-phpize-grammar.php ./MySQLParser-factored.json > ../parser/grammar.php
