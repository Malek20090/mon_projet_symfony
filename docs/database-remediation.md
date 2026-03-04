# Database Remediation (Doctrine Doctor)

## 1) Proxy auto-generation in production

Already enforced in app config:
- `auto_generate_proxy_classes: false` by default.
- `auto_generate_proxy_classes: true` only in `dev`.

File:
- `config/packages/doctrine.yaml`

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
