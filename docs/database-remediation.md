# Database Remediation (Doctrine Doctor)

## 1) Proxy auto-generation in production

Already enforced in app config:
- `auto_generate_proxy_classes: false` by default.
- `auto_generate_proxy_classes: true` only in `dev`.
- Explicit production override file:
  - `config/packages/prod/doctrine.yaml`

File:
- `config/packages/doctrine.yaml`
- `config/packages/prod/doctrine.yaml`

Deployment command (pre-generate proxies in prod build step):

```powershell
php bin/console cache:clear --env=prod --no-debug
php bin/console doctrine:generate:proxies --env=prod --no-debug
```

## 2) Mixed table collations

Run:
- `sql/db_remediation_doctrine_doctor.sql`

This script:
- aligns database default collation,
- converts tables that differ from that default,
- prints verification queries.

## 3) InnoDB buffer pool too small (XAMPP)

Edit `C:\xampp\mysql\bin\my.ini`:
- `innodb_buffer_pool_size=16M` -> recommended `512M` in local dev (or 50-70% of RAM).

Then restart MySQL.

## 4) `innodb_flush_log_at_trx_commit` in dev

In development only, set in `C:\xampp\mysql\bin\my.ini`:
- `innodb_flush_log_at_trx_commit=2`

Keep `1` in production.

Restart MySQL after change.

## 5) MySQL timezone tables empty

On this XAMPP setup, timezone SQL data file exists at:
- `C:\xampp\mysql\share\mysql_test_data_timezone.sql`

Load it:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root mysql < C:\xampp\mysql\share\mysql_test_data_timezone.sql
```

Verify:

```sql
SELECT COUNT(*) AS timezone_rows FROM mysql.time_zone_name;
```

## 6) Decimal scale 8 warnings on crypto prices

`scale=8` on:
- `Crypto::$currentprice`
- `Investissement::$buyPrice`

is intentional for crypto precision and should not be reduced to 2 for this domain.

## 7) Quick validation checklist

```sql
SHOW VARIABLES LIKE 'collation_database';
SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';
SELECT COUNT(*) AS timezone_rows FROM mysql.time_zone_name;
```

Expected in local dev:
- `collation_database`: same as tables after remediation script
- `innodb_buffer_pool_size`: >= `134217728` (128MB), ideally `536870912` (512MB)
- `innodb_flush_log_at_trx_commit`: `2`
- `timezone_rows`: > `0`
