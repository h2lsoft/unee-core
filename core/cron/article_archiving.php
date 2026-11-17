<?php

// cron task "Article archiving": articles whose archive date passed are archived (\Plugin\Website\ArticleController::archiveDue)
echo \Plugin\Website\ArticleController::archiveDue((int)($params['limit'] ?? 500))." article(s) archived".CR;
