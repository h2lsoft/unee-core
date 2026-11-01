<?php

// cron task "Notifications purge": deletes the notifications read for more than $params['days'] days (90 by default)
$days = max(1, (int)($params['days'] ?? 90));
DB()->query("DELETE FROM xcore_notification WHERE read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL :days DAY)", ['days' => $days]);
echo DB()->rowCount()." notification(s) deleted".CR;
