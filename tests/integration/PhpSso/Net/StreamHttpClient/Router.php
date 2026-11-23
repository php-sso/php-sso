<?php

// Router of the throwaway server the StreamHttpClient tests talk to.
$bytes = (int) ($_GET['bytes'] ?? 0);
$delay = (float) ($_GET['delay'] ?? 0);
$status = (int) ($_GET['status'] ?? 200);
http_response_code($status);
header('Content-Type: text/plain');
if ($delay > 0) {
    // One byte at a time, slowly: each read succeeds, but the answer never ends in time.
    for ($i = 0; $i < 50; $i++) {
        echo 'x';
        flush();
        usleep((int) ($delay * 1000000));
    }
    return;
}
echo str_repeat('a', $bytes);
