# Provisioning the database

One-time steps that need privileges the application role does not have, and
deliberately should not have. Migrations assume all of this is already done and
fail with an explicit message if it is not.

Run as a Postgres superuser, once per environment.

## 1. Role and databases

The application connects as the role in `DB_USERNAME`. On the current
development machine that role is **`edumaba`**; on a server it should be a
dedicated role that owns nothing else.

```sql
CREATE ROLE <db_username> LOGIN PASSWORD '...';
CREATE DATABASE mdr_gh OWNER <db_username>;
CREATE DATABASE mdr_gh_testing OWNER <db_username>;   -- the test suite wipes this
```

Where the role already exists and the databases belong to `postgres`, as on the
development machine, the equivalent is:

```sql
ALTER ROLE edumaba CREATEDB;
ALTER DATABASE mdr_gh OWNER TO edumaba;
```

`CREATEDB` is only needed to create the test database; the application itself
never creates one.

## 2. Extensions

`pg_trgm` backs fuzzy name matching, which is what duplicate detection at
registration depends on. Without it the patients migration refuses to run.

```sql
\c mdr_gh
CREATE EXTENSION pg_trgm;

\c mdr_gh_testing
CREATE EXTENSION pg_trgm;
```

`CREATE EXTENSION` needs superuser unless the role owns the database, so this
is usually a `sudo -u postgres psql` away:

```sh
sudo -u postgres psql -d mdr_gh -c "CREATE EXTENSION pg_trgm;"
sudo -u postgres psql -d mdr_gh_testing -c "CREATE EXTENSION pg_trgm;"
```

The test database needs it too: duplicate detection is the core safety feature
of the registry, and a suite that cannot exercise `similarity()` is not testing
the thing most likely to cause patient harm.

## 3. Verify

```sql
SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_trgm';
```

Then, from the application:

```
php artisan migrate
php artisan test --compact
```

## Why the extension is not created by a migration

`CREATE EXTENSION` needs superuser or database ownership. Granting either to
the role the web application authenticates as, in production, to save one
provisioning command, trades a permanent privilege escalation for a one-off
convenience. The migration asserts instead, and names the command to run.
