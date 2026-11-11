<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

/**
 * Builds the copy-paste iframe snippet + host-side auto-resize listener (BL-160).
 */
final class EmbedIframeSnippet
{
    public const MESSAGE_SOURCE = 'intake-engine-embed';

    public const MESSAGE_TYPE_RESIZE = 'resize';

    /**
     * @param  array{id?: string, title?: string, max_width?: string, initial_height?: int}  $options
     */
    public function forSrc(string $src, array $options = []): string
    {
        $iframeId = (string) ($options['id'] ?? 'intake-embed-frame');
        $title = (string) ($options['title'] ?? 'Opname starten');
        $maxWidth = (string) ($options['max_width'] ?? '40rem');
        $initialHeight = (int) ($options['initial_height'] ?? 480);

        $escapedSrc = e($src);
        $escapedId = e($iframeId);
        $escapedTitle = e($title);
        $escapedMaxWidth = e($maxWidth);

        // No CSS min-height: host height follows postMessage both up and down.
        $iframe = '<iframe id="'.$escapedId.'"'
            .' src="'.$escapedSrc.'"'
            .' title="'.$escapedTitle.'"'
            .' width="100%"'
            .' height="'.$initialHeight.'"'
            .' style="border:0;width:100%;max-width:'.$escapedMaxWidth.';display:block;"'
            .' loading="lazy"'
            .' referrerpolicy="strict-origin-when-cross-origin"'
            .'></iframe>';

        $script = '<script>(function(){'
            .'var iframe=document.getElementById('.json_encode($iframeId, JSON_UNESCAPED_SLASHES).');'
            .'if(!iframe){return;}'
            .'var expectedOrigin;try{expectedOrigin=new URL(iframe.getAttribute("src")||"",window.location.href).origin;}catch(e){return;}'
            .'function applyHeight(h){'
            .'if(!Number.isFinite(h)||h<120){return;}'
            .'iframe.style.height=Math.ceil(h)+"px";'
            .'iframe.removeAttribute("height");'
            .'}'
            .'window.addEventListener("message",function(event){'
            .'if(event.origin!==expectedOrigin){return;}'
            .'var data=event.data;'
            .'if(!data||data.source!=='.json_encode(self::MESSAGE_SOURCE).'||data.type!=='.json_encode(self::MESSAGE_TYPE_RESIZE).'){return;}'
            .'applyHeight(Number(data.height));'
            .'});'
            .'})();</script>';

        return $iframe."\n".$script;
    }
}
