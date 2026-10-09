<?php

namespace App\Mail;

use RuntimeException;

/** Thrown when a project has no project_email_configs row yet. */
class EmailNotConfiguredException extends RuntimeException
{
}
