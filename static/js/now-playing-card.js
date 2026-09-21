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
