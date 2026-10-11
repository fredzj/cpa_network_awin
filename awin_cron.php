<?php
/**
 * AWIN publisher data exporter — run from cron (PHP CLI). Saves everything into MariaDB (see schema.sql).
 *
 *   awin_accounts            GET  /accounts
 * Per publisher account ("site"):
 *   awin_programmes          GET  /publishers/{id}/programmes
 *   awin_programme_details   GET  /publishers/{id}/programmedetails      (per joined advertiser)
 *   awin_commission_groups   GET  /publishers/{id}/commissiongroups      (per joined advertiser)
 *   awin_promotions          POST /publisher/{id}/promotions
 *   awin_transactions        GET  /publishers/{id}/transactions/
 *   awin_report_rows         GET  /publishers/{id}/reports/{advertiser|creative|campaign}
 *
 * Usage:
 *   php awin_cron.php                                  normal run (lookback window from config)
 *   php awin_cron.php --from=2024-01-01 --to=2024-12-31  backfill transactions + reports
 *   php awin_cron.php --only=transactions,reports      run selected parts (static, transactions, reports)
 *   php awin_cron.php --force-static                   ignore static_refresh_hours
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

set_time_limit(0);
ini_set('memory_limit', '512M');
date_default_timezone_set('UTC');

const API_BASE = 'https://api.awin.com';
const REPORT_REGIONS = ['AT', 'AU', 'BE', 'BR', 'BU', 'CA', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IE', 'IT', 'NL', 'NO', 'PL', 'SE', 'US'];
const TRANSACTION_MAX_DAYS = 31;

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing config.php — copy config.sample.php and fill it in.\n");
    exit(1);
}
$cfg = require $configFile;

$opts = getopt('', ['from:', 'to:', 'only:', 'force-static']);
$parts = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : ['static', 'transactions', 'reports'];
$forceStatic = isset($opts['force-static']);

@mkdir($cfg['log_dir'], 0750, true);
if (!is_file($cfg['log_dir'] . '/.htaccess')) {
    @file_put_contents($cfg['log_dir'] . '/.htaccess', "Require all denied\nDeny from all\n");
}

// Prevent overlapping runs.
$lock = fopen($cfg['log_dir'] . '/.cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logMsg('Another run is still active, exiting.');
    exit(0);
}

$today = new DateTimeImmutable('today');
$runStart = gmdate('Y-m-d H:i:s');   // every row written in this run gets synced_at = $runStart

try {
    $db = new Db($cfg['db']);
    $db->migrate(__DIR__ . '/schema.sql');
    $api = new AwinClient($cfg['api_token'], (int)$cfg['max_calls_per_minute']);

    $publishers = resolvePublishers($api, $db, $cfg);
    logMsg('Publishers: ' . implode(', ', array_map(fn($id, $n) => "$id ($n)", array_keys($publishers), $publishers)));

    foreach ($publishers as $pid => $name) {
        logMsg("=== Publisher $pid ($name) ===");
        try {
            if (in_array('static', $parts, true)) {
                syncStatic($api, $db, $cfg, $pid, $forceStatic);
            }
            if (in_array('transactions', $parts, true)) {
                $from = isset($opts['from']) ? new DateTimeImmutable($opts['from']) : $today->modify('-' . (int)$cfg['transaction_lookback_days'] . ' days');
                $to = isset($opts['to']) ? new DateTimeImmutable($opts['to']) : $today;
                syncTransactions($api, $db, $cfg, $pid, $from, $to);
            }
            if (in_array('reports', $parts, true)) {
                $from = isset($opts['from']) ? new DateTimeImmutable($opts['from']) : $today->modify('-' . (int)$cfg['report_lookback_days'] . ' days');
                $to = isset($opts['to']) ? new DateTimeImmutable($opts['to']) : $today;
                syncReports($api, $db, $cfg, $pid, $from, $to);
            }
        } catch (AwinAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            logMsg("ERROR publisher $pid: " . $e->getMessage());
        }
    }
    logMsg("Done. API calls: {$api->calls}");
} catch (AwinAuthException $e) {
    logMsg('FATAL: ' . $e->getMessage() . ' — check api_token.');
    exit(2);
} catch (Throwable $e) {
    logMsg('FATAL: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}

/* ------------------------------------------------------------------ sync steps */

/**
 * GET /accounts — every account the token's user can access. Saved to awin_accounts
 * and used to find the publisher accounts ("sites") unless they are set in config.
 */
function resolvePublishers(AwinClient $api, Db $db, array $cfg): array
{
    global $runStart;
    $configured = $cfg['publishers'];
    $res = $configured
        ? tryCall(fn() => $api->get('/accounts'), 'accounts')
        : $api->get('/accounts');
    $accounts = $res === null ? [] : ($res['accounts'] ?? (array_is_list($res) ? $res : []));

    $names = [];
    foreach ($accounts as $acc) {
        $db->upsert('awin_accounts', [
            'account_id' => (int)$acc['accountId'],
            'account_name' => str($acc['accountName'] ?? null, 255),
            'account_type' => str($acc['accountType'] ?? null, 20),
            'user_role' => str($acc['userRole'] ?? null, 50),
            'raw' => json($acc),
            'synced_at' => $runStart,
        ]);
        if (strtolower($acc['accountType'] ?? '') === 'publisher') {
            $names[(int)$acc['accountId']] = (string)($acc['accountName'] ?? $acc['accountId']);
        }
    }
    if ($res !== null) {
        logMsg('accounts: ' . count($accounts));
    }

    if ($configured) {
        // [id, id] (names taken from /accounts) or [id => 'name']
        return array_is_list($configured)
            ? array_combine(array_map('intval', $configured), array_map(fn($id) => $names[(int)$id] ?? (string)$id, $configured))
            : array_map('strval', $configured);
    }
    if (!$names) {
        throw new RuntimeException('No publisher accounts found for this token.');
    }
    return $names;
}

function syncStatic(AwinClient $api, Db $db, array $cfg, int $pid, bool $force): void
{
    global $runStart;
    $last = $db->value('SELECT last_run_at FROM awin_sync_state WHERE publisher_id = ? AND part = ?', [$pid, 'static']);
    if (!$force && $last && time() - strtotime($last . ' UTC') < (int)$cfg['static_refresh_hours'] * 3600) {
        logMsg('Static data is fresh, skipping (use --force-static to override).');
        return;
    }

    // Programmes per relationship. Rows not returned anymore for a relationship are removed.
    $relationships = $cfg['programme_relationships'];
    if (!in_array('joined', $relationships, true)) {
        array_unshift($relationships, 'joined');
    }
    $joined = [];
    foreach ($relationships as $rel) {
        $list = tryCall(fn() => $api->get("/publishers/$pid/programmes", ['relationship' => $rel]), "programmes/$rel");
        if ($list === null) {
            continue;
        }
        $db->transaction(function () use ($db, $pid, $rel, $list, $runStart) {
            foreach ($list as $p) {
                $db->upsert('awin_programmes', [
                    'publisher_id' => $pid,
                    'advertiser_id' => (int)$p['id'],
                    'relationship' => $rel,
                    'name' => str($p['name'] ?? null, 255),
                    'primary_region' => str($p['primaryRegion']['countryCode'] ?? null, 2),
                    'currency_code' => str($p['currencyCode'] ?? null, 3),
                    'display_url' => str($p['displayUrl'] ?? null, 500),
                    'raw' => json($p),
                    'synced_at' => $runStart,
                ]);
            }
            $db->run('DELETE FROM awin_programmes WHERE publisher_id = ? AND relationship = ? AND synced_at < ?', [$pid, $rel, $runStart]);
        });
        logMsg("programmes/$rel: " . count($list));
        if ($rel === 'joined') {
            $joined = $list;
        }
    }
    // Drop rows of relationships that are no longer exported (e.g. removed from config).
    $db->run(
        'DELETE FROM awin_programmes WHERE publisher_id = ? AND relationship NOT IN (' . rtrim(str_repeat('?,', count($relationships)), ',') . ')',
        array_merge([$pid], $relationships)
    );

    // Details + commission groups per joined advertiser.
    $effective = gmdate('Y-m-d\TH:i:s\Z');
    foreach ($joined as $p) {
        $aid = (int)$p['id'];
        $d = tryCall(fn() => $api->get("/publishers/$pid/programmedetails", ['advertiserId' => $aid, 'relationship' => 'joined']), "programmedetails/$aid");
        if ($d !== null) {
            $db->upsert('awin_programme_details', ['publisher_id' => $pid, 'advertiser_id' => $aid, 'raw' => json($d), 'synced_at' => $runStart]);
        }

        $g = tryCall(fn() => $api->get("/publishers/$pid/commissiongroups", [
            'advertiserId' => $aid,
            'effectiveDate' => $effective,
            'extraConditionsDetails' => 'true',
        ]), "commissiongroups/$aid");
        if ($g !== null) {
            $groups = $g['commissionGroups'] ?? (array_is_list($g) ? $g : []);
            $db->transaction(function () use ($db, $pid, $aid, $groups, $runStart) {
                $db->run('DELETE FROM awin_commission_groups WHERE publisher_id = ? AND advertiser_id = ?', [$pid, $aid]);
                foreach ($groups as $i => $grp) {
                    $db->upsert('awin_commission_groups', [
                        'publisher_id' => $pid,
                        'advertiser_id' => $aid,
                        'group_key' => str((string)($grp['groupId'] ?? $grp['groupCode'] ?? "idx$i"), 100),
                        'group_id' => isset($grp['groupId']) ? (int)$grp['groupId'] : null,
                        'group_code' => str($grp['groupCode'] ?? null, 100),
                        'group_name' => str($grp['groupName'] ?? null, 255),
                        'type' => str($grp['type'] ?? null, 20),
                        'percentage' => num($grp['percentage'] ?? null),
                        'amount' => num($grp['amount'] ?? null),
                        'currency' => str($grp['currency'] ?? null, 3),
                        'raw' => json($grp),
                        'synced_at' => $runStart,
                    ]);
                }
            });
        }
    }
    logMsg('programme details + commission groups: ' . count($joined) . ' advertisers');

    // Offers (promotions + vouchers), all pages. Offers no longer returned are kept with is_active = 0.
    $count = 0;
    $complete = false;
    $pageSize = 200;
    for ($page = 1; ; $page++) {
        $res = tryCall(fn() => $api->post("/publisher/$pid/promotions", [
            'filters' => ['membership' => 'all', 'status' => 'active', 'type' => 'all'],
            'pagination' => ['page' => $page, 'pageSize' => $pageSize],
        ]), "promotions page $page");
        if ($res === null) {
            break;
        }
        $rows = $res['data'] ?? (array_is_list($res) ? $res : []);
        $db->transaction(function () use ($db, $pid, $rows, $runStart) {
            foreach ($rows as $o) {
                $db->upsert('awin_promotions', [
                    'publisher_id' => $pid,
                    'promotion_id' => (int)$o['promotionId'],
                    'advertiser_id' => isset($o['advertiser']['id']) ? (int)$o['advertiser']['id'] : null,
                    'type' => str($o['type'] ?? null, 20),
                    'title' => str($o['title'] ?? null, 500),
                    'voucher_code' => str($o['voucher']['code'] ?? null, 255),
                    'start_date' => dt($o['startDate'] ?? null),
                    'end_date' => dt($o['endDate'] ?? null),
                    'is_active' => 1,
                    'raw' => json($o),
                    'first_seen_at' => $runStart,
                    'synced_at' => $runStart,
                ], ['first_seen_at']);
            }
        });
        $count += count($rows);
        $total = $res['pagination']['total'] ?? null;
        if (count($rows) < $pageSize || ($total !== null && $count >= $total)) {
            $complete = true;
            break;
        }
    }
    if ($complete) {
        $db->run('UPDATE awin_promotions SET is_active = 0 WHERE publisher_id = ? AND synced_at < ?', [$pid, $runStart]);
    }
    logMsg("promotions: $count");

    $db->upsert('awin_sync_state', ['publisher_id' => $pid, 'part' => 'static', 'last_run_at' => $runStart]);
}

function syncTransactions(AwinClient $api, Db $db, array $cfg, int $pid, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    global $runStart;
    $total = 0;
    for ($start = $from; $start <= $to; $start = $start->modify('+' . TRANSACTION_MAX_DAYS . ' days')) {
        $end = min($start->modify('+' . (TRANSACTION_MAX_DAYS - 1) . ' days'), $to);
        $rows = tryCall(fn() => $api->get("/publishers/$pid/transactions/", [
            'startDate' => $start->format('Y-m-d\T00:00:00'),
            'endDate' => $end->format('Y-m-d\T23:59:59'),
            'timezone' => $cfg['timezone'],
            'dateType' => 'transaction',
            'showBasketProducts' => 'true',
        ]), 'transactions ' . $start->format('Y-m-d') . '..' . $end->format('Y-m-d'));
        if ($rows === null) {
            continue;
        }

        $db->transaction(function () use ($db, $pid, $rows, $runStart) {
            foreach ($rows as $t) {
                $db->upsert('awin_transactions', [
                    'transaction_id' => (int)$t['id'],
                    'publisher_id' => (int)($t['publisherId'] ?? $pid),
                    'advertiser_id' => isset($t['advertiserId']) ? (int)$t['advertiserId'] : null,
                    'commission_status' => str($t['commissionStatus'] ?? null, 20),
                    'transaction_date' => dt($t['transactionDate'] ?? null),
                    'validation_date' => dt($t['validationDate'] ?? null),
                    'commission_amount' => num($t['commissionAmount']['amount'] ?? null),
                    'commission_currency' => str($t['commissionAmount']['currency'] ?? null, 3),
                    'sale_amount' => num($t['saleAmount']['amount'] ?? null),
                    'sale_currency' => str($t['saleAmount']['currency'] ?? null, 3),
                    'click_ref' => str($t['clickRefs']['clickRef'] ?? null, 255),
                    'publisher_url' => str($t['publisherUrl'] ?? null, 1000),
                    'decline_reason' => str($t['declineReason'] ?? null, 500),
                    'raw' => json($t),
                    'synced_at' => $runStart,
                ]);
            }
        });
        $total += count($rows);
    }
    logMsg("transactions {$from->format('Y-m-d')}..{$to->format('Y-m-d')}: $total");
}

function syncReports(AwinClient $api, Db $db, array $cfg, int $pid, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    global $runStart;
    $regions = $cfg['report_regions'] ?: $db->column(
        "SELECT DISTINCT primary_region FROM awin_programmes WHERE publisher_id = ? AND relationship = 'joined' AND primary_region IS NOT NULL",
        [$pid]
    );
    $regions = array_values(array_intersect(array_map('strtoupper', $regions), REPORT_REGIONS));
    if (!$regions) {
        logMsg('No report regions known (run static first or set report_regions), skipping reports.');
        return;
    }

    $count = 0;
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        foreach ($regions as $region) {
            $base = ['startDate' => $day, 'endDate' => $day, 'region' => $region, 'timezone' => $cfg['timezone']];
            $reports = [
                'advertiser' => ["/publishers/$pid/reports/advertiser", $base + ['dateType' => 'transaction']],
                'creative' => ["/publishers/$pid/reports/creative", $base + ['dateType' => 'transaction']],
                'campaign' => ["/publishers/$pid/reports/campaign", $base + ['includeNumbersWithoutCampaign' => 'true']],
            ];
            foreach ($reports as $type => [$path, $query]) {
                $res = tryCall(fn() => $api->get($path, $query), "report $type $region $day");
                if ($res === null) {
                    continue;
                }
                $rows = array_is_list($res) ? $res : [$res];
                // Replace the whole (publisher, type, region, day) slice.
                $db->transaction(function () use ($db, $pid, $type, $region, $day, $rows, $runStart) {
                    $db->run('DELETE FROM awin_report_rows WHERE publisher_id = ? AND report_type = ? AND region = ? AND report_date = ?', [$pid, $type, $region, $day]);
                    foreach ($rows as $r) {
                        $db->insert('awin_report_rows', [
                            'publisher_id' => $pid,
                            'report_type' => $type,
                            'region' => $region,
                            'report_date' => $day,
                            'advertiser_id' => isset($r['advertiserId']) ? (int)$r['advertiserId'] : null,
                            'creative_id' => isset($r['creativeId']) ? (int)$r['creativeId'] : null,
                            'campaign' => str($r['campaign'] ?? null, 128),
                            'impressions' => isset($r['impressions']) ? (int)$r['impressions'] : null,
                            'clicks' => isset($r['clicks']) ? (int)$r['clicks'] : null,
                            'total_no' => isset($r['totalNo']) ? (int)$r['totalNo'] : null,
                            'total_value' => num($r['totalValue'] ?? null),
                            'total_comm' => num($r['totalComm'] ?? null),
                            'currency' => str($r['currency'] ?? null, 3),
                            'raw' => json($r),
                            'synced_at' => $runStart,
                        ]);
                    }
                });
                $count += count($rows);
            }
        }
    }
    logMsg("reports {$from->format('Y-m-d')}..{$to->format('Y-m-d')} [" . implode(',', $regions) . "]: $count rows");
}

/* ------------------------------------------------------------------ helpers */

/** Runs an API call; logs and returns null on non-fatal errors (403, 404, 400...). */
function tryCall(callable $fn, string $label): ?array
{
    try {
        return $fn();
    } catch (AwinAuthException $e) {
        throw $e;
    } catch (Throwable $e) {
        logMsg("skip $label: " . $e->getMessage());
        return null;
    }
}

function json($v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function str($v, int $max): ?string
{
    if ($v === null || $v === '' || is_array($v)) {
        return null;
    }
    return mb_substr((string)$v, 0, $max);
}

function num($v): ?string
{
    return is_numeric($v) ? (string)$v : null;
}

/** "2024-05-01T13:45:00.000Z" / "2024-05-01T13:45:00" -> "2024-05-01 13:45:00" (time as returned by AWIN). */
function dt($v): ?string
{
    if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}:\d{2}))?/', $v, $m)) {
        return null;
    }
    return $m[1] . ' ' . ($m[2] ?? '00:00:00');
}

function logMsg(string $msg): void
{
    global $cfg;
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line;
    @file_put_contents($cfg['log_dir'] . '/awin-' . date('Y-m-d') . '.log', $line, FILE_APPEND);
}

/* ------------------------------------------------------------------ database */

class Db
{
    private PDO $pdo;

    public function __construct(private array $cfg)
    {
        $this->connect();
    }

    private function connect(): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->cfg['host'], $this->cfg['port'] ?? 3306, $this->cfg['name']);
        $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    /** Executes schema.sql (CREATE TABLE IF NOT EXISTS ...). */
    public function migrate(string $file): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    /** Runs a statement; reconnects once if the server dropped the connection during long API waits. */
    public function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st;
        } catch (PDOException $e) {
            $gone = in_array($e->errorInfo[1] ?? null, [2006, 2013], true) || str_contains($e->getMessage(), 'gone away');
            if (!$gone || $this->pdo->inTransaction()) {
                throw $e;
            }
            $this->connect();
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st;
        }
    }

    public function value(string $sql, array $params = [])
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $this->run(
            "INSERT INTO $table (`" . implode('`,`', $cols) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')',
            array_values($row)
        );
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE every column except those in $keepOnUpdate. */
    public function upsert(string $table, array $row, array $keepOnUpdate = []): void
    {
        $cols = array_keys($row);
        $updates = array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($cols, $keepOnUpdate));
        $this->run(
            "INSERT INTO $table (`" . implode('`,`', $cols) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            array_values($row)
        );
    }

    public function transaction(callable $fn): void
    {
        $this->value('SELECT 1');   // reconnect here if needed, before the transaction starts
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

/* ------------------------------------------------------------------ API client */

class AwinAuthException extends RuntimeException {}

class AwinClient
{
    public int $calls = 0;
    private array $recent = [];

    public function __construct(private string $token, private int $perMinute = 18) {}

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body, array $query = []): array
    {
        return $this->request('POST', $path, $query, $body);
    }

    private function request(string $method, string $path, array $query, ?array $body = null): array
    {
        $url = API_BASE . $path . ($query ? '?' . http_build_query($query) : '');

        for ($attempt = 1; ; $attempt++) {
            $this->throttle();
            $ch = curl_init($url);
            $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_ENCODING => '',
                CURLOPT_CUSTOMREQUEST => $method,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $this->calls++;

            if ($raw === false) {
                if ($attempt < 4) { sleep(10 * $attempt); continue; }
                throw new RuntimeException("network error: $err");
            }
            if ($status === 401) {
                throw new AwinAuthException("401 Unauthorized on $path");
            }
            if ($status === 429 || $status >= 500) {
                if ($attempt < 5) {
                    $wait = $status === 429 ? 65 : 15 * $attempt;
                    logMsg("HTTP $status on $path, retrying in {$wait}s");
                    sleep($wait);
                    continue;
                }
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("HTTP $status: " . substr(trim((string)$raw), 0, 300));
            }
            if (trim($raw) === '') {
                return [];
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('invalid JSON: ' . substr($raw, 0, 200));
            }
            return $data;
        }
    }

    /** Sliding-window limiter: max N calls per 60 seconds (AWIN limit is 20/min per user). */
    private function throttle(): void
    {
        $now = microtime(true);
        $this->recent = array_values(array_filter($this->recent, fn($t) => $t > $now - 60));
        if (count($this->recent) >= $this->perMinute) {
            $wait = 60 - ($now - $this->recent[0]) + 0.5;
            usleep((int)($wait * 1e6));
        }
        $this->recent[] = microtime(true);
    }
}
