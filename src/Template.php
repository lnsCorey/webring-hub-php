<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

/**
 * Server-side HTML template renderer for `index.html`, `remove.html` and `admin.html`.
 * Mirrors `renderInstanceHtml` in `webring-directory/src/server.js`.
 */
final class Template
{
    public static function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function renderBrandTitleHtml(string $title): string
    {
        $trimmed = trim($title) ?: Database::DEFAULT_INSTANCE_META['title'];
        $parts = preg_split('/\s+/u', $trimmed) ?: [$trimmed];
        if (count($parts) === 1) {
            return '<span class="brand-accent">' . self::escapeHtml($parts[0]) . '</span>';
        }
        $accent = (string) array_pop($parts);
        return '<span class="brand-webring">' . self::escapeHtml(implode(' ', $parts)) . '</span> <span class="brand-accent">' . self::escapeHtml($accent) . '</span>';
    }

    /**
     * @param array{title: string, description: string, bannerUrl: string, did: string, maintainerBadge: bool, maintainer: array{name: string, url: string, did: string, verified: bool}|null} $info
     */
    public static function renderMaintainerHtml(array $info): string
    {
        if (empty($info['maintainerBadge']) || !is_array($info['maintainer'])) {
            return '';
        }
        $m = $info['maintainer'];
        $verified = !empty($m['verified']);
        $label = $verified ? 'Verified Maintainer' : 'Maintained by';
        $nameHtml = $m['url'] !== ''
            ? '<a class="maintainer-link" href="' . self::escapeHtml($m['url']) . '" rel="noopener noreferrer" target="_blank">' . self::escapeHtml($m['name']) . '</a>'
            : '<strong class="maintainer-name">' . self::escapeHtml($m['name']) . '</strong>';
        $didHtml = $m['did'] !== ''
            ? ' <code class="maintainer-did">' . self::escapeHtml($m['did']) . '</code>'
            : '';
        $ringDidHtml = $info['did'] !== ''
            ? '<a class="hub-did-pill" href="/.well-known/did.json" target="_blank" rel="noopener noreferrer" title="Hub W3C DID Document"><span class="maintainer-label">Ring DID</span> <code>' . self::escapeHtml($info['did']) . '</code></a>'
            : '';

        return '<div class="maintainer-bar"><span class="maintainer-badge'
            . ($verified ? ' is-verified' : '')
            . '"><span class="maintainer-dot" aria-hidden="true"></span><span class="maintainer-label">'
            . $label
            . '</span> '
            . $nameHtml
            . $didHtml
            . '</span>'
            . $ringDidHtml
            . '</div>';
    }

    /**
     * Renders horizontal navigation tabs for user-facing extensions on the home page, or '' when
     * none are enabled. All text is HTML-escaped; no inline styles or handlers.
     *
     * @param list<array{id: string, title: string, tagline: string, href: string, cta: string, art: string, accent: string}> $cards
     */
    public static function renderExtensionCardsHtml(array $cards): string
    {
        if (count($cards) === 0) {
            return '';
        }
        $allowedAccents = ['magenta', 'cyan', 'yellow', 'green', 'violet', 'orange'];
        $tabsHtml = '';
        foreach ($cards as $card) {
            $accent = in_array($card['accent'] ?? '', $allowedAccents, true) ? $card['accent'] : 'cyan';
            $title = self::escapeHtml($card['title'] ?? '');
            $tagline = self::escapeHtml($card['tagline'] ?? '');
            $href = self::escapeHtml($card['href'] ?? '#');
            $extId = self::escapeHtml($card['id'] ?? '');
            $titleAttr = $tagline !== '' ? ' title="' . $tagline . '"' : '';
            $tabsHtml .= '<a class="hub-home-tab hub-ext-tab ext-accent-' . $accent . '" href="' . $href . '" data-ext-id="' . $extId . '"' . $titleAttr . '>'
                . '<span class="hub-ext-dot" aria-hidden="true"></span>'
                . $title
                . '</a>';
        }

        return '<span class="hub-home-tabs-divider" aria-hidden="true"></span>'
            . '<div class="hub-ext-tabs" aria-label="Hub extensions">'
            . $tabsHtml
            . '</div>';
    }

    /**
     * Renders `index.html`, `remove.html` or `admin.html` with the instance's custom title,
     * description, banner, maintainer badge, and optional home-page extension carousel.
     *
     * @param array<string, mixed> $meta
     * @param list<array{id: string, title: string, tagline: string, href: string, cta: string, art: string, accent: string}> $extensionCards
     */
    public static function renderInstanceHtml(string $name, string $rawHtml, array $meta, ?PDO $pdo = null, array $extensionCards = []): string
    {
        $safe = Database::normalizeInstanceMeta($meta, Database::DEFAULT_INSTANCE_META);
        $info = Database::resolvePublicInfo($pdo, $safe);
        $wordmark = self::renderBrandTitleHtml($safe['title']);

        $html = preg_replace_callback(
            '/<!--INSTANCE_WORDMARK-->[\s\S]*?<!--\/INSTANCE_WORDMARK-->/',
            static fn(): string => $wordmark,
            $rawHtml
        ) ?? $rawHtml;

        if ($name === 'index.html') {
            $pageTitle = $safe['title'] === Database::DEFAULT_INSTANCE_META['title']
                ? self::escapeHtml($safe['title']) . ' — Decentralized Profile &amp; Ring Index'
                : self::escapeHtml($safe['title']) . ' — Webring Hub';

            $html = preg_replace_callback(
                '/<title>[\s\S]*?<\/title>/',
                static fn(): string => '<title>' . $pageTitle . '</title>',
                $html
            ) ?? $html;

            $html = preg_replace_callback(
                '/<meta\s+name="description"\s+content="[^"]*"\s*\/>/',
                static fn(): string => '<meta name="description" content="' . self::escapeHtml($safe['description']) . '" />',
                $html
            ) ?? $html;

            $html = preg_replace_callback(
                '/<!--INSTANCE_DESCRIPTION-->[\s\S]*?<!--\/INSTANCE_DESCRIPTION-->/',
                static fn(): string => self::escapeHtml($safe['description']),
                $html
            ) ?? $html;

            $maintainerHtml = self::renderMaintainerHtml($info);
            $html = preg_replace_callback(
                '/<!--INSTANCE_MAINTAINER-->[\s\S]*?<!--\/INSTANCE_MAINTAINER-->/',
                static fn(): string => $maintainerHtml,
                $html
            ) ?? $html;

            if ($safe['bannerUrl'] !== '') {
                $html = str_replace(
                    '<header id="site-header" class="site-header">',
                    '<header id="site-header" class="site-header has-banner">',
                    $html
                );
                $bannerTag = '<div id="site-banner" class="hero-banner"><img id="site-banner-img" class="hero-banner-img" src="'
                    . self::escapeHtml($safe['bannerUrl'])
                    . '" alt="" /></div>';
                $html = preg_replace_callback(
                    '/<!--INSTANCE_BANNER-->[\s\S]*?<!--\/INSTANCE_BANNER-->/',
                    static fn(): string => $bannerTag,
                    $html
                ) ?? $html;
            } else {
                $html = preg_replace(
                    '/<!--INSTANCE_BANNER-->[\s\S]*?<!--\/INSTANCE_BANNER-->/',
                    '',
                    $html
                ) ?? $html;
            }

            $carouselHtml = self::renderExtensionCardsHtml($extensionCards);
            $html = preg_replace_callback(
                '/<!--EXTENSION_CARDS-->[\s\S]*?<!--\/EXTENSION_CARDS-->/',
                static fn(): string => $carouselHtml,
                $html
            ) ?? $html;

            $carouselScript = '';
            $html = preg_replace_callback(
                '/<!--EXTENSION_CARDS_SCRIPT-->[\s\S]*?<!--\/EXTENSION_CARDS_SCRIPT-->/',
                static fn(): string => $carouselScript,
                $html
            ) ?? $html;
        } elseif ($name === 'remove.html' || $name === 'admin.html') {
            $prefix = $name === 'admin.html' ? 'Admin' : 'Remove a site';
            $titleTag = '<title>' . $prefix . ' — ' . self::escapeHtml($safe['title']) . '</title>';
            $html = preg_replace_callback(
                '/<title>[\s\S]*?<\/title>/',
                static fn(): string => $titleTag,
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Loads `src/templates/$name` and renders it with instance metadata.
     *
     * @param array<string, mixed> $meta
     * @param list<array{id: string, title: string, tagline: string, href: string, cta: string, art: string, accent: string}> $extensionCards
     */
    public static function renderTemplateFile(string $name, array $meta, ?PDO $pdo = null, array $extensionCards = []): string
    {
        $templatePath = __DIR__ . '/templates/' . $name;
        if (!is_file($templatePath)) {
            throw new AppError(500, 'missing_template', "Template {$name} not found.");
        }
        $rawHtml = (string) file_get_contents($templatePath);
        return self::renderInstanceHtml($name, $rawHtml, $meta, $pdo, $extensionCards);
    }
}
