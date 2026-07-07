<?php
$apiUrl = 'https://joe-api.turmin.com/now-playing';
$cacheFile = __DIR__ . '/now-playing-cache.json';
$cacheTtl = 10; // seconds

function startsWith($string, $prefix) {
    return strpos($string, $prefix) === 0;
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

$json = getCachedJson($apiUrl, $cacheFile, $cacheTtl);
$data = $json ? json_decode($json, true) : null;

$track = $data['track'] ?? null;

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
    <title>Now Playing</title>

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
            width: 100%;
        }

        .progress-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 5px;
            font-size: 11px;
            line-height: 1.2;
            font-weight: 700;
            color: #777777;
        }

        .progress-meta span {
            min-width: 0;
            white-space: nowrap;
        }

        .progress-meta span:first-child {
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

            .progress-meta {
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
        <div class="label">Now on JOE</div>

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
                    style="--track-duration: <?= e($duration) ?>s; --track-delay: -<?= e(min($elapsed, $duration)) ?>s;"
                >
                    <div class="progress-meta">
                        <span><?= e($elapsedLabel) ?></span>
                        <span><?= e($durationLabel) ?></span>
                    </div>
                    <div class="progress-track" aria-hidden="true">
                        <div class="progress-fill"></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-title">No track available</p>
            <p class="empty-text">The API did not return usable track data.</p>
        <?php endif; ?>
    </div>
</article>

</body>
</html>
