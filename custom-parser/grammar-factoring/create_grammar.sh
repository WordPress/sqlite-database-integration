#!/bin/bash

php convert-grammar.php > MySQLParser-factored-versioned.json
php 3-phpize-grammar.php ./MySQLParser-factored-versioned.json > ../parser/grammar.php
