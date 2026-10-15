# BKB Digital Banking provider

This importer fork adds a `bkb` provider that talks to a private browser
service ("bkb-bot") on the internal Docker network. The connector is never
exposed: browsers only reach server-side PHP proxy endpoints, and BKB
management needs its own administrative login.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `BKB_BOT_URL` | `http://bkb-bot:8080` | Base URL of the internal browser service. |
| `BKB_API_TOKEN` | empty | Internal Bearer token for the bot API. |
| `BKB_API_TOKEN_FILE` | empty | File holding the token (Docker secrets style); wins when readable. |
| `BKB_ADMIN_PASSWORD_HASH` | empty | `password_hash()` of the BKB admin password. Empty disables management. |
| `BKB_ADMIN_PASSWORD_HASH_FILE` | empty | File holding the hash; wins when readable. |

Generate the admin hash with:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

## Setup

1. Start the bot service on the private network (port 8080, no public port).
2. Configure the variables above on the importer.
3. Open `/authenticate-flow/bkb` or `/new-import/bkb`; both lead to BKB
   management (`/bkb`) after the administrative login.
4. On the management page: check the service state, open the bank browser,
   log in on the bank page, save the account statement URL and date range.
5. Fetch a sample CSV to run the normal file-flow column mapping. Finish the
   mapping, use the download option on the configuration step, and paste the
   downloaded JSON into the import configuration field so automated imports
   reuse the exact same mapping.
6. Unpause and either wait for the hourly run or press "Sync now".

Public endpoints (`/api/import-flows`, `/validate/bkb`,
`/api/import-flows/validate/bkb`) only report whether the service is
configured and reachable; bank state stays behind the admin login.
