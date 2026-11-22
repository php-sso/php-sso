<?php

declare(strict_types=1);

namespace PhpSso\Admin;

/** Which rows a numbered page shows, and which page numbers to link to. The page asked for is made to fit: out of range is the first or the last page. */
final class Pager
{
    public readonly int $pages;
    public readonly int $page;
    public readonly int $offset;

    /** @var array<int, int|null> the page numbers to link to, with null for a gap */
    public readonly array $links;

    public function __construct(public readonly int $total, public readonly int $perPage, mixed $requestedPage)
    {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min($this->pages, max(1, is_numeric($requestedPage) ? (int) $requestedPage : 1));
        $this->offset = ($this->page - 1) * $perPage;
        $this->links = self::links($this->page, $this->pages);
    }

    /**
     * Page numbers to show: the first and last page and a window around the current one, with gaps as null.
     *
     * @return array<int, int|null>
     */
    public static function links(int $page, int $pages): array
    {
        $numbers = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn (int $n) => $n >= 1 && $n <= $pages));
        sort($numbers);

        $links = [];
        $previous = 0;
        foreach ($numbers as $n) {
            if ($n - $previous > 1) {
                $links[] = null;
            }
            $links[] = $n;
            $previous = $n;
        }

        return $links;
    }
}
