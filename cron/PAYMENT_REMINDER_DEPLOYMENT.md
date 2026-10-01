# Payment reminder scheduler deployment

1. Add the values from `cron/payment-reminders.env.example` to the backend `.env`.
2. Apply `database/account_payment_reminder_lifecycle_migration.sql`.
3. Verify and repair storage:

```bash
php cron/verifyPaymentReminderDeployment.php --repair
```

4. Copy `tests/payment-reminder-test.env.example` to `tests/payment-reminder-test.env`, configure the isolated test database, then run regressions:

```bash
php tests/runPaymentReminderRegressionSuite.php
```

5. Run one live cycle, then require a healthy heartbeat:

```bash
php cron/processPaymentReminderCycle.php 50
php cron/verifyPaymentReminderDeployment.php --require-healthy
```

6. Install the entries from `cron/payment-reminders.crontab.example` for the deployment user.
7. Optionally install `cron/payment-reminders.logrotate.example` under `/etc/logrotate.d/` after correcting the backend path.

The scheduler health command exits with code `0` when healthy and `1` when failed, stale, stuck, or never successfully run:

```bash
php cron/checkPaymentReminderScheduler.php 15
```
