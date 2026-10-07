#!/usr/bin/php
<?php
// UNMOTION_ISOLATED_PROCESS_SERVICE_FIXTURE: never install this fixture.
pcntl_async_signals(true);
pcntl_signal(SIGTERM,static function():void{exit(0);});
echo getmypid(),"\n";flush();
while(true)usleep(100000);
