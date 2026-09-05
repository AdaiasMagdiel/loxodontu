<?php

namespace App\Mail;

/**
 * Built-in subject/body used whenever a project hasn't overridden a given
 * template_key in project_email_templates. Kept as code, not DB seed rows,
 * so improving a default later needs no data migration.
 */
class DefaultTemplates
{
    public const KEYS = ['magic_link', 'password_reset', 'email_verification', 'email_change'];

    /** @return array{subject: string, body: string} */
    public static function get(string $templateKey): array
    {
        return match ($templateKey) {
            'magic_link' => [
                'subject' => 'Your sign-in link for {{project_name}}',
                'body' => '<p>Hi,</p><p>Click the link below to sign in to {{project_name}}:</p>'
                    . '<p><a href="{{link}}">{{link}}</a></p>'
                    . '<p>If you did not request this, you can safely ignore this email.</p>',
            ],
            'password_reset' => [
                'subject' => 'Reset your password for {{project_name}}',
                'body' => '<p>Hi,</p><p>We received a request to reset the password for {{email}}.</p>'
                    . '<p><a href="{{link}}">{{link}}</a></p>'
                    . '<p>If you did not request this, you can safely ignore this email.</p>',
            ],
            'email_verification' => [
                'subject' => 'Confirm your email for {{project_name}}',
                'body' => '<p>Hi,</p><p>Please confirm your email address for {{project_name}}:</p>'
                    . '<p><a href="{{link}}">{{link}}</a></p>',
            ],
            'email_change' => [
                'subject' => 'Confirm your new email for {{project_name}}',
                'body' => '<p>Hi,</p><p>Confirm that {{new_email}} should become the email address for your {{project_name}} account:</p>'
                    . '<p><a href="{{link}}">{{link}}</a></p>'
                    . '<p>If you did not request this, you can safely ignore this email.</p>',
            ],
            default => throw new \InvalidArgumentException("Unknown template key: {$templateKey}"),
        };
    }
}
