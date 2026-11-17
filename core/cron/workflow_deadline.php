<?php

// cron task "Workflow deadlines": deadlines of the workflow states passed, each handled once (\Core\Workflow::deadlines)
$counts = \Core\Workflow::deadlines((int)($params['limit'] ?? 500));
echo "{$counts['notified']} notified, {$counts['reminded']} reminded, {$counts['fired']} transition(s) fired, {$counts['closed']} closed without action, {$counts['failed']} failed".CR;
