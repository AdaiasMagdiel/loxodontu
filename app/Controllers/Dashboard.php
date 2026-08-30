<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use stdClass;

/**
 * Renders the dashboard's server-routed pages. Each route maps to its own
 * template, which mounts a single Vue component scoped to that page --
 * navigation between them is plain browser navigation, not client-side
 * routing. Data within a page (fetching, forms, expand/collapse) is still
 * driven by Vue, calling the JSON API in app/Controllers/*.php with the
 * platform token stored client-side (there's no server session to render
 * authenticated data with).
 */
class Dashboard
{
    public static function home(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/index'), ['activeNav' => 'home']);
    }

    public static function account(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/account'), ['activeNav' => 'account']);
    }

    public static function projects(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/index'), ['activeNav' => 'projects']);
    }

    public static function projectOverview(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/show'), [
            'activeNav' => 'overview',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectTables(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/tables'), [
            'activeNav' => 'tables',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectSql(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/sql'), [
            'activeNav' => 'sql',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectKeys(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/keys'), [
            'activeNav' => 'keys',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectFunctions(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/functions'), [
            'activeNav' => 'functions',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectCronJobs(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/cron-jobs'), [
            'activeNav' => 'cron-jobs',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectEndUsers(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/end-users'), [
            'activeNav' => 'end-users',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectStorage(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/storage'), [
            'activeNav' => 'storage',
            'projectId' => $params->project_id,
        ]);
    }
}
