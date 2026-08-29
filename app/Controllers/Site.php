<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use stdClass;

class Site
{
    public static function index(Request $req, Response $res, stdClass $params)
    {
        return $res->withTemplate(t('site/index'));
    }
}
