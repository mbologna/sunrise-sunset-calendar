<?php

// Records every case of /work/cases.jsonl: runs request.php once per case at
// the case's moment (libfaketime) with its environment, and writes the body
// to /work/out/<id>. A case whose PHP process fails, or ends before the app's
// shutdown report, stops the run.

$faketime = '/usr/lib/faketime-dir/libfaketime.so.1';
foreach (file('/work/cases.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $env = ['AUTH_TOKEN' => $case['token'], 'CASE_JSON' => $line, 'LD_PRELOAD' => $faketime,
        'FAKETIME' => '@' . $case['now'], 'FAKETIME_DONT_FAKE_MONOTONIC' => '1', 'PATH' => getenv('PATH')]
        + $case['env'];
    $process = proc_open(
        ['php', '/recorder/request.php'],
        [1 => ['file', '/work/out/' . $case['id'], 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    $result = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($result, true);
    if ($exit !== 0 || !is_array($decoded)) {
        fwrite(STDERR, "case {$case['id']} failed ({$exit}): {$result}\n");
        exit(1);
    }
}
