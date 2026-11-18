<?php

// cron task "Tracker purge": hits of the trackers older than the globals tracker.retention.months are deleted (\Core\Tracker::purge), 0 = kept for ever
$months = \Core\Tracker::retentionMonths();
echo ($months > 0) ? \Core\Tracker::purge($months)." hit(s) older than {$months} month(s) deleted".CR : "tracker.retention.months = 0: nothing purged".CR;
