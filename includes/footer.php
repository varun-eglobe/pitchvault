<?php
// includes/footer.php - Shared Layout Footer
?>
<footer class="mt-auto border-t border-slate-200 bg-white py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center text-xs text-slate-500 space-y-2 sm:space-y-0">
        <div>
            &copy; <?= date('Y') ?> PitchVault. Secure Private Video Sharing System.
        </div>
        <div class="flex items-center space-x-4">
            <span class="inline-flex items-center space-x-1">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="font-medium text-slate-600">Protected Endpoint Streaming Active</span>
            </span>
        </div>
    </div>
</footer>

<!-- Auto-pause video when user switches tabs or window loses focus -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    function pausePlayingVideos() {
        const videos = document.querySelectorAll('video');
        videos.forEach(v => {
            if (!v.paused && !v.ended) {
                v.pause();
            }
        });
    }

    // Tab visibility change (switching tabs, minimizing browser, screen lock)
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            pausePlayingVideos();
        }
    });

    // Window blur (switching windows/apps, Alt+Tab, Cmd+Tab, clicking out of window)
    window.addEventListener('blur', () => {
        pausePlayingVideos();
    });
});
</script>
</body>
</html>
