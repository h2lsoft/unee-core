<?php

// cron task "Notifications purge": deletes the receipts read for more than $params['days'] days (90 by default), then the notifications without receipt left
$days = max(1, (int)($params['days'] ?? 90));
DB()->query("DELETE FROM xcore_notification_recipient WHERE read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL :days DAY)", ['days' => $days]);
$receipts = DB()->rowCount();
DB()->query("DELETE n FROM xcore_notification n LEFT JOIN xcore_notification_recipient r ON r.xcore_notification_id = n.id WHERE r.id IS NULL AND n.created_at < DATE_SUB(NOW(), INTERVAL :days DAY)", ['days' => $days]);
echo "{$receipts} read receipt(s) and ".DB()->rowCount()." notification(s) deleted".CR;
