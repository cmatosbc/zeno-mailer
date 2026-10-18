<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;

final class CssInlinerMiddleware implements MiddlewareInterface
{
    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        $htmlBody = $envelope->getHtmlBody();
        
        if ($htmlBody !== '') {
            $inlinedHtml = $this->inlineCss($htmlBody);
            $envelope->setHtmlBody($inlinedHtml);
        }
        
        return $handler->handle($envelope);
    }

    private function inlineCss(string $html): string
    {
        if (!preg_match('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            return $html;
        }

        $css = $matches[1];
        $stripped = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = $stripped ?? $html;

        $rules = $this->parseCss($css);

        foreach ($rules as $selector => $styles) {
            $html = $this->applyStylesToSelector($html, $selector, $styles);
        }

        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function parseCss(string $css): array
    {
        $rules = [];
        
        preg_match_all('/([^{]+)\{([^}]+)\}/i', $css, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $selectors = array_map('trim', explode(',', $match[1]));
            $styles = trim($match[2]);
            
            foreach ($selectors as $selector) {
                $selector = trim($selector);
                if (!isset($rules[$selector])) {
                    $rules[$selector] = [];
                }
                $rules[$selector][] = $styles;
            }
        }
        
        foreach ($rules as $selector => $styleArray) {
            $rules[$selector] = implode('; ', $styleArray);
        }
        
        return $rules;
    }

    private function applyStylesToSelector(string $html, string $selector, string $styles): string
    {
        $selector = preg_quote($selector, '/');
        $selector = str_replace('\\.', '\.', $selector);
        $selector = str_replace('\\#', '\#', $selector);
        
        $simpleSelector = str_replace(['\\', '.', '#'], ['', '', ''], $selector);
        
        if (strpos($selector, '.') !== false) {
            $className = preg_replace('/.*\\\.([a-zA-Z0-9_-]+).*/', '$1', $selector) ?? '';
            $pattern = '/<([a-z]+)([^>]*class=["\'][^"\']*' . preg_quote($className, '/') . '[^"\']*["\'][^>]*)>/i';
        } elseif (strpos($selector, '#') !== false) {
            $idName = preg_replace('/.*\\#([a-zA-Z0-9_-]+).*/', '$1', $selector) ?? '';
            $pattern = '/<([a-z]+)([^>]*id=["\']' . preg_quote($idName, '/') . '["\'][^>]*)>/i';
        } else {
            $pattern = '/<(' . $simpleSelector . ')([^>]*)>/i';
        }

        $result = preg_replace_callback($pattern, function ($matches) use ($styles) {
            $tag = $matches[1];
            $attributes = $matches[2];

            if (preg_match('/style=["\']([^"\']*)["\']/', $attributes, $styleMatch)) {
                $existingStyle = rtrim($styleMatch[1], ';');
                $newStyle = $existingStyle . '; ' . $styles;
                $attributes = preg_replace('/style=["\'][^"\']*["\']/', 'style="' . $newStyle . '"', $attributes) ?? $attributes;
            } else {
                $attributes .= ' style="' . $styles . '"';
            }

            return '<' . $tag . $attributes . '>';
        }, $html);

        return $result ?? $html;
    }
}
