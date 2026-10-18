<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;

final class DarkModeGuardMiddleware implements MiddlewareInterface
{
    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        $htmlBody = $envelope->getHtmlBody();
        
        if ($htmlBody !== '') {
            $protectedHtml = $this->protectFromDarkMode($htmlBody);
            $envelope->setHtmlBody($protectedHtml);
        }
        
        return $handler->handle($envelope);
    }

    private function protectFromDarkMode(string $html): string
    {
        $metaTags = <<<HTML
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light">
HTML;

        if (preg_match('/<head[^>]*>/i', $html)) {
            $withMeta = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $metaTags, $html, 1);
            $html = $withMeta ?? $html;
        } else {
            $html = $metaTags . "\n" . $html;
        }

        $styleTag = <<<CSS
<style>
    :root {
        color-scheme: light only;
        supported-color-schemes: light;
    }
    
    @media (prefers-color-scheme: dark) {
        body, table, td, div, p, span, a {
            color: #000000 !important;
            background-color: #ffffff !important;
        }
        
        [data-ogsc] {
            color: var(--original-color) !important;
            background-color: var(--original-bg) !important;
        }
    }
</style>
CSS;

        if (preg_match('/<head[^>]*>.*?<\/head>/is', $html)) {
            $withStyle = preg_replace('/(<\/head>)/i', $styleTag . "\n" . '$1', $html, 1);
            $html = $withStyle ?? $html;
        } else {
            $html = $styleTag . "\n" . $html;
        }

        return $html;
    }
}
