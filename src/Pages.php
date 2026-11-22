<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Admin\AdminTwigExtension;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the pages people see (login, profile, errors). Templates are looked up in the theme
 * folder first and in the built-in `templates/pages` second, so a theme only has to contain the
 * files it wants to change. Built-in files are always reachable as `@sso/<file>`.
 */
final class Pages
{
    private const ASSET_TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    private string $themeAssets;
    private ?Environment $themed = null;
    private Environment $default;

    /** @var array<string, mixed> variables every page receives, set per request (see share()) */
    private array $shared = [];

    public function __construct(
        private string $builtinDir,
        private string $themeDir,
        string $cacheDir,
        private string $publicDir,
        private array $globals,
        private bool $debug,
    ) {
        $this->themeAssets = rtrim($themeDir, '/') . '/assets';
        $this->default = $this->environment([$builtinDir], $cacheDir . '/default');

        if (is_dir(rtrim($themeDir, '/') . '/templates')) {
            $this->themed = $this->environment([rtrim($themeDir, '/') . '/templates', $builtinDir], $cacheDir . '/theme');
        }
    }

    /** Makes a variable available to every page rendered from now on. */
    public function share(string $name, mixed $value): void
    {
        $this->shared[$name] = $value;
    }

    /** Renders a page. A theme that fails to render falls back to the built-in page (unless debug is on). */
    public function render(string $page, array $context = [], int $status = 200): ResponseInterface
    {
        $context += $this->shared;
        if ($this->themed !== null) {
            try {
                return new HtmlResponse($this->themed->render($page . '.html.twig', $context), $status);
            } catch (TwigError $e) {
                if ($this->debug) {
                    throw $e;
                }
                error_log("SSO theme error in {$page}: {$e->getMessage()}; using the built-in page");
            }
        }

        return new HtmlResponse($this->default->render($page . '.html.twig', $context), $status);
    }

    /**
     * Renders an email: `email_<name>.txt.twig` (whose `subject` block is the subject, and which is the plain text part) and `email_<name>.html.twig`. They are
     * looked up in the theme like pages are; a theme that fails to render falls back to the built-in email (unless debug is on).
     *
     * @param array<string, mixed> $context
     * @return array{subject: string, text: string, html: string}
     */
    public function email(string $name, array $context): array
    {
        $context += $this->shared;
        foreach ([$this->themed, $this->default] as $environment) {
            if ($environment === null) {
                continue;
            }
            try {
                $text = $environment->load("email_{$name}.txt.twig");
                $html = $environment->load("email_{$name}.html.twig");

                return [
                    'subject' => trim((string) preg_replace('/\s+/', ' ', $text->renderBlock('subject', $context))),
                    'text' => $text->render($context),
                    'html' => $html->render($context),
                ];
            } catch (TwigError $e) {
                if ($this->debug || $environment === $this->default) {
                    throw $e;
                }
                error_log("SSO theme error in the email {$name}: {$e->getMessage()}; using the built-in email");
            }
        }

        throw new \LogicException('There is no environment to render an email in.');
    }

    /** @return array{file: string, type: string}|null a servable file below the theme's assets folder */
    public function themeAsset(string $path): ?array
    {
        $root = realpath($this->themeAssets);
        $file = $root === false ? false : realpath($root . '/' . $path);
        $type = self::ASSET_TYPES[strtolower(pathinfo((string) $file, PATHINFO_EXTENSION))] ?? null;

        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || $type === null) {
            return null;
        }

        return ['file' => $file, 'type' => $type];
    }

    /** @param string[] $paths */
    private function environment(array $paths, string $cache): Environment
    {
        $loader = new FilesystemLoader($paths);
        $loader->addPath($this->builtinDir, 'sso');

        $twig = new Environment($loader, [
            'cache' => $cache,
            'auto_reload' => true,
            'strict_variables' => true,
            // HTML is escaped; a plain text template (`*.txt.twig`, the text part of an email) is not, in whatever file it is written, so a name such as O'Brien stays as typed.
            'autoescape' => static fn (string $name): string|false => str_ends_with($name, '.txt.twig') ? false : 'html',
        ]);
        $twig->addExtension(new AdminTwigExtension($this->publicDir));
        $twig->addGlobal('app_name', $this->globals['app_name']);
        $twig->addFunction(new TwigFunction('theme_asset', fn (string $path): string => '/theme/assets/' . $path . '?v=' . (@filemtime($this->themeAssets . '/' . $path) ?: 0)));
        $twig->addFunction(new TwigFunction('has_theme_asset', fn (string $path): bool => is_file($this->themeAssets . '/' . $path)));

        return $twig;
    }
}
