#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SQLANCER_DIR="${SQLANCER_DIR:-/tmp/sqlancer}"
SQLANCER_REPO="${SQLANCER_REPO:-https://github.com/sqlancer/sqlancer.git}"
SQLANCER_IMAGE="${SQLANCER_IMAGE:-maven:3.9-eclipse-temurin-21}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.4}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-sqlancer}"
MYSQL_TMPFS_SIZE="${MYSQL_TMPFS_SIZE:-1024m}"
RANDOM_SEED="${RANDOM_SEED:-20260617}"
NUM_QUERIES="${NUM_QUERIES:-200}"
MAX_GENERATED_DATABASES="${MAX_GENERATED_DATABASES:-1}"
SQLANCER_MYSQL_ORACLE="${SQLANCER_MYSQL_ORACLE:-FUZZER}"
DATABASE_PREFIX="${DATABASE_PREFIX:-sdi_fuzz}"
ARTIFACTS_DIR="${ARTIFACTS_DIR:-/tmp/sdi-sqlancer-artifacts/$(date -u +%Y%m%d-%H%M%S)}"

NETWORK="sdi-sqlancer-$$"
MYSQL_CONTAINER="sdi-sqlancer-mysql-$$"
CURRENT_DB=""
SKIP_ARGS=()

cleanup() {
	docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
	docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [ ! -f "$SQLANCER_DIR/pom.xml" ]; then
	git clone --depth 1 "$SQLANCER_REPO" "$SQLANCER_DIR"
fi

SQLANCER_JAR="$(find "$SQLANCER_DIR/target" -maxdepth 1 -type f -name 'sqlancer-*.jar' 2>/dev/null | sort | head -n 1 || true)"
if [ -z "$SQLANCER_JAR" ]; then
	docker run --rm \
		-v "$SQLANCER_DIR:/src" \
		-w /src \
		"$SQLANCER_IMAGE" \
		mvn -DskipTests package
	SQLANCER_JAR="$(find "$SQLANCER_DIR/target" -maxdepth 1 -type f -name 'sqlancer-*.jar' | sort | head -n 1)"
fi

mkdir -p "$ARTIFACTS_DIR"
docker run --rm \
	-v "$SQLANCER_DIR:/sqlancer" \
	-w /sqlancer \
	"$SQLANCER_IMAGE" \
	sh -c 'rm -rf logs/mysql'

docker network create "$NETWORK" >/dev/null
docker run -d --rm \
	--name "$MYSQL_CONTAINER" \
	--network "$NETWORK" \
	--tmpfs "/var/lib/mysql:rw,size=$MYSQL_TMPFS_SIZE" \
	-e MYSQL_ROOT_PASSWORD="$MYSQL_PASSWORD" \
	-e MYSQL_ROOT_HOST=% \
	"$MYSQL_IMAGE" \
	--mysql-native-password=ON >/dev/null

for _ in $(seq 1 60); do
	if docker run --rm --network "$NETWORK" --tmpfs /var/lib/mysql:rw,size=16m "$MYSQL_IMAGE" mysqladmin ping -h"$MYSQL_CONTAINER" -uroot -p"$MYSQL_PASSWORD" --silent >/dev/null 2>&1; then
		break
	fi
	sleep 1
done

docker run --rm --network "$NETWORK" --tmpfs /var/lib/mysql:rw,size=16m "$MYSQL_IMAGE" mysqladmin ping -h"$MYSQL_CONTAINER" -uroot -p"$MYSQL_PASSWORD" --silent >/dev/null

docker run --rm \
	--network "$NETWORK" \
	-v "$SQLANCER_DIR:/sqlancer" \
	-w /sqlancer \
	"$SQLANCER_IMAGE" \
	java -jar "target/$(basename "$SQLANCER_JAR")" \
	--num-threads 1 \
	--num-queries "$NUM_QUERIES" \
	--max-generated-databases "$MAX_GENERATED_DATABASES" \
	--num-tries 1 \
	--database-prefix "$DATABASE_PREFIX" \
	--random-seed "$RANDOM_SEED" \
	--username root \
	--password "$MYSQL_PASSWORD" \
	--host "$MYSQL_CONTAINER" \
	--port 3306 \
	mysql --oracle "$SQLANCER_MYSQL_ORACLE"

LOG_FILE="$(find "$SQLANCER_DIR/logs/mysql" -maxdepth 1 -type f -name '*-cur.log' | sort | head -n 1)"
if [ -z "$LOG_FILE" ]; then
	echo "No SQLancer MySQL log was generated." >&2
	exit 1
fi

cp "$LOG_FILE" "$ARTIFACTS_DIR/"
LOG_FILE="$ARTIFACTS_DIR/$(basename "$LOG_FILE")"
MYSQL_FAILURES_FILE="$ARTIFACTS_DIR/mysql-rejected-lines.txt"
MYSQL_ACCEPTED_FILE="$ARTIFACTS_DIR/mysql-accepted-prefix.sql"
: > "$MYSQL_FAILURES_FILE"
: > "$MYSQL_ACCEPTED_FILE"

LINE_NUMBER=0
while IFS= read -r LINE || [ -n "$LINE" ]; do
	LINE_NUMBER=$(( LINE_NUMBER + 1 ))
	SQL="$(php -r '
		$line = trim(stream_get_contents(STDIN));
		if ($line === "" || strpos($line, "--") === 0) {
			exit;
		}
		echo preg_replace("/;\s*--\s*\d+ms;?$/", ";", $line);
	' <<< "$LINE")"

	if [ -z "$SQL" ]; then
		continue
	fi

	if [[ "$SQL" =~ ^[Uu][Ss][Ee][[:space:]]+([^[:space:];]+) ]]; then
		CURRENT_DB="${BASH_REMATCH[1]}"
		printf '%s\n' "$SQL" >> "$MYSQL_ACCEPTED_FILE"
		continue
	fi

	TMP_SQL="$(mktemp)"
	printf '%s\n' "$SQL" > "$TMP_SQL"
	if [ -n "$CURRENT_DB" ]; then
		MYSQL_ARGS=( --database="$CURRENT_DB" )
	else
		MYSQL_ARGS=()
	fi

	if ! docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_PASSWORD" --batch --raw "${MYSQL_ARGS[@]}" < "$TMP_SQL" >/dev/null 2>&1; then
		printf '%s\n' "$LINE_NUMBER" >> "$MYSQL_FAILURES_FILE"
		SKIP_ARGS+=( "--skip-line=$LINE_NUMBER" )
		# Non-transactional MySQL engines can keep partial changes after errors.
		# Rebuild from the accepted prefix so later filtering does not depend on skipped side effects.
		if ! docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_PASSWORD" --batch --raw < "$MYSQL_ACCEPTED_FILE" >/dev/null 2>&1; then
			echo "Failed to restore MySQL state from accepted SQLancer prefix after line $LINE_NUMBER." >&2
			exit 1
		fi
	else
		printf '%s\n' "$SQL" >> "$MYSQL_ACCEPTED_FILE"
	fi
	rm -f "$TMP_SQL"
done < "$LOG_FILE"

php "$ROOT_DIR/tests/fuzz/replay-sqlancer-log.php" "$LOG_FILE" "${SKIP_ARGS[@]}"

printf 'SQLancer log: %s\n' "$LOG_FILE"
printf 'MySQL-rejected line list: %s\n' "$MYSQL_FAILURES_FILE"
