<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use stdClass;

/**
 * Renders the dashboard's server-routed pages. Each route maps to its own
 * template, which mounts a single scoped Vue component, which handles all
 * data fetching, forms, and updates on that page by calling the same JSON
 * API documented throughout these pages, authenticated with the platform
 * token that's stored client-side (there's no server-side session to render
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

    public static function projectTableEditor(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/table-editor'), [
            'activeNav' => 'table-editor',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectSql(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/sql'), [
            'activeNav' => 'sql-editor',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectDatabase(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/database'), [
            'activeNav' => 'database',
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

    public static function projectFunctionDetail(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/function-detail'), [
            'activeNav' => 'functions',
            'projectId' => $params->project_id,
            'functionId' => $params->function_id,
        ]);
    }

    public static function projectCronJobs(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/cron-jobs'), [
            'activeNav' => 'cron-jobs',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectCronJobDetail(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/cron-job-detail'), [
            'activeNav' => 'cron-jobs',
            'projectId' => $params->project_id,
            'jobId' => $params->job_id,
        ]);
    }

    public static function projectStorage(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/storage'), [
            'activeNav' => 'storage-buckets',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectStorageDetail(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/storage-detail'), [
            'activeNav' => 'storage-buckets',
            'projectId' => $params->project_id,
            'bucketId' => $params->bucket_id,
        ]);
    }

    public static function projectAuthUsers(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/auth-users'), [
            'activeNav' => 'auth-users',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectAuthProviders(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/auth-providers'), [
            'activeNav' => 'auth-providers',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectAuthTemplates(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/auth-templates'), [
            'activeNav' => 'auth-templates',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectAuthTemplateEditor(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/auth-template-editor'), [
            'activeNav' => 'auth-templates',
            'projectId' => $params->project_id,
            'templateKey' => $params->key,
        ]);
    }

    public static function projectAuthSettings(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/auth-settings'), [
            'activeNav' => 'auth-settings',
            'projectId' => $params->project_id,
        ]);
    }

    public static function projectSettings(Request $req, Response $res, stdClass $params): Response
    {
        return $res->withTemplate(t('dashboard/projects/settings'), [
            'activeNav' => 'settings',
            'projectId' => $params->project_id,
        ]);
    }
}
