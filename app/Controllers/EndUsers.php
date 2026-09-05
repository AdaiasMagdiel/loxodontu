<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Auth\EndUserAuth;
use App\Database;
use App\Mail\EmailNotConfiguredException;
use App\Mail\MailerFactory;
use App\Pagination;
use stdClass;

/**
 * End users are the accounts of a *project's own app* (the developer's
 * users), completely separate from platform users in `users` /
 * `platform_auth_tokens`. Their token authenticates REST passthrough
 * requests and feeds `$auth.id` / `$auth.email` / `$auth.role` into RLS
 * policy expressions.
 */
class EndUsers
{
    public static function register(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email and password are required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'Invalid email']);
        }

        if (strlen($password) < 8) {
            return $res->setStatusCode(422)->withJson(['error' => 'Password must be at least 8 characters']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1');
        $stmt->execute([$project['id'], $email]);

        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Email already registered']);
        }

        // Role is never client-assignable at registration — only a project owner
        // can grant elevated roles, via the management endpoint below.
        $pdo->prepare('INSERT INTO project_end_users (project_id, email, password) VALUES (?, ?, ?)')->execute([
            $project['id'],
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();

        // With confirmation required, registering does not also log the user
        // in — they must verify first, same as a plain login would demand below.
        if (self::requiresEmailConfirmation($pdo, $project['id'])) {
            self::sendPurposeEmail($pdo, $project['id'], $userId, $email, 'email_verification', $body['redirect_url'] ?? null);

            return $res->setStatusCode(201)->withJson([
                'token' => null,
                'user'  => ['id' => $userId, 'email' => $email, 'role' => null],
                'email_verification_required' => true,
            ]);
        }

        $token = self::issueToken($pdo, $userId);

        return $res->setStatusCode(201)->withJson([
            'token' => $token,
            'user'  => ['id' => $userId, 'email' => $email, 'role' => null],
        ]);
    }

    public static function login(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email and password are required']);
        }

        $stmt = $pdo->prepare(
            'SELECT id, email, password, role, email_verified_at FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([$project['id'], $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid credentials']);
        }

        if ($user['email_verified_at'] === null && self::requiresEmailConfirmation($pdo, $project['id'])) {
            return $res->setStatusCode(403)->withJson(['error' => 'Email not verified']);
        }

        $token = self::issueToken($pdo, $user['id']);

        return $res->withJson([
            'token' => $token,
            'user'  => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']],
        ]);
    }

    public static function logout(Request $req, Response $res, stdClass $params): Response
    {
        $hash = hash('sha256', substr($req->getHeader('Authorization') ?? '', 7));
        Database::getConn('default')->prepare('DELETE FROM project_end_user_tokens WHERE token_hash = ?')->execute([$hash]);

        return $res->setStatusCode(204);
    }

    /**
     * Requests a magic link login email. Always 200 whether or not the email
     * is registered, so a caller can never use this to enumerate accounts.
     */
    public static function requestMagicLink(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email = trim($body['email'] ?? '');
        if ($email === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email is required']);
        }

        $user = self::findEndUserByEmail($pdo, $project['id'], $email);
        if ($user) {
            self::sendPurposeEmail($pdo, $project['id'], $user['id'], $email, 'magic_link', $body['redirect_url'] ?? null, '+15 minutes');
        }

        return $res->withJson(['message' => 'If that email is registered, a sign-in link has been sent.']);
    }

    /** Consumes a magic-link token and logs the user in, exactly like login. */
    public static function consumeMagicLink(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $tokenRow = self::consumeToken($pdo, $project['id'], $body['token'] ?? '', 'magic_link');
        if (!$tokenRow) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid or expired token']);
        }

        $token = self::issueToken($pdo, $tokenRow['end_user_id']);

        return $res->withJson([
            'token' => $token,
            'user'  => ['id' => $tokenRow['end_user_id'], 'email' => $tokenRow['email'], 'role' => $tokenRow['role']],
        ]);
    }

    /** Requests a password reset email. Enumeration-safe like requestMagicLink. */
    public static function requestPasswordReset(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email = trim($body['email'] ?? '');
        if ($email === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email is required']);
        }

        $user = self::findEndUserByEmail($pdo, $project['id'], $email);
        if ($user) {
            self::sendPurposeEmail($pdo, $project['id'], $user['id'], $email, 'password_reset', $body['redirect_url'] ?? null, '+30 minutes');
        }

        return $res->withJson(['message' => 'If that email is registered, a password reset link has been sent.']);
    }

    /** Consumes a password-reset token and sets a new password. */
    public static function resetPassword(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $password = $body['password'] ?? '';
        if (strlen($password) < 8) {
            return $res->setStatusCode(422)->withJson(['error' => 'Password must be at least 8 characters']);
        }

        $tokenRow = self::consumeToken($pdo, $project['id'], $body['token'] ?? '', 'password_reset');
        if (!$tokenRow) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid or expired token']);
        }

        $pdo->prepare('UPDATE project_end_users SET password = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $tokenRow['end_user_id']]);

        // A password reset invalidates every existing session for this account.
        $pdo->prepare("DELETE FROM project_end_user_tokens WHERE end_user_id = ? AND purpose = 'session'")
            ->execute([$tokenRow['end_user_id']]);

        return $res->withJson(['message' => 'Password updated.']);
    }

    /** Resends the email-verification email. Enumeration-safe like requestMagicLink. */
    public static function resendVerification(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email = trim($body['email'] ?? '');
        if ($email === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email is required']);
        }

        $user = self::findEndUserByEmail($pdo, $project['id'], $email);
        if ($user && $user['email_verified_at'] === null) {
            self::sendPurposeEmail($pdo, $project['id'], $user['id'], $email, 'email_verification', $body['redirect_url'] ?? null);
        }

        return $res->withJson(['message' => 'If that email is registered and unverified, a confirmation link has been sent.']);
    }

    /** Consumes an email-verification token, marking the account's email verified. */
    public static function verifyEmail(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $tokenRow = self::consumeToken($pdo, $project['id'], $body['token'] ?? '', 'email_verification');
        if (!$tokenRow) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid or expired token']);
        }

        $pdo->prepare('UPDATE project_end_users SET email_verified_at = NOW() WHERE id = ?')->execute([$tokenRow['end_user_id']]);

        return $res->withJson(['message' => 'Email verified.']);
    }

    /** Requests an email change — the confirmation link is sent to the *new* address. Requires X-User-Token. */
    public static function requestEmailChange(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $auth = EndUserAuth::resolve($pdo, $req, $project['id']);
        if (!$auth) {
            return $res->setStatusCode(401)->withJson(['error' => 'Authentication required']);
        }

        $newEmail = trim($body['new_email'] ?? '');
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'A valid new_email is required']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1');
        $stmt->execute([$project['id'], $newEmail]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Email already in use']);
        }

        $token = self::issueToken($pdo, $auth['id'], 'email_change', '+30 minutes', $newEmail);
        self::deliverEmail($project['id'], $newEmail, 'email_change', [
            'link' => self::buildLink($body['redirect_url'] ?? null, $token),
            'new_email' => $newEmail,
        ]);

        return $res->withJson(['message' => 'A confirmation link has been sent to the new email address.']);
    }

    /** Consumes an email-change token, applying the new address (proof of ownership = the link itself). */
    public static function confirmEmailChange(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $tokenRow = self::consumeToken($pdo, $project['id'], $body['token'] ?? '', 'email_change');
        if (!$tokenRow || $tokenRow['new_email'] === null) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid or expired token']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1');
        $stmt->execute([$project['id'], $tokenRow['new_email']]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Email already in use']);
        }

        $pdo->prepare('UPDATE project_end_users SET email = ?, email_verified_at = NOW() WHERE id = ?')
            ->execute([$tokenRow['new_email'], $tokenRow['end_user_id']]);

        return $res->withJson(['message' => 'Email updated.', 'email' => $tokenRow['new_email']]);
    }

    /** Lists a project's end users. Platform-owner only. */
    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_end_users WHERE project_id = ?');
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, email, role, created_at FROM project_end_users WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($stmt->fetchAll());
    }

    /** Sets an end user's role (e.g. "manager", "admin"). Platform-owner only. */
    public static function updateRole(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->end_user_id, $project['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return $res->setStatusCode(404)->withJson(['error' => 'End user not found']);
        }

        $role = array_key_exists('role', $body) ? $body['role'] : false;
        if ($role === false) {
            return $res->setStatusCode(422)->withJson(['error' => 'role is required (use null to clear it)']);
        }

        if ($role !== null && (!is_string($role) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $role))) {
            return $res->setStatusCode(422)->withJson(['error' => 'role must be null or an alphanumeric string (max 64 chars)']);
        }

        $pdo->prepare('UPDATE project_end_users SET role = ? WHERE id = ?')->execute([$role, $user['id']]);

        return $res->withJson(['id' => (int) $user['id'], 'role' => $role]);
    }

    /** Removes an end user. Platform-owner only. */
    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->end_user_id, $project['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return $res->setStatusCode(404)->withJson(['error' => 'End user not found']);
        }

        $pdo->prepare('DELETE FROM project_end_users WHERE id = ?')->execute([$user['id']]);

        return $res->setStatusCode(204);
    }

    private static function issueToken(
        \PDO $pdo,
        int $endUserId,
        string $purpose = 'session',
        string $ttl = '+30 days',
        ?string $newEmail = null,
    ): string {
        $token = bin2hex(random_bytes(32));

        $pdo->prepare(
            'INSERT INTO project_end_user_tokens (end_user_id, purpose, token_hash, expires_at, new_email)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$endUserId, $purpose, hash('sha256', $token), date('Y-m-d H:i:s', strtotime($ttl)), $newEmail]);

        return $token;
    }

    /**
     * Validates and single-use-consumes a token for a given purpose, returning
     * the owning end user's row (plus the token's new_email, if any) or false.
     */
    private static function consumeToken(\PDO $pdo, int $projectId, string $rawToken, string $purpose): array|false
    {
        if ($rawToken === '') {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.new_email, u.id AS end_user_id, u.email, u.role
             FROM project_end_user_tokens t
             JOIN project_end_users u ON u.id = t.end_user_id
             WHERE t.token_hash = ? AND t.purpose = ? AND u.project_id = ?
                   AND t.expires_at > NOW() AND t.consumed_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $rawToken), $purpose, $projectId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $pdo->prepare('UPDATE project_end_user_tokens SET consumed_at = NOW() WHERE id = ?')->execute([$row['token_id']]);

        return $row;
    }

    private static function findEndUserByEmail(\PDO $pdo, int $projectId, string $email): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, email, email_verified_at FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $email]);
        return $stmt->fetch();
    }

    private static function requiresEmailConfirmation(\PDO $pdo, int $projectId): bool
    {
        $stmt = $pdo->prepare('SELECT require_email_confirmation FROM project_email_configs WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Issues a purpose token and emails its link to the end user. Silently no-ops if email isn't configured. */
    private static function sendPurposeEmail(
        \PDO $pdo,
        int $projectId,
        int $endUserId,
        string $email,
        string $purpose,
        ?string $redirectUrl,
        string $ttl = '+1 day',
    ): void {
        $token = self::issueToken($pdo, $endUserId, $purpose, $ttl);

        self::deliverEmail($projectId, $email, $purpose, [
            'link' => self::buildLink($redirectUrl, $token),
            'email' => $email,
        ]);
    }

    /** Renders and sends a template email for one of the four flow purposes. Swallows delivery failures. */
    private static function deliverEmail(int $projectId, string $to, string $templateKey, array $vars): void
    {
        try {
            $pdo = Database::getConn('default');
            $stmt = $pdo->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);

            $vars += ['project_name' => (string) $stmt->fetchColumn()];

            $rendered = MailerFactory::renderTemplate($projectId, $templateKey, $vars);
            MailerFactory::forProject($projectId)->send($to, $rendered['subject'], $rendered['body']);
        } catch (EmailNotConfiguredException) {
            // No provider configured yet — the flow still succeeds from the
            // caller's point of view (token issued), it just can't be emailed.
        }
    }

    /** Builds the link an end user clicks: the developer's own redirect URL plus the raw token. */
    private static function buildLink(?string $redirectUrl, string $token): string
    {
        $redirectUrl ??= env('APP_URL', '');
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';

        return "{$redirectUrl}{$separator}token={$token}";
    }

    private static function findProject(\PDO $pdo, mixed $publicId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicId]);
        return $stmt->fetch();
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
