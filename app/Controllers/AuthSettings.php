<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Auth\RedirectAllowlist;
use App\Database;
use stdClass;

/**
 * A project's auth URL settings: the Site URL used by auth emails when the
 * client sends no redirect_url, and the allowlist a client-supplied
 * redirect_url must match (see App\Auth\RedirectAllowlist).
 */
class AuthSettings
{
    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $project = self::findOwnedProject(Database::getConn('default'), $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        return $res->withJson(self::present($project));
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $siteUrl = $project['auth_site_url'];
        if (array_key_exists('site_url', $body)) {
            $siteUrl = null;
            if ($body['site_url'] !== null && trim((string) $body['site_url']) !== '') {
                $siteUrl = RedirectAllowlist::normalizeEntry((string) $body['site_url']);
                if ($siteUrl === null) {
                    return $res->setStatusCode(422)->withJson(['error' => 'site_url must be an absolute http or https URL']);
                }
            }
        }

        $redirectUrls = self::decodeList($project['auth_redirect_urls']);
        if (array_key_exists('redirect_urls', $body)) {
            if (!is_array($body['redirect_urls']) || !array_is_list($body['redirect_urls'])) {
                return $res->setStatusCode(422)->withJson(['error' => 'redirect_urls must be an array of URLs']);
            }
            if (count($body['redirect_urls']) > RedirectAllowlist::MAX_ENTRIES) {
                return $res->setStatusCode(422)->withJson(['error' => 'redirect_urls accepts at most ' . RedirectAllowlist::MAX_ENTRIES . ' URLs']);
            }

            $redirectUrls = [];
            foreach ($body['redirect_urls'] as $url) {
                $normalized = is_string($url) ? RedirectAllowlist::normalizeEntry($url) : null;
                if ($normalized === null) {
                    return $res->setStatusCode(422)->withJson(['error' => 'Every redirect URL must be an absolute http or https URL: ' . (is_string($url) ? $url : json_encode($url))]);
                }
                $redirectUrls[] = $normalized;
            }
            $redirectUrls = array_values(array_unique($redirectUrls));
        }

        $pdo->prepare('UPDATE projects SET auth_site_url = ?, auth_redirect_urls = ? WHERE id = ?')
            ->execute([$siteUrl, json_encode($redirectUrls), $project['id']]);

        return $res->withJson(['site_url' => $siteUrl, 'redirect_urls' => $redirectUrls]);
    }

    /**
     * The redirect target for an auth email: the client's redirect_url when
     * allowed, otherwise the Site URL (or APP_URL when none is set). Returns
     * false when a redirect_url was supplied but is not allowed.
     */
    public static function resolveRedirect(\PDO $pdo, int $projectId, mixed $requested): string|false
    {
        $stmt = $pdo->prepare('SELECT auth_site_url, auth_redirect_urls FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch() ?: ['auth_site_url' => null, 'auth_redirect_urls' => null];

        if ($requested === null || $requested === '') {
            return $project['auth_site_url'] ?? (string) env('APP_URL', '');
        }

        if (!is_string($requested)
            || !RedirectAllowlist::isAllowed($requested, $project['auth_site_url'], self::decodeList($project['auth_redirect_urls']))) {
            return false;
        }

        return $requested;
    }

    /** @return array{site_url: ?string, redirect_urls: string[]} */
    private static function present(array $project): array
    {
        return ['site_url' => $project['auth_site_url'], 'redirect_urls' => self::decodeList($project['auth_redirect_urls'])];
    }

    /** @return string[] */
    private static function decodeList(?string $json): array
    {
        $list = $json !== null ? json_decode($json, true) : [];

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, auth_site_url, auth_redirect_urls FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$publicId, $userId]);

        return $stmt->fetch();
    }
}
