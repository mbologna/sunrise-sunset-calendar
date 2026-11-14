<?php

// Prints, as JSON, what PHP 8.5 returns for the primitives the calendar's
// output depends on, over awkward inputs: rounding, float-to-string, the
// input filters, strip_tags(), mb_substr(), urlencode(), parse_str(),
// htmlspecialchars(), date() and wall-clock time zone arithmetic.
// tests/test_php.py checks the Python equivalents against the results, recorded
// at two moments (mktime() resolves repeated wall times by the current DST):
//   tests/php_reference/record.sh

mt_srand(20261114);
$b = fn(string $s) => base64_encode($s);

$floats = [0.5, 1.5, 2.5, -0.5, -1.5, -0.4, -0.6, 0.49999999999999994, 1.45, 1.55, 0.285, 1.005, 2.675,
    -2.675, 12.35, 12.45, 1e15 + 0.5, 4503599627370495.5, -0.0, 0.0, 99.95, 5.05, -5.05, 0.05, 1234.5678];
for ($i = 0; $i < 300; $i++) {
    $floats[] = (mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(-3, 6);
}
$round = [];
foreach ($floats as $f) {
    $round[] = [$f, round($f), round($f, 1), round($f, 2)];
}

$strs = [];
foreach (array_merge($floats, [41.9028, 12.4964, 1e-5, 1e-4, 0.0001234, 1e14, 1e15, 123456789012345.0,
    -0.0, 0.1 + 0.2, 1 / 3, 2 / 3, 90.0, -180.0, 45.123456789012345, 1.0E+25, 5e-324]) as $f) {
    $strs[] = [$f, (string) $f];
}

$filterInputs = ['45.5', '-45.5', ' 30 ', "\t12\n", '+10', '-0', '-0.0', '0', '00', '010', '1.5', '1e3', '1E+2',
    '4.19e1', '.5', '5.', '-.5', '1e', '1e+', '.', '-', '+', '', ' ', 'abc', '12abc', '0x1A', '1e400', '1e-400',
    '0e5', '0.0', '1,5', '1 000', '9223372036854775807', '9223372036854775808', '-9223372036854775808',
    '-9223372036854775809', '99999999999999999999', '00000000000000000000045', '1441', '-1440', "\v7", "7\0",
    '٣', '1_000', 'inf', 'NAN', '0.00001', '-1e-5', '1.7976931348623157e308', '2e308'];
$filters = [];
foreach ($filterInputs as $s) {
    $fl = filter_var($s, FILTER_VALIDATE_FLOAT);
    $in = filter_var($s, FILTER_VALIDATE_INT);
    $filters[] = [$b($s), $fl === false ? null : $fl, $in === false ? null : $in,
        $fl === false ? null : (string) $fl];
}

$tagInputs = ['Hello World', '<script>Hello World</script>', '<b>Bold</b> text', 'a < b and c > d', 'a <b ok',
    '<!-- comment -->x', '<!-- a > b -->y', '<!DOCTYPE html>z', '<?php echo 1; ?>w', '<?xml version="1.0"?>v',
    '<a href="x>y">link</a>', "<a title='it\\'s'>q</a>", '<<b>>nested', 'x<y', '1 <2', "tab<\tx>", 'a>b',
    '<p>unterminated', '"quoted" <i>tag</i>', '<a b="<c>">d</a>e', "<!x a='>'>f", "nul\0byte", '<!-->g-->h',
    '<?= $x ?>i', '<? (">") ?>j', "<a\n>k", 'l<!--', '<<<', '>>>', '<>', '< >', 'é<b>é</b>'];
$tags = [];
foreach ($tagInputs as $s) {
    $tags[] = [$b($s), $b(strip_tags($s))];
}

$mbInputs = ["caf\xE9", "\xC3\x28", "\xE2\x82", "\xE2\x82\xAC", "\xF0\x9F\x8C", "\xF0\x9F\x8C\x85",
    "\xED\xA0\x80", "\xC0\xAF", "\xF4\x90\x80\x80", "\xF8\x88\x80\x80\x80", "abc\xFF", "\xE0\x80\x80",
    "\xF0\x80\x80\x80", "\xE2\x82x", "\xF1\x80", "\xF1\x80\x80", str_repeat('é', 600), str_repeat('a', 600),
    "x\xE2", "\xC2"];
$mb = [];
foreach ($mbInputs as $s) {
    $mb[] = [$b($s), $b(mb_substr($s, 0, 500, 'UTF-8')), $b(mb_substr($s, 0, 2, 'UTF-8'))];
}

$urls = [];
foreach (['a b', '~tilde', 'é', '*!\'()', 'a&b=c', "\x00\xFF", 'Europe/Rome', '-_.'] as $s) {
    $urls[] = [$b($s), urlencode($s)];
}

$queries = ['a=1&b=2', 'a=1&a=2', 'a[]=1', 'a[x]=1&b=2', 'a.b=1', 'a b=1', 'a[b=1', 'a.b[c=1', ' a=1', '=1',
    '[a]=1', 'a', 'a=', 'a=%E9', 'a=1+2', '%61=1', 'a%00b=1', 'a[=1', 'a]=1', 'a[b]c=1', '&&a=1&&', 'a=1;b=2'];
$parsed = [];
foreach ($queries as $q) {
    parse_str($q, $out);
    $parsed[] = [$q, array_map(fn($v) => is_array($v) ? 'ARRAY' : $b($v), $out)];
}

$html = [];
foreach (['<a href="x">it\'s & more</a>', 'plain', "\"'<>&"] as $s) {
    $html[] = [$s, htmlspecialchars($s)];
}

// Wall-clock arithmetic around DST changes, including changes at midnight
$zones = ['Europe/Rome', 'America/New_York', 'America/Santiago', 'America/Havana', 'Asia/Beirut', 'Africa/Cairo',
    'America/Asuncion', 'Australia/Lord_Howe', 'Pacific/Chatham', 'Europe/Dublin', 'Africa/Casablanca', 'UTC'];
$clock = [];
foreach ($zones as $zone) {
    $tz = new DateTimeZone($zone);
    date_default_timezone_set($zone);
    foreach ($tz->getTransitions(mktime(0, 0, 0, 1, 1, 2026), mktime(0, 0, 0, 1, 1, 2029)) as $t) {
        $ts = $t['ts'];
        foreach ([-1, 0, 1] as $d) {
            $day = (new DateTimeImmutable('@' . ($ts + $d * 86400)))->setTimezone($tz);
            $midnight = $day->setTime(0, 0);
            $date = $day->format('Y-m-d');
            $today = new DateTimeImmutable($date, $tz);
            $clock[] = [
                $zone, $ts + $d * 86400, $date,
                $midnight->getTimestamp(), $midnight->format('Y-m-d H:i:s'),
                $today->getTimestamp(), $today->modify('+1 day')->getTimestamp(),
                $today->modify('-1 day')->format('Y-m-d H:i'), $today->setTime(12, 0)->getOffset(),
                mktime(0, 0, 0, (int) $day->format('n'), 1, (int) $day->format('Y')),
                mktime(23, 59, 59, (int) $day->format('n') + 1, 0, (int) $day->format('Y')),
                mktime(2, 30, 0, (int) $day->format('n'), (int) $day->format('j'), (int) $day->format('Y')),
                strtotime('+3 days', $ts + $d * 86400),
                strtotime('first day of this month', $ts + $d * 86400),
                strtotime('+1 month', strtotime('first day of this month', $ts + $d * 86400)),
                date('D, M j, H:i', $ts + $d * 86400), date('l, F j, Y w', $ts + $d * 86400),
            ];
        }
    }
}

echo json_encode([
    'now' => time(), 'round' => $round, 'float_to_str' => $strs, 'filters' => $filters, 'strip_tags' => $tags,
    'mb_substr' => $mb, 'urlencode' => $urls, 'parse_str' => $parsed, 'htmlspecialchars' => $html,
    'clock' => $clock,
], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
