# Testing

## One-time setup

The suite runs against a **dedicated test database**. It never uses your
development database.

```bash
composer install
php artisan db:create-test-database
```

`db:create-test-database` creates `denthub_testing` using the host, username and
password from your own `.env`. It refuses to create any database whose name does
not end in `_test` or `_testing`.

No credentials are committed. `phpunit.xml` pins only the database **name**;
everything else is inherited from your local `.env` (or from CI environment
variables).

## Running tests

| Command | What it does |
| --- | --- |
| `php artisan test` | Full suite |
| `php artisan test --testsuite=Security` | Security regression suite only |
| `php artisan test --testsuite=Feature` | Feature tests only |
| `php artisan test --testsuite=Unit` | Unit tests only |
| `php artisan test --filter=OwnershipBoundaryTest` | One test class |
| `php vendor/bin/phpunit --testdox` | Full suite with readable names |
| `composer test` | Alias for `php artisan test` |
| `composer test:security` | Alias for the security suite |

## Code style

Laravel Pint is already a dev dependency. Configuration lives in `pint.json`
(Laravel preset).

| Command | What it does |
| --- | --- |
| `composer lint:changed` | Check style on changed files — **this is what CI enforces** |
| `composer lint` | Check style across the whole codebase |
| `composer lint:fix` | Apply fixes |

The legacy codebase has a large pre-existing style baseline (~142 files). It was
deliberately **not** reformatted, because a mass rewrite would bury real changes
in noise. CI therefore only enforces style on files you actually touch.

## Safety

Two independent guards stop the suite from destroying real data:

1. `tests/TestCase::guardTestDatabase()` runs before any database trait opens a
   connection and aborts the run unless `APP_ENV` is `testing` **and** the
   configured database name ends in `_test` / `_testing`.
2. `db:create-test-database` refuses to create a non-test database.

To verify guard 1 yourself:

```bash
DB_DATABASE=dentallink php artisan test --filter=OwnershipBoundaryTest
```

That must fail with "Refusing to run tests against a database that is not
clearly a test database" and must not touch the database.

## Database reset strategy

The suite uses **`RefreshDatabase`**. It migrates the test database once per run
and wraps each test in a transaction that is rolled back afterwards, so tests are
isolated and order-independent without paying migration cost per test.

`DatabaseTransactions` was rejected because it assumes an already-migrated
database and would silently drift when a migration is added. `DatabaseMigrations`
was rejected because re-migrating per test is far too slow against MySQL.

The test database must be MySQL/MariaDB: three migrations use raw
`ALTER TABLE ... MODIFY COLUMN ... ENUM` statements, which SQLite cannot execute.
Making the suite driver-portable is future work.

## Test layout

```
tests/
  Feature/
    Auth/         registration, login, tokens
    Cart/         cart access and ownership
    Financial/    ledger arithmetic baseline (documents known defects)
    Orders/       order lifecycle baseline
    Payments/     payment access and retrieval
    Pricing/      price-snapshot baseline (documents known defects)
    Products/     catalogue behaviour and ownership
    Security/     attack surface, hardening invariants, IDOR, privilege escalation
  Unit/
```

Tests are named after the behaviour they protect, not the code they call —
`a_supplier_cannot_update_another_suppliers_product`, not `test_update_method`.

## Tests that document defects

Several tests assert behaviour that is **known to be wrong**. They exist so that
later remediation stages change behaviour deliberately rather than accidentally.
Each carries a docblock beginning with `DEFECT CONFIRMED` and names the stage
that owns the fix. When that stage lands, the assertion is inverted.

Do not "fix" a failing assertion in one of those tests by weakening it.

## Known limitations

- **Parallel testing is not enabled.** `brianium/paratest` is not installed, and
  the suite shares one test database, so parallel workers would collide. Future
  work.
- **No coverage reporting.** No coverage driver (Xdebug/PCOV) is configured.
- **SQLite is unsupported**, see above.
