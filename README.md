# AWIN data export to MariaDB (cron, Vimexx)

## Install
1. In DirectAdmin → **MySQL Management**, create a database and a user (e.g. `USER_awin`).
2. Upload `awin_cron.php`, `schema.sql` and `config.sample.php` to a folder **outside** `public_html`, e.g. `/home/USER/awin/`.
3. Copy `config.sample.php` to `config.php`. Fill in your API token (from https://ui.awin.com/awin-api) and the database details. `config.php` is listed in `.gitignore`, so never commit it.
4. Test it over SSH, or start with a one-off cron run: `php /home/USER/awin/awin_cron.php`. The tables are created automatically on the first run.
5. If you want history, run a backfill once (transactions are fetched in 31-day chunks):
   `php awin_cron.php --from=2024-01-01 --only=transactions`

## Cron (DirectAdmin → Advanced Features → Cron Jobs)
```
15 */4 * * *  /usr/local/bin/php /home/USER/awin/awin_cron.php > /dev/null 2>&1
```
Runs every 4 hours. Static data (programmes, programme details, commission groups, offers) only refreshes once every `static_refresh_hours`. Run `which php` over SSH to check the PHP path.

## Tables
| Table | Contents |
|---|---|
| `awin_accounts` | All accounts your AWIN user can access (`GET /accounts`). Unless you set `publishers` in `config.php`, its publisher accounts are the sites the script processes |
| `awin_programmes` | Programmes per site with your relationship (joined, pending, suspended, rejected; not-joined programmes are skipped) |
| `awin_programme_details` | Programme details per joined advertiser |
| `awin_commission_groups` | Current commission groups per joined advertiser |
| `awin_promotions` | Offers and vouchers. `is_active = 0` means AWIN no longer returns it as active |
| `awin_transactions` | Transactions, updated when their status changes |
| `awin_report_rows` | Advertiser, creative and campaign reports, one row per line per day per region |
| `awin_sync_state` | When the static data was last refreshed |

Every table has a `raw` JSON column with the complete AWIN record, so fields without their own column can still be queried:
`SELECT JSON_VALUE(raw, '$.advertiserName') FROM awin_report_rows`.

## Notes
- AWIN allows 20 calls per minute, and the script stays under that limit. A full static refresh makes about 2 calls per joined advertiser.
- Logs are in `logs/awin-YYYY-MM-DD.log`.

## License
MIT, see [LICENSE](LICENSE).
