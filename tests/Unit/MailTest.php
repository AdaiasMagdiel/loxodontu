<?php

use App\Mail\DefaultTemplates;
use App\Mail\TemplateRenderer;

test('renders placeholders and escapes their values', function () {
    $rendered = TemplateRenderer::render('Hi {{name}}, click {{link}}', [
        'name' => '<b>Bob</b>',
        'link' => 'https://example.test/confirm?x=1&y=2',
    ]);

    expect($rendered)->toBe('Hi &lt;b&gt;Bob&lt;/b&gt;, click https://example.test/confirm?x=1&amp;y=2');
});

test('leaves unknown placeholders untouched', function () {
    $rendered = TemplateRenderer::render('{{known}} and {{unknown}}', ['known' => 'value']);

    expect($rendered)->toBe('value and {{unknown}}');
});

test('every default template key has a subject and body', function () {
    foreach (DefaultTemplates::KEYS as $key) {
        $template = DefaultTemplates::get($key);

        expect($template)->toHaveKeys(['subject', 'body']);
        expect($template['subject'])->not->toBeEmpty();
        expect($template['body'])->toContain('{{link}}');
    }
});

test('throws for an unknown template key', function () {
    expect(fn () => DefaultTemplates::get('not-a-real-key'))->toThrow(InvalidArgumentException::class);
});
