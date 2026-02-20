#!/bin/sh
set -eu

TEST_DB="vunnix_test"

if [ "${TEST_DB}" = "${POSTGRES_DB}" ]; then
    echo "Skipping test DB creation: test DB matches POSTGRES_DB (${POSTGRES_DB})."
    exit 0
fi

psql -v ON_ERROR_STOP=1 \
    --username "${POSTGRES_USER}" \
    --dbname postgres \
    --set=test_db="${TEST_DB}" <<'SQL'
SELECT format('CREATE DATABASE %I', :'test_db')
WHERE NOT EXISTS (
    SELECT 1
    FROM pg_database
    WHERE datname = :'test_db'
)\gexec
SQL
