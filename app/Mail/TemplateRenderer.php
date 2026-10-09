<?php

namespace App\Mail;

class TemplateRenderer
{
    /** @param array<string, string> $vars */
    public static function render(string $body, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return strtr($body, $replacements);
    }
}
