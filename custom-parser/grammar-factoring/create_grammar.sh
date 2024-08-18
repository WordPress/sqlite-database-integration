#!/bin/bash

bun 1-ebnf-to-json.js ./MySQLParser-manually-factored.ebnf > MySQLParser-manually-factored.json
python 2-cli.py expand ./MySQLParser-manually-factored.json --format=json > ./MySQLParser-factored.json
php 3-phpize-grammar.php ./MySQLParser-factored.json > ../parser/grammar.php
