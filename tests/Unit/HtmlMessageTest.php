<?php

namespace Tests\Unit;

use App\Support\HtmlMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HtmlMessageTest extends TestCase
{
    public function test_for_email_body_keeps_safe_html(): void
    {
        $html = '<p>Hello <strong>team</strong></p><script>alert(1)</script>';

        $result = HtmlMessage::forEmailBody($html);

        $this->assertStringContainsString('<strong>team</strong>', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function activeMarkup(): array
    {
        return [
            'event handler on an image' => ['<img src="x" onerror="alert(1)">', 'onerror'],
            'event handler on a link' => ['<a href="https://x.test" onclick="alert(1)">a</a>', 'onclick'],
            'javascript link' => ['<a href="javascript:alert(1)">a</a>', 'javascript:'],
            'javascript link hidden by a tab entity' => ['<a href="java&#9;script:alert(1)">a</a>', 'script:'],
            'data link' => ['<a href="data:text/html,<script>alert(1)</script>">a</a>', 'data:'],
            'style loading a url' => ['<p style="background:url(https://x.test/a)">a</p>', 'url('],
            'svg with onload' => ['<svg onload="alert(1)"></svg>', 'onload'],
        ];
    }

    #[DataProvider('activeMarkup')]
    public function test_sanitize_removes_markup_that_could_run_script(string $html, string $forbidden): void
    {
        $this->assertStringNotContainsString($forbidden, HtmlMessage::sanitize($html));
    }

    public function test_sanitize_keeps_formatting_web_links_and_placeholders(): void
    {
        $result = HtmlMessage::sanitize('<p style="color:red">Hi {{first_name}}</p><a href="{{accept_url}}">Yes</a> <a href="https://planner.test/r/abc">Link</a> <a href="mailto:a@b.test">Mail</a>');

        $this->assertStringContainsString('<p style="color:red">Hi {{first_name}}</p>', $result);
        $this->assertStringContainsString('href="{{accept_url}}"', $result);
        $this->assertStringContainsString('href="https://planner.test/r/abc"', $result);
        $this->assertStringContainsString('href="mailto:a@b.test"', $result);
    }

    public function test_for_email_body_escapes_plain_text(): void
    {
        $result = HtmlMessage::forEmailBody("Line 1\nLine 2");

        $this->assertStringContainsString('Line 1<br />', $result);
        $this->assertStringContainsString('Line 2', $result);
    }

    public function test_excerpt_strips_tags(): void
    {
        $this->assertSame(
            'Hello world',
            HtmlMessage::excerpt('<p>Hello <em>world</em></p>')
        );
    }
}
