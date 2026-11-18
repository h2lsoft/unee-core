<?php

// cron task "Smart links purge": hits of the smart links older than the globals smart-link.retention.months are deleted (\Core\SmartLink::purge), 0 = kept for ever
$months = \Core\SmartLink::retentionMonths();
echo ($months > 0) ? \Core\SmartLink::purge($months)." hit(s) older than {$months} month(s) deleted".CR : "smart-link.retention.months = 0: nothing purged".CR;
