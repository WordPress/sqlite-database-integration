#!/usr/bin/env bash

rm -rf tmp/mysql-server-tests
mkdir -p tmp/mysql-server-tests
git clone --depth 1 --no-checkout https://github.com/mysql/mysql-server.git tmp/mysql-server-tests
cd tmp/mysql-server-tests
git config core.sparseCheckout true
touch .git/info/sparse-checkout
echo "mysql-test/" >> .git/info/sparse-checkout
git checkout
rm -rf .git
