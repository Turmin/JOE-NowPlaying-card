<?php
$apiBaseUrl = 'https://joe-api.turmin.com/playlist';
$defaultStation = 'joe_nl';
$cacheTtl = 10; // seconds

function startsWith($string, $prefix) {
    return strpos($string, $prefix) === 0;
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getRequestedStation(string $defaultStation): string {
    $station = $_GET['station'] ?? $defaultStation;

    if (!is_string($station)) {
        return $defaultStation;
    }

    $station = strtolower(trim($station));

    if ($station === '' || !preg_match('/^[a-z0-9_-]+$/', $station)) {
        return $defaultStation;
    }

    return $station;
}

function isDarkModeRequested(): bool {
    if (!array_key_exists('darkmode', $_GET) || !is_string($_GET['darkmode'])) {
        return false;
    }

    $darkMode = strtolower(trim($_GET['darkmode']));

    return $darkMode === '' || in_array($darkMode, ['true', 'yes', 'on'], true);
}

function getStationLabel(string $station): string {
    $stationLabels = [
        'joe_nl' => 'JOE',
        'qmusic_nl' => 'Qmusic',
    ];

    if (isset($stationLabels[$station])) {
        return $stationLabels[$station];
    }

    $parts = preg_split('/[_-]+/', $station) ?: [];
    $parts = array_map(static function (string $part): string {
        if (strlen($part) === 2) {
            return strtoupper($part);
        }

        return strtoupper(substr($part, 0, 1)) . substr($part, 1);
    }, $parts);

    return implode(' ', array_filter($parts, static function (string $part): bool {
        return $part !== '';
    }));
}

function getStationCacheFile(string $station, string $defaultStation): string {
    if ($station === $defaultStation) {
        return __DIR__ . '/now-playing-cache.json';
    }

    return __DIR__ . '/now-playing-cache-' . $station . '.json';
}

function fetchJsonWithCurl(string $url, int $timeout = 3): ?string {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: JOE Now Playing Card'
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('JOE API fetch failed: HTTP ' . $httpCode . ' - ' . $error);
        return null;
    }

    return $response;
}

function getCachedJson(string $apiUrl, string $cacheFile, int $cacheTtl): ?string {
    $cacheExists = file_exists($cacheFile);
    $cacheIsFresh = $cacheExists && (time() - filemtime($cacheFile) < $cacheTtl);

    if ($cacheIsFresh) {
        return file_get_contents($cacheFile);
    }

    $json = fetchJsonWithCurl($apiUrl);

    if ($json !== null) {
        file_put_contents($cacheFile, $json, LOCK_EX);
        return $json;
    }

    // Fallback: if the API fails, use the stale cache when available.
    if ($cacheExists) {
        return file_get_contents($cacheFile);
    }

    return null;
}

function getImageUrl($path) {
    if (!$path) {
        return null;
    }

    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }

    if (strpos($path, '/') !== 0) {
        $path = '/' . $path;
    }

    return 'https://cdn-radio.dpgmedia.net/cover/w300' . $path;
}

function formatDuration(int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;

    return sprintf('%d:%02d', $minutes, $remainingSeconds);
}

$requestedStation = getRequestedStation($defaultStation);
$darkMode = isDarkModeRequested();
$stationLabel = getStationLabel($requestedStation);
$apiUrl = $apiBaseUrl . '?' . http_build_query(['station' => $requestedStation]);
$cacheFile = getStationCacheFile($requestedStation, $defaultStation);

$json = getCachedJson($apiUrl, $cacheFile, $cacheTtl);
$data = $json ? json_decode($json, true) : null;

$track = $data['track'] ?? $data['tracks'][0] ?? null;

$title = $track['title'] ?? null;
$artist = $track['artist'] ?? null;
$playedAt = $track['played_at'] ?? null;
$duration = (int) ($track['raw']['duration'] ?? 0);

$releaseYear = $track['raw']['release_year'] ?? null;
$cover = getImageUrl(
    $track['raw']['images']['default']
    ?? $track['raw']['thumbnail']
    ?? null
);

$time = null;
$elapsed = 0;
$elapsedLabel = null;
$durationLabel = $duration > 0 ? formatDuration($duration) : null;

if ($playedAt) {
    try {
        $date = new DateTime($playedAt);
        $time = $date->format('H:i');
        $elapsed = max(0, time() - $date->getTimestamp());
        $elapsedLabel = $duration > 0 ? formatDuration(min($elapsed, $duration)) : null;
    } catch (Exception $exception) {
        $time = null;
        $elapsed = 0;
        $elapsedLabel = null;
    }
}

$hasTrack = $track && $title && $artist;
$hasProgress = $hasTrack && $playedAt && $duration > 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="10">
    <title>Now Playing on <?= e($stationLabel) ?></title>

    <link rel="stylesheet" href="static/css/now-playing-card.css">
</head>
<body>

<article class="now-playing-card<?= $darkMode ? ' dark-mode' : '' ?>">
    <div class="cover">
        <?php if ($cover): ?>
            <img src="<?= e($cover) ?>" alt="Cover for <?= e($title ?? 'the current track') ?>">
        <?php else: ?>
            <div class="cover-placeholder">♪</div>
        <?php endif; ?>
    </div>

    <div class="track-info">
        <div class="label">Now playing on <?= e($stationLabel) ?></div>

        <?php if ($hasTrack): ?>
            <h1 class="title" title="<?= e($title) ?>">
                <?= e($title) ?>
            </h1>

            <p class="artist" title="<?= e($artist) ?>">
                <?= e($artist) ?>
            </p>

            <div class="meta">
                <?php if ($time): ?>
                    <span>Started at <?= e($time) ?></span>
                <?php endif; ?>

                <?php if ($releaseYear): ?>
                    <span><?= e($releaseYear) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($hasProgress): ?>
                <div
                    class="track-progress"
                    role="progressbar"
                    aria-label="Track progress"
                    aria-valuemin="0"
                    aria-valuemax="<?= e($duration) ?>"
                    aria-valuenow="<?= e(min($elapsed, $duration)) ?>"
                    aria-valuetext="<?= e($elapsedLabel) ?> of <?= e($durationLabel) ?>"
                    data-duration="<?= e($duration) ?>"
                    data-elapsed="<?= e(min($elapsed, $duration)) ?>"
                    style="--track-duration: <?= e($duration) ?>s; --track-delay: -<?= e(min($elapsed, $duration)) ?>s;"
                >
                    <span class="progress-time progress-elapsed"><?= e($elapsedLabel) ?></span>
                    <div class="progress-track" aria-hidden="true">
                        <div class="progress-fill"></div>
                    </div>
                    <span class="progress-time"><?= e($durationLabel) ?></span>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-title">No track available</p>
            <p class="empty-text">The API did not return usable track data.</p>
        <?php endif; ?>
    </div>
</article>

<script src="static/js/now-playing-card.js"></script>

</body>
</html>
