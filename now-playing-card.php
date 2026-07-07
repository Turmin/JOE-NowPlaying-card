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

    // Fallback: als API faalt, gebruik oude cache als die bestaat
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
$endsAt = null;
$elapsed = 0;
$progress = 0;
$durationLabel = $duration > 0 ? formatDuration($duration) : null;

if ($playedAt) {
    try {
        $date = new DateTime($playedAt);
        $time = $date->format('H:i');
        $endsAt = $duration > 0
            ? (clone $date)->modify('+' . $duration . ' seconds')->format('H:i')
            : null;
        $elapsed = max(0, time() - $date->getTimestamp());
        $progress = $duration > 0
            ? min(100, max(0, ($elapsed / $duration) * 100))
            : 0;
    } catch (Exception $exception) {
        $time = null;
        $endsAt = null;
        $elapsed = 0;
        $progress = 0;
    }
}

$hasTrack = $track && $title && $artist;
$hasProgress = $hasTrack && $playedAt && $duration > 0;
?>
<!doctype html>
<html lang="nl">
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
            margin-top: 13px;
            width: 100%;
        }

        .progress-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
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
            color: #e30613;
        }

        .progress-track {
            position: relative;
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0)),
                #ececec;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.18);
            overflow: hidden;
        }

        .progress-fill,
        .progress-head {
            animation-duration: var(--track-duration);
            animation-delay: var(--track-delay);
            animation-fill-mode: forwards;
            animation-timing-function: linear;
        }

        .progress-fill {
            position: absolute;
            inset: 0;
            transform: scaleX(0);
            transform-origin: left center;
            border-radius: inherit;
            background:
                linear-gradient(90deg, #e30613 0%, #ff3b30 58%, #ffca28 100%);
            box-shadow: 0 0 14px rgba(227, 6, 19, 0.36);
            animation-name: progress-grow;
            overflow: hidden;
        }

        .progress-fill::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(
                105deg,
                transparent 0%,
                transparent 35%,
                rgba(255, 255, 255, 0.58) 50%,
                transparent 65%,
                transparent 100%
            );
            animation: progress-shine 2.8s ease-in-out infinite;
        }

        .progress-head {
            position: absolute;
            top: 50%;
            left: 0;
            width: 16px;
            height: 16px;
            border: 3px solid #ffffff;
            border-radius: 50%;
            background: #ffca28;
            box-shadow: 0 2px 8px rgba(227, 6, 19, 0.38);
            transform: translate(-50%, -50%);
            animation-name: progress-head;
        }

        @keyframes progress-grow {
            to {
                transform: scaleX(1);
            }
        }

        @keyframes progress-head {
            to {
                left: 100%;
            }
        }

        @keyframes progress-shine {
            0%,
            35% {
                transform: translateX(-100%);
            }

            75%,
            100% {
                transform: translateX(100%);
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
            <img src="<?= e($cover) ?>" alt="Cover van <?= e($title ?? 'huidig nummer') ?>">
        <?php else: ?>
            <div class="cover-placeholder">♪</div>
        <?php endif; ?>
    </div>

    <div class="track-info">
        <div class="label">Nu op JOE</div>

        <?php if ($hasTrack): ?>
            <h1 class="title" title="<?= e($title) ?>">
                <?= e($title) ?>
            </h1>

            <p class="artist" title="<?= e($artist) ?>">
                <?= e($artist) ?>
            </p>

            <div class="meta">
                <?php if ($time): ?>
                    <span>Gestart om <?= e($time) ?></span>
                <?php endif; ?>

                <?php if ($releaseYear): ?>
                    <span><?= e($releaseYear) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($hasProgress): ?>
                <div
                    class="track-progress"
                    role="img"
                    aria-label="Voortgang <?= e(round($progress)) ?> procent van <?= e($durationLabel) ?>, eindigt rond <?= e($endsAt) ?>"
                    style="--track-duration: <?= e($duration) ?>s; --track-delay: -<?= e(min($elapsed, $duration)) ?>s;"
                >
                    <div class="progress-meta">
                        <span><?= e(round($progress)) ?>%</span>
                        <span><?= e($durationLabel) ?> tot <?= e($endsAt) ?></span>
                    </div>
                    <div class="progress-track" aria-hidden="true">
                        <div class="progress-fill"></div>
                        <div class="progress-head"></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-title">Geen nummer beschikbaar</p>
            <p class="empty-text">De API gaf tijdelijk geen bruikbare trackdata terug.</p>
        <?php endif; ?>
    </div>
</article>

</body>
</html>
