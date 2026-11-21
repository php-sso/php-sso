<?php

declare(strict_types=1);

namespace PhpSso\Admin;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Template helpers for the admin panel. */
final class AdminTwigExtension extends AbstractExtension
{
    public function __construct(private string $publicDir)
    {
    }

    /** Stable hue (0-359) for a name, so a person or group always has the same color. */
    public static function hue(string $name): int
    {
        return crc32(mb_strtolower($name)) % 360;
    }

    /** Up to two initials: "Alice Example" => "AE", "bob" => "B". */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s._-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $letters = array_map(static fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters));
    }

    /** "just now", "5 min ago", "3 h ago", "2 d ago". */
    public static function ago(int $timestamp): string
    {
        $s = max(0, time() - $timestamp);

        return match (true) {
            $s < 45 => 'just now',
            $s < 3600 => max(1, intdiv($s, 60)) . ' min ago',
            $s < 86400 => intdiv($s, 3600) . ' h ago',
            default => intdiv($s, 86400) . ' d ago',
        };
    }

    /** "in 5 min", "in 2 h", "in 3 d"; "now" when the time has come. */
    public static function until(int $timestamp): string
    {
        $s = $timestamp - time();

        return match (true) {
            $s <= 0 => 'now',
            $s < 3600 => 'in ' . max(1, (int) ceil($s / 60)) . ' min',
            $s < 86400 => 'in ' . (int) ceil($s / 3600) . ' h',
            default => 'in ' . (int) ceil($s / 86400) . ' d',
        };
    }

    /** Short "Firefox on Linux" from a User-Agent header; "Unknown device" when nothing matches. */
    public static function device(string $ua): string
    {
        $browser = null;
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari', 'curl/' => 'curl'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $browser = $label;
                break;
            }
        }
        $os = null;
        foreach (['Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $os = $label;
                break;
            }
        }

        return match (true) {
            $browser !== null && $os !== null => "$browser on $os",
            $browser !== null => $browser,
            $os !== null => $os,
            default => 'Unknown device',
        };
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hue', self::hue(...)),
            new TwigFunction('initials', self::initials(...)),
            new TwigFunction('ago', self::ago(...)),
            new TwigFunction('until', self::until(...)),
            new TwigFunction('device', self::device(...)),
            // URL of a file in public/assets, with a cache-busting version.
            new TwigFunction('asset', fn (string $path): string => '/assets/' . $path . '?v=' . (@filemtime($this->publicDir . '/assets/' . $path) ?: 0)),
        ];
    }
}
