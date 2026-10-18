<?php

// cron task "Jobber": runs the pending jobs of the queue, $params: max_jobs, max_seconds
$result = \Core\Jobber::run((int)($params['max_jobs'] ?? 100), (int)($params['max_seconds'] ?? 50), function(string $line){ echo $line.CR; });
echo "{$result['completed']} job(s) completed, {$result['failed']} failed".CR;
