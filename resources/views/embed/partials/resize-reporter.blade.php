{{-- Child-frame height reporter for host auto-resize (BL-158). --}}
<script>
(function () {
    if (window.parent === window) {
        return;
    }

    var SOURCE = @json(\App\Domains\Intake\Services\EmbedIframeSnippet::MESSAGE_SOURCE);
    var TYPE = @json(\App\Domains\Intake\Services\EmbedIframeSnippet::MESSAGE_TYPE_RESIZE);
    var lastHeight = 0;
    var timer = null;

    function measure() {
        var root = document.documentElement;
        var body = document.body;
        var height = Math.max(
            root ? root.scrollHeight : 0,
            body ? body.scrollHeight : 0,
            root ? Math.ceil(root.getBoundingClientRect().height) : 0,
            body ? Math.ceil(body.getBoundingClientRect().height) : 0
        );

        return Math.ceil(height);
    }

    function post() {
        var height = measure();
        if (height === lastHeight || height < 1) {
            return;
        }
        lastHeight = height;
        window.parent.postMessage({ source: SOURCE, type: TYPE, height: height }, '*');
    }

    function schedule() {
        if (timer !== null) {
            clearTimeout(timer);
        }
        timer = setTimeout(post, 60);
    }

    if (typeof ResizeObserver !== 'undefined') {
        var observer = new ResizeObserver(schedule);
        if (document.documentElement) {
            observer.observe(document.documentElement);
        }
        if (document.body) {
            observer.observe(document.body);
        }
    }

    window.addEventListener('load', schedule);
    window.addEventListener('orientationchange', schedule);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    }
    schedule();
})();
</script>
