# SQL policy

**Who sees it:** workspace administrators under **Admin → Governance → SQL Policy**.

![SQL Policy settings](../assets/screenshots/admin-sql-policy.png){ .docs-screenshot }

## Statement families

Enable read, `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, and `TRUNCATE TABLE` according to workspace policy. **Allow all governed statement families** permits every recognized family without discarding individual selections.

## Emergency SQL fallback

Emergency fallback is a separately enabled Deployment Batch exception for one otherwise unsupported statement. It is classified as write, warned in preflight, approval-controlled, and audited. It never applies to Query Access and never permits permanently prohibited SQL categories.

![Emergency SQL fallback and policy enforcement](../assets/screenshots/admin-emergency-fallback.png){ .docs-screenshot }

Changing statement policy can affect drafts, approved work, and active sessions at the next server-side check. Review currently prepared work before tightening or expanding policy.

See [SQL statement policy](../concepts/sql-policy.md) and [Supported SQL](../reference/supported-sql.md).
