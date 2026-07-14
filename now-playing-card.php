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

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: transparent;
            font-family: Arial, Helvetica, sans-serif;
        }

        .now-playing-card {
            width: 100%;
            height: 100%;
            max-width: none;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px;
            background: #ffffff;
            color: #151515;
            overflow: hidden;
        }

        .cover {
            flex: 0 0 112px;
            width: 112px;
            height: 112px;
            border-radius: 14px;
            overflow: hidden;
            background: #eeeeee;
        }

        .cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .cover-placeholder {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            font-size: 32px;
            color: #777777;
        }

        .track-info {
            min-width: 0;
            flex: 1;
        }

        .label {
            margin-bottom: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #e30613;
        }

        .title {
            margin: 0 0 5px;
            font-size: 22px;
            line-height: 1.15;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .artist {
            margin: 0;
            font-size: 16px;
            line-height: 1.25;
            font-weight: 600;
            color: #333333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            font-size: 12px;
            color: #777777;
        }

        .meta span {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #f4f4f4;
        }

        .track-progress {
            margin-top: 12px;
            display: grid;
            grid-template-columns: auto minmax(90px, 280px) auto;
            align-items: center;
            gap: 8px;
            width: min(100%, 360px);
        }

        .progress-time {
            font-size: 11px;
            line-height: 1.2;
            font-weight: 700;
            color: #777777;
            white-space: nowrap;
        }

        .progress-elapsed {
            color: #5f5f5f;
        }

        .progress-track {
            position: relative;
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: #e5e5e5;
            overflow: hidden;
        }

        .progress-fill {
            animation-duration: var(--track-duration);
            animation-delay: var(--track-delay);
            animation-fill-mode: forwards;
            animation-timing-function: linear;
            position: absolute;
            inset: 0;
            transform: scaleX(0);
            transform-origin: left center;
            border-radius: inherit;
            background: #b64b52;
            animation-name: progress-grow;
        }

        @keyframes progress-grow {
            to {
                transform: scaleX(1);
            }
        }

        .empty-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .empty-text {
            margin: 6px 0 0;
            font-size: 13px;
            color: #777777;
        }

        @media (max-width: 360px) {
            .now-playing-card {
                gap: 12px;
                padding: 12px;
                border-radius: 14px;
            }

            .cover {
                flex-basis: 86px;
                width: 86px;
                height: 86px;
                border-radius: 12px;
            }

            .title {
                font-size: 18px;
            }

            .artist {
                font-size: 14px;
            }

            .meta {
                margin-top: 8px;
                font-size: 11px;
                gap: 6px;
            }

            .track-progress {
                margin-top: 10px;
            }

            .track-progress {
                grid-template-columns: auto minmax(70px, 1fr) auto;
                gap: 6px;
            }

            .progress-time {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>

<article class="now-playing-card">
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

<script>
    (function () {
        var progress = document.querySelector('.track-progress');

        if (!progress) {
            return;
        }

        var elapsedLabel = progress.querySelector('.progress-elapsed');
        var duration = parseInt(progress.getAttribute('data-duration') || '0', 10);
        var initialElapsed = parseInt(progress.getAttribute('data-elapsed') || '0', 10);

        if (!elapsedLabel || !duration || !isFinite(duration)) {
            return;
        }

        var startedAt = Date.now() - (initialElapsed * 1000);

        function formatDuration(seconds) {
            var safeSeconds = Math.max(0, Math.min(duration, seconds));
            var minutes = Math.floor(safeSeconds / 60);
            var remainingSeconds = safeSeconds % 60;
            var paddedSeconds = remainingSeconds < 10 ? '0' + remainingSeconds : String(remainingSeconds);

            return String(minutes) + ':' + paddedSeconds;
        }

        function updateElapsed() {
            var elapsed = Math.min(duration, Math.floor((Date.now() - startedAt) / 1000));
            var label = formatDuration(elapsed);

            elapsedLabel.textContent = label;
            progress.setAttribute('aria-valuenow', String(elapsed));
            progress.setAttribute('aria-valuetext', label + ' of ' + formatDuration(duration));
        }

        updateElapsed();
        window.setInterval(updateElapsed, 1000);
    }());
</script>

</body>
</html>
