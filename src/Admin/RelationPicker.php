<?php

declare(strict_types=1);

namespace PhpSso\Admin;

use PhpSso\Store\Clients;
use PhpSso\Store\Groups;
use PhpSso\Store\Relation;
use PhpSso\Store\Relations;
use PhpSso\Store\Roles;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * The admin "relation picker": one control, used wherever people or groups are linked to something.
 * Search to add, a paged and filterable list of what is linked, remove, remove everything matching the filter. Every action applies immediately.
 *
 *   group-users   on a group's page: its members
 *   user-groups   on a person's page: the groups they are in
 *   client-groups on a client's page: groups allowed to sign in
 *   client-users  on a client's page: people allowed to sign in
 *   role-groups   on a role's page: groups that have the role
 *   role-users    on a role's page: users that have the role directly
 */
final class RelationPicker
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private Environment $twig,
        private Relations $relations,
        private Users $users,
        private Groups $groups,
        private Clients $clients,
        private Roles $roles,
    ) {
    }

    /** What each relation looks like on screen. `key` namespaces its query parameters, `anchor` its place on the page. */
    private function specs(): array
    {
        $people = [
            'key' => 'users', 'item' => 'user', 'one' => 'user', 'many' => 'users', 'add' => 'Add users',
            'placeholder' => 'Search by name, username or email',
        ];
        $groups = [
            'key' => 'groups', 'item' => 'group', 'one' => 'group', 'many' => 'groups', 'add' => 'Add groups',
            'placeholder' => 'Search groups by name',
        ];

        return [
            'group-users' => $people + ['anchor' => 'members', 'title' => 'Members', 'list' => 'In this group', 'intro' => 'Changes here apply straight away; there is nothing to save.',
                'empty' => 'Nobody is in this group yet. Search above to add users.'],
            'user-groups' => $groups + ['anchor' => 'groups', 'title' => 'Groups', 'list' => 'Member of', 'intro' => 'Apps that ask for the groups scope receive these names. Changes here apply straight away.',
                'empty' => 'This user is not in any group yet. Search above to add one.'],
            'client-groups' => $groups + ['anchor' => 'allowed-groups', 'title' => 'Allowed groups', 'list' => 'Allowed', 'intro' => 'Everyone in these groups can sign in. Changes here apply straight away.',
                'empty' => 'No groups added yet.'],
            'client-users' => $people + ['anchor' => 'allowed-users', 'title' => 'Allowed users', 'list' => 'Allowed', 'intro' => 'These users can sign in even if they are in none of the groups. Changes here apply straight away.',
                'empty' => 'No individual users added yet.'],
            'role-groups' => $groups + ['anchor' => 'role-groups', 'title' => 'Groups', 'list' => 'Have this role', 'intro' => 'Everyone in these groups has the role. Changes here apply straight away.',
                'empty' => 'No groups added yet.'],
            'role-users' => $people + ['anchor' => 'role-users', 'title' => 'Users', 'list' => 'Have this role', 'intro' => 'These users have the role whatever groups they are in. Changes here apply straight away.',
                'empty' => 'No individual users added yet.'],
        ];
    }

    /** The page the control lives on, or null when the owner does not exist. @return array{id: string|int, name: string, page: string}|null */
    private function owner(string $name, string $id): ?array
    {
        switch ($name) {
            case 'group-users':
                $g = $this->groups->find((int) $id);

                return $g === null ? null : ['id' => $g['id'], 'name' => $g['name'], 'page' => '/admin/groups/' . $g['id'] . '/members'];
            case 'user-groups':
                $u = $this->users->find($id);

                return $u === null ? null : ['id' => $u['sub'], 'name' => $u['name'], 'page' => '/admin/users/' . rawurlencode($u['sub']) . '/groups'];
            case 'client-groups':
            case 'client-users':
                $c = $this->clients->find($id);

                return $c === null ? null : ['id' => $c['client_id'], 'name' => $c['name'], 'page' => '/admin/clients/' . rawurlencode($c['client_id']) . '/access'];
            case 'role-groups':
            case 'role-users':
                $r = $this->roles->find((int) $id);

                return $r === null ? null : ['id' => $r['id'], 'name' => $r['name'], 'page' => '/admin/roles/' . $r['id'] . ($name === 'role-groups' ? '/groups' : '/users')];
        }

        return null;
    }

    /** Everything the control's template needs. Reads this relation's filter, page and search from the query string. */
    public function view(string $name, string|int $ownerId, string $csrf): array
    {
        $spec = $this->specs()[$name];
        $relation = $this->relations->get($name);
        $owner = $this->owner($name, (string) $ownerId);
        $key = $spec['key'];

        $param = static function (string $p) use ($key): string {
            $v = $_GET["{$key}_{$p}"] ?? '';

            return is_string($v) ? trim($v) : '';
        };
        $query = $param('m');
        $find = $param('find');

        $matching = $relation->count($owner['id'], $query);
        $pages = max(1, (int) ceil($matching / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $param('page')));

        return $spec + [
            'name' => $name,
            'owner' => rawurlencode((string) $owner['id']),
            'ownerName' => $owner['name'],
            'pageUrl' => $owner['page'],
            'csrf' => $csrf,
            'total' => $query === '' ? $matching : $relation->count($owner['id'], ''),
            'matching' => $matching,
            'rows' => $this->rows($spec['item'], $relation->page($owner['id'], $query, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)),
            'query' => $query,
            'page' => $page,
            'pages' => $pages,
            'from' => $matching === 0 ? 0 : ($page - 1) * self::PAGE_SIZE + 1,
            'to' => min($matching, $page * self::PAGE_SIZE),
            'links' => Pager::links($page, $pages),
            'find' => $find,
            'candidates' => $find === '' ? [] : $this->rows($spec['item'], $relation->candidates($owner['id'], $find, 8)),
        ];
    }

    public function handle(ServerRequestInterface $request, string $name, string $ownerId, string $action): ResponseInterface
    {
        $relation = $this->relations->get($name);
        $owner = $relation === null ? null : $this->owner($name, $ownerId);
        if ($relation === null || $owner === null) {
            return new TextResponse('Not found', 404);
        }
        $spec = $this->specs()[$name];
        $body = (array) $request->getParsedBody();
        $back = $owner['page'] . '#' . $spec['anchor'];

        switch ($action) {
            case 'candidates':
                $q = $request->getQueryParams()['q'] ?? '';
                $q = is_string($q) ? trim($q) : '';

                return new HtmlResponse($this->twig->render('admin/_candidates.html.twig', [
                    'r' => $this->candidatesContext($name, $spec, $owner, $q, $q === '' ? [] : $relation->candidates($owner['id'], $q, 8)),
                    'csrf' => $_SESSION['csrf'],
                ]));

            case 'add':
                $item = $this->itemName($spec['item'], (string) ($body['item'] ?? ''));
                if ($item === null) {
                    return new TextResponse('Not found', 404);
                }
                $relation->add($owner['id'], $body['item']);

                return $this->done($back, "{$item} added.");

            case 'remove':
                $item = $this->itemName($spec['item'], (string) ($body['item'] ?? ''));
                if ($item === null) {
                    return new TextResponse('Not found', 404);
                }
                $relation->remove($owner['id'], $body['item']);

                return $this->done($back, "{$item} removed.");

            case 'remove-all':
                $removed = $relation->removeMatching($owner['id'], trim((string) ($body['m'] ?? '')));

                return $this->done($back, ($removed === 1 ? "1 {$spec['one']}" : "{$removed} {$spec['many']}") . ' removed.');
        }

        return new TextResponse('Not found', 404);
    }

    /** @return array the variables _candidates.html.twig reads */
    private function candidatesContext(string $name, array $spec, array $owner, string $find, array $found): array
    {
        return ['name' => $name, 'owner' => rawurlencode((string) $owner['id']), 'find' => $find, 'candidates' => $this->rows($spec['item'], $found)];
    }

    /** Adds a link target and an avatar seed to raw item rows. */
    private function rows(string $kind, array $items): array
    {
        return array_map(static fn (array $i) => $i + [
            'url' => $kind === 'user' ? '/admin/users/' . rawurlencode($i['id']) . '/edit' : '/admin/groups/' . $i['id'] . '/edit',
        ], $items);
    }

    /** The display name of an existing user or group, or null. */
    private function itemName(string $kind, string $id): ?string
    {
        $row = $kind === 'user' ? $this->users->find($id) : $this->groups->find((int) $id);

        return $row['name'] ?? null;
    }


    private function done(string $to, string $message, string $type = 'success'): ResponseInterface
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];

        return new RedirectResponse($to);
    }
}
