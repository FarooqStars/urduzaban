<?php
/* ============================================================
   urduzaban.com — «واہ واہ!» ایپ کا server (Nizam FA-022 · AI1-Claude1 · 21 Nov 2026)
   مالک کا فیصلہ: اردو زبان ہی ایپ کا ڈیٹا گھر ہے؛ ایپ صرف فون اور ٹیبلٹ پر، ویب پر کوئی کھیل نہیں۔

   عوامی (بغیر چابی):
     GET ?action=day&d=YYYY-MM-DD[&c=1&p=android&v=0.1.10&l=ur]  → اُس دن کے 5 لطیفے + 1 پہیلی
         c=1 ہو تو صرف گنتی بڑھتی ہے (کون سا فون، کون سا ورژن) — کوئی آئی پی، نام یا شناخت محفوظ نہیں ہوتی
     GET ?action=config                                          → اسٹور کے لنک، اعلان
     GET ?action=health                                          → حالت (کوئی راز نہیں)
     GET ?action=kids                                            → بچوں کا کونا: زمرے + تصویریں (22 نومبر)
     GET ?action=score&g=weekly&s=N&wk=2026-W47                  → ہفتہ وار مقابلے کی گنتی (صرف اسکور، کوئی شناخت نہیں)
   انتظام (POST، وہی چابی جو uz-admin / lexicon.php کی ہے: uz-private/uz-config.php):
     overview · remove · restore · edit · add · config_save · rebuild · kids_save · kids_remove

   قاعدے: روز 5 لطیفے اور 1 پہیلی؛ کوئی چیز 30 دن سے پہلے دوبارہ نہیں آتی؛
          آج اور گزرے دن کبھی نہیں بدلتے (جو لوگ دیکھ چکے وہی رہے)، تبدیلی کل سے لاگو ہوتی ہے۔
   ذخیرہ: public_html سے باہر  uz-private/wahwah/  (git deploy اسے نہیں چھوتا)
   پہلی بار: ریپو کی  wahwah/v1/pool.json  اور  wahwah/v1/calendar.json  سے بھرتا ہے۔
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Qatar');

$ROOT = dirname(__DIR__);
$PRIV = dirname($ROOT) . '/uz-private';
if (!is_dir($PRIV)) @mkdir($PRIV, 0700, true);
$BASE = (is_dir($PRIV) && is_writable($PRIV)) ? $PRIV : $ROOT . '/uz-data';
$DIR  = $BASE . '/wahwah';
if (!is_dir($DIR)) @mkdir($DIR, 0700, true);
if (!is_dir($DIR . '/stats')) @mkdir($DIR . '/stats', 0700, true);
$CFG = $BASE . '/uz-config.php';
$KEY = '';
if (is_file($CFG)) { $c = @include $CFG; if (is_array($c) && !empty($c['admin_key'])) $KEY = (string)$c['admin_key']; }

const GAP = 30; const JOKES = 5; const AHEAD = 60;

function readj($f, $d) { if (is_file($f)) { $j = json_decode(file_get_contents($f), true); if (is_array($j)) return $j; } return $d; }
function writej($f, $j) { $t = $f . '.tmp'; file_put_contents($t, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX); return rename($t, $f); }
function out($j, $code = 200) { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; }
function today() { return date('Y-m-d'); }
function dnum($d) { return intdiv(strtotime($d . ' 12:00:00 UTC'), 86400); }
function dstr($n) { return gmdate('Y-m-d', $n * 86400 + 43200); }
function clean($s, $max = 2000) { $s = trim(str_replace("\r", '', (string)$s)); return mb_substr($s, 0, $max); }

/* ---------- ذخیرہ ---------- */
function pool() {
    global $DIR, $ROOT;
    $f = $DIR . '/pool.json';
    $p = readj($f, null);
    if ($p === null) { $p = readj($ROOT . '/wahwah/v1/pool.json', ['jokes' => [], 'riddles' => []]); writej($f, $p); }
    return $p;
}
function cal() {
    global $DIR, $ROOT;
    $f = $DIR . '/calendar.json';
    $c = readj($f, null);
    if ($c === null) { $s = readj($ROOT . '/wahwah/v1/calendar.json', ['days' => []]); $c = $s['days'] ?? []; writej($f, $c); }
    return $c;
}
function logline($what) {
    global $DIR;
    $f = $DIR . '/log.json'; $l = readj($f, []);
    $l[] = ['t' => date('Y-m-d H:i'), 'what' => $what];
    writej($f, array_slice($l, -300));
}
function kids() {
    global $DIR, $ROOT;
    $f = $DIR . '/kids.json';
    $k = readj($f, null);
    if ($k === null) { $k = readj($ROOT . '/wahwah/kids/kids.json', ['categories' => [], 'items' => []]); writej($f, $k); }
    return $k;
}
function active($items) { $o = []; foreach ($items as $i) if (($i['status'] ?? 'draft') !== 'no') $o[$i['id']] = $i; return $o; }

/* ---------- کیلنڈر: کل سے آگے نیا بناتا ہے، آج اور ماضی کو نہیں چھوتا ---------- */
function plan_from($fromDay, $until) {
    global $DIR;
    $p = pool(); $c = cal();
    $J = active($p['jokes']); $R = active($p['riddles']);
    $from = dnum($fromDay); $end = dnum($until);
    foreach (array_keys($c) as $d) if (dnum($d) >= $from) unset($c[$d]);
    $last = [];
    ksort($c);
    foreach ($c as $d => $v) { $n = dnum($d); foreach ($v['jokes'] as $id) $last[$id] = $n; $last[$v['riddle']] = $n; }
    $order = function ($ids, $n) use (&$last) {
        $free = array_values(array_filter($ids, function ($id) use (&$last, $n) { return !isset($last[$id]) || $n - $last[$id] > GAP; }));
        usort($free, function ($a, $b) use (&$last) {
            $la = $last[$a] ?? -1000000; $lb = $last[$b] ?? -1000000;
            return $la === $lb ? (crc32($a . 'w') <=> crc32($b . 'w')) : ($la <=> $lb);
        });
        return $free;
    };
    $answers = []; foreach ($R as $r) $answers[] = $r['a'];
    for ($n = $from; $n <= $end; $n++) {
        $fj = $order(array_keys($J), $n); $fr = $order(array_keys($R), $n);
        if (count($fj) < JOKES || !$fr) break;      /* ذخیرہ کم ہو تو رک جائے — ایپ اپنا کیلنڈر استعمال کرے گی */
        $pick = array_slice($fj, 0, JOKES); $rid = $fr[0];
        foreach ($pick as $id) $last[$id] = $n; $last[$rid] = $n;
        $others = array_values(array_filter($answers, function ($a) use ($R, $rid) { return $a !== $R[$rid]['a']; }));
        usort($others, function ($a, $b) use ($n) { return crc32($a . $n) <=> crc32($b . $n); });
        $opts = array_slice($others, 0, 3); $opts[] = $R[$rid]['a'];
        usort($opts, function ($a, $b) use ($n) { return crc32($n . $a) <=> crc32($n . $b); });
        $c[dstr($n)] = ['jokes' => $pick, 'riddle' => $rid, 'options' => $opts];
    }
    ksort($c);
    writej($DIR . '/calendar.json', $c);
    return $c;
}
function ensure($day) {
    $c = cal();
    if (!isset($c[$day])) {
        $keys = array_keys($c); sort($keys);
        $start = $keys ? dstr(max(dnum(end($keys)) + 1, dnum(today()))) : today();
        $c = plan_from($start, dstr(max(dnum($day), dnum(today()) + AHEAD)));
    }
    return $c;
}
function day_json($day) {
    $c = ensure($day);
    if (!isset($c[$day])) return null;
    $p = pool();
    $J = []; foreach ($p['jokes'] as $j) $J[$j['id']] = $j;
    $R = []; foreach ($p['riddles'] as $r) $R[$r['id']] = $r;
    $v = $c[$day]; $jokes = [];
    foreach ($v['jokes'] as $id) if (isset($J[$id])) $jokes[] = ['id' => $id, 'text' => $J[$id]['text']];
    $r = $R[$v['riddle']] ?? null;
    return ['day' => $day, 'from' => 'urduzaban-live', 'jokes' => $jokes,
            'riddle' => $r ? ['id' => $r['id'], 'q' => $r['q'], 'a' => $r['a'], 'hint' => $r['hint'] ?? '', 'options' => $v['options']] : null];
}

/* ---------- گنتی (کوئی ذاتی معلومات نہیں) ---------- */
function count_hit($day) {
    global $DIR;
    $tag = function ($k, $max = 16) { return preg_replace('/[^a-z0-9.\-]/', '', strtolower(substr((string)($_GET[$k] ?? ''), 0, $max))) ?: 'x'; };
    $f = $DIR . '/stats/' . substr($day, 0, 7) . '.json';
    $h = fopen($f . '.lock', 'c'); if ($h) flock($h, LOCK_EX);
    $s = readj($f, []);
    $e = $s[$day] ?? ['n' => 0, 'p' => [], 'v' => [], 'l' => []];
    $e['n']++;
    foreach (['p', 'v', 'l'] as $k) { $t = $tag($k); $e[$k][$t] = ($e[$k][$t] ?? 0) + 1; }
    $s[$day] = $e; writej($f, $s);
    if ($h) { flock($h, LOCK_UN); fclose($h); }
}

$act = $_REQUEST['action'] ?? 'health';

if ($act === 'health') {
    $p = pool(); $c = cal();
    out(['ok' => true, 'store' => $BASE === $PRIV ? 'outside' : 'public_html', 'writable' => is_writable($DIR),
         'jokes' => count(active($p['jokes'])), 'riddles' => count(active($p['riddles'])), 'days' => count($c), 'config' => is_file($CFG)]);
}
if ($act === 'config') {
    out(readj($DIR . '/config.json', ['store_play' => '', 'store_apple' => '', 'notice_ur' => '', 'notice_en' => '', 'notice_until' => '', 'poet_week' => '', 'poet_until' => '']) + ['ok' => true]);
}
if ($act === 'kids') {
    $k = kids();
    $k['items'] = array_values(array_filter($k['items'], function ($i) { return ($i['status'] ?? 'ok') !== 'no'; }));
    out($k + ['ok' => true]);
}
if ($act === 'score') {
    $g = preg_replace('/[^a-z]/', '', (string)($_GET['g'] ?? ''));
    $wk = preg_match('/^\d{4}-W\d{2}$/', $_GET['wk'] ?? '') ? $_GET['wk'] : '';
    $sc = max(0, min(999, (int)($_GET['s'] ?? 0)));
    if ($g !== 'weekly' || $wk === '') out(['ok' => false, 'err' => 'bad'], 400);
    $f = $DIR . '/stats/weekly.json';
    $h = fopen($f . '.lock', 'c'); if ($h) flock($h, LOCK_EX);
    $w = readj($f, []);
    $e = $w[$wk] ?? ['n' => 0, 'sum' => 0, 'max' => 0];
    $e['n']++; $e['sum'] += $sc; $e['max'] = max($e['max'], $sc);
    $w[$wk] = $e; writej($f, array_slice($w, -60, null, true));
    if ($h) { flock($h, LOCK_UN); fclose($h); }
    out(['ok' => true]);
}
if ($act === 'day') {
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'] ?? '') ? $_GET['d'] : today();
    $n = dnum($d); $t = dnum(today());
    if ($n < $t - 2 || $n > $t + 10) out(['ok' => false, 'err' => 'range'], 404);
    $j = day_json($d);
    if (!$j) out(['ok' => false, 'err' => 'empty'], 404);
    if (($_GET['c'] ?? '') === '1') count_hit($d);
    out($j);
}

/* ---------- یہاں سے آگے صرف چابی کے ساتھ ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'err' => 'post'], 405);
if (!is_string($KEY) || strlen($KEY) < 16) out(['ok' => false, 'err' => 'noconfig'], 503);
if (!hash_equals($KEY, (string)($_POST['key'] ?? ''))) out(['ok' => false, 'err' => 'key'], 403);

$tomorrow = dstr(dnum(today()) + 1);
$p = pool();

if ($act === 'overview') {
    $c = ensure(dstr(dnum(today()) + 14));
    /* سارے دن: گزرے، آج، اور آگے کے (مالک 22 نومبر: پورا ڈیٹا ایک جگہ) */
    $days = [];
    foreach (array_keys(cal()) as $d) { $j = day_json($d); if ($j) $days[] = $j; }
    $stats = [];
    for ($m = 0; $m < 3; $m++) { $mk = date('Y-m', strtotime(date('Y-m-01') . " -$m month")); $stats += readj($DIR . "/stats/$mk.json", []); }
    krsort($stats);
    $cl = cal(); $lastDay = $cl ? max(array_keys($cl)) : '';
    out(['ok' => true, 'today' => today(), 'days' => $days, 'pool' => $p, 'stats' => array_slice($stats, 0, 60, true),
         'config' => readj($DIR . '/config.json', new stdClass()), 'log' => array_reverse(readj($DIR . '/log.json', [])), 'planned_until' => $lastDay,
         'kids' => kids(), 'weekly' => readj($DIR . '/stats/weekly.json', new stdClass())]);
}
$find = function ($id) use (&$p) {
    foreach (['jokes', 'riddles'] as $k) foreach ($p[$k] as $i => $it) if ($it['id'] === $id) return [$k, $i];
    return [null, null];
};
if ($act === 'remove' || $act === 'restore') {
    [$k, $i] = $find((string)($_POST['id'] ?? ''));
    if ($k === null) out(['ok' => false, 'err' => 'id'], 404);
    $p[$k][$i]['status'] = $act === 'remove' ? 'no' : 'ok';
    writej($DIR . '/pool.json', $p);
    plan_from($tomorrow, dstr(dnum(today()) + AHEAD));
    logline(($act === 'remove' ? 'removed ' : 'restored ') . $p[$k][$i]['id']);
    out(['ok' => true]);
}
if ($act === 'edit') {
    [$k, $i] = $find((string)($_POST['id'] ?? ''));
    if ($k === null) out(['ok' => false, 'err' => 'id'], 404);
    if ($k === 'jokes') { $t = clean($_POST['text'] ?? ''); if ($t === '') out(['ok' => false, 'err' => 'empty'], 400); $p[$k][$i]['text'] = $t; }
    else foreach (['q', 'a', 'hint'] as $f) if (isset($_POST[$f]) && clean($_POST[$f]) !== '') $p[$k][$i][$f] = clean($_POST[$f], 600);
    $p[$k][$i]['status'] = 'ok';
    writej($DIR . '/pool.json', $p);
    logline('edited ' . $p[$k][$i]['id']);
    out(['ok' => true]);
}
if ($act === 'add') {
    $type = ($_POST['type'] ?? '') === 'riddle' ? 'riddles' : 'jokes';
    $pre = $type === 'jokes' ? 'joke-' : 'riddle-';
    $max = 0; foreach ($p[$type] as $it) $max = max($max, (int)preg_replace('/\D/', '', $it['id']));
    $id = $pre . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    if ($type === 'jokes') { $t = clean($_POST['text'] ?? ''); if ($t === '') out(['ok' => false, 'err' => 'empty'], 400); $p[$type][] = ['id' => $id, 'text' => $t, 'status' => 'ok']; }
    else { $q = clean($_POST['q'] ?? '', 600); $a = clean($_POST['a'] ?? '', 100);
           if ($q === '' || $a === '') out(['ok' => false, 'err' => 'empty'], 400);
           $p[$type][] = ['id' => $id, 'q' => $q, 'a' => $a, 'hint' => clean($_POST['hint'] ?? '', 200), 'status' => 'ok']; }
    writej($DIR . '/pool.json', $p);
    logline('added ' . $id);
    out(['ok' => true, 'id' => $id]);
}
if ($act === 'rebuild') {
    plan_from($tomorrow, dstr(dnum(today()) + AHEAD));
    logline('calendar rebuilt from ' . $tomorrow);
    out(['ok' => true]);
}
if ($act === 'kids_save') {
    $k = kids();
    $id = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['id'] ?? ''));
    $cat = preg_replace('/[^a-z]/', '', (string)($_POST['cat'] ?? ''));
    $cats = array_column($k['categories'], 'id');
    if (!in_array($cat, $cats, true)) out(['ok' => false, 'err' => 'cat'], 400);
    $item = ['cat' => $cat, 'ur' => clean($_POST['ur'] ?? '', 60), 'en' => clean($_POST['en'] ?? '', 60), 'status' => 'ok'];
    if ($item['ur'] === '') out(['ok' => false, 'err' => 'empty'], 400);
    foreach (['img', 'page'] as $f) { $u = clean($_POST[$f] ?? '', 500); if ($u !== '' && !preg_match('#^https://#', $u)) out(['ok' => false, 'err' => 'url'], 400); if ($u !== '') $item[$f] = $u; }
    foreach (['credit', 'license', 'color'] as $f) if (clean($_POST[$f] ?? '') !== '') $item[$f] = clean($_POST[$f], 120);
    $found = false;
    foreach ($k['items'] as $i => $it) if ($it['id'] === $id) { $k['items'][$i] = array_merge($it, $item); $found = true; }
    if (!$found) { $max = 0; foreach ($k['items'] as $it) if (strpos($it['id'], 'pic-') === 0) $max = max($max, (int)substr($it['id'], 4));
        $id = 'pic-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT); $k['items'][] = ['id' => $id, 'key' => $id] + $item; }
    writej($DIR . '/kids.json', $k);
    logline(($found ? 'kids edited ' : 'kids added ') . $id);
    out(['ok' => true, 'id' => $id]);
}
if ($act === 'kids_remove') {
    $k = kids(); $id = (string)($_POST['id'] ?? ''); $on = ($_POST['restore'] ?? '') === '1';
    foreach ($k['items'] as $i => $it) if ($it['id'] === $id) $k['items'][$i]['status'] = $on ? 'ok' : 'no';
    writej($DIR . '/kids.json', $k);
    logline(($on ? 'kids restored ' : 'kids removed ') . $id);
    out(['ok' => true]);
}
if ($act === 'config_save') {
    $cfg = readj($DIR . '/config.json', []);
    foreach (['store_play', 'store_apple'] as $f) if (isset($_POST[$f])) { $u = clean($_POST[$f], 300); $cfg[$f] = preg_match('#^https://#', $u) ? $u : ''; }
    foreach (['notice_ur', 'notice_en'] as $f) if (isset($_POST[$f])) $cfg[$f] = clean($_POST[$f], 300);
    if (isset($_POST['poet_week'])) $cfg['poet_week'] = preg_replace('/[^a-z]/', '', (string)$_POST['poet_week']);
    if (isset($_POST['poet_until'])) $cfg['poet_until'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['poet_until']) ? $_POST['poet_until'] : '';
    if (isset($_POST['notice_until'])) $cfg['notice_until'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['notice_until']) ? $_POST['notice_until'] : '';
    $cfg['updated'] = date('Y-m-d H:i');
    writej($DIR . '/config.json', $cfg);
    logline('settings saved');
    out(['ok' => true]);
}
out(['ok' => false, 'err' => 'action'], 400);
