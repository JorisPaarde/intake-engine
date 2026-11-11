{{-- Child-frame height reporter for host auto-resize (BL-158).
     Measures the main content box so height can shrink after step changes. --}}
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
        var html = document.documentElement;
        var body = document.body;
        if (! html || ! body) {
            return 0;
        }

        // Force collapse: a tall iframe otherwise keeps html/body scrollHeight large.
        html.style.setProperty('height', 'auto', 'important');
        body.style.setProperty('height', 'auto', 'important');
        html.style.setProperty('min-height', '0', 'important');
        body.style.setProperty('min-height', '0', 'important');

        var main = document.querySelector('main');
        var height;
        if (main) {
            height = Math.ceil(main.offsetTop + main.offsetHeight);
        } else {
            height = Math.ceil(Math.max(body.scrollHeight || 0, html.scrollHeight || 0));
        }

        // Sub-pixel / box-shadow buffer only.
        height += 4;

        return height > 0 ? height : 0;
    }

    function post(force) {
        var height = measure();
        if (height < 1) {
            return;
        }
        if (! force && height === lastHeight) {
            return;
        }
        lastHeight = height;
        window.parent.postMessage({ source: SOURCE, type: TYPE, height: height }, '*');
    }

    function schedule(force) {
        if (timer !== null) {
            clearTimeout(timer);
        }
        timer = setTimeout(function () {
            post(!!force);
        }, 40);
    }

    if (typeof ResizeObserver !== 'undefined') {
        var observer = new ResizeObserver(function () {
            schedule(false);
        });
        observer.observe(document.documentElement);
        observer.observe(document.body);
        var main = document.querySelector('main');
        if (main) {
            observer.observe(main);
        }
        var card = document.querySelector('.card');
        if (card) {
            observer.observe(card);
        }
    }

    window.addEventListener('load', function () {
        schedule(true);
    });
    window.addEventListener('orientationchange', function () {
        schedule(true);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            schedule(true);
        });
    } else {
        schedule(true);
    }
    // Extra passes after layout/fonts settle (important after in-iframe navigation).
    setTimeout(function () {
        schedule(true);
    }, 100);
    setTimeout(function () {
        schedule(true);
    }, 350);
})();
</script>
