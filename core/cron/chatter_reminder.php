<?php

// cron task "Chatter reminders": activities of the chatter due today or before, reminded once to their responsible (in-app, by email when asked)
echo \Core\Chatter::remind()." activity reminder(s) sent".CR;
