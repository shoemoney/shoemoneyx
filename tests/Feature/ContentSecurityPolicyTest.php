<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $deskPasswordSet = false;

    public function test_login_page_has_script_src_self_and_loads_no_script(): void
    {
        app(Settings::class)->setMasterPassword('owner-chosen-password');

        $response = $this->get('/login')->assertOk();

        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $response->getContent());
    }

    public function test_login_page_renders_its_bundled_stylesheet_and_no_script_through_real_vite(): void
    {
        $directory = 'csp-fixture-'.uniqid();
        File::ensureDirectoryExists(public_path($directory));
        File::put(public_path("{$directory}/manifest.json"), json_encode([
            'resources/css/login.css' => ['file' => 'assets/login-fixture.css', 'src' => 'resources/css/login.css', 'isEntry' => true],
        ]));

        try {
            $this->withVite();
            Vite::useBuildDirectory($directory);
            app(Settings::class)->setMasterPassword('owner-chosen-password');

            $html = $this->get('/login')->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<link[^>]+rel="stylesheet"[^>]+assets/login-fixture\.css#', $html);
            $this->assertStringNotContainsString('<script', $html);
        } finally {
            File::deleteDirectory(public_path($directory));
        }
    }

    public function test_spa_shell_carries_the_policy(): void
    {
        $this->signInAsOwner();
        // Unfinished onboarding redirects the root, so finish it to reach the real shell.
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            OnboardingWizard::ORDER,
            ['status' => 'done'],
        )]);

        $response = $this->get('/')->assertOk()->assertHeader('Content-Security-Policy', ContentSecurityPolicy::POLICY);

        $this->assertStringContainsString('id="app"', $response->getContent());
        $this->assertStringNotContainsString('<script', preg_replace('#<script type="application/ld\+json".*?</script>#s', '', $response->getContent()));
    }

    public function test_no_view_ships_an_executable_inline_or_external_script(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<script\b[^>]*>/i', $file->getContents(), $tags);
            foreach ($tags[0] as $tag) {
                preg_match('/\btype\s*=\s*["\']([^"\']*)["\']/i', $tag, $type);

                $this->assertSame(
                    'application/ld+json',
                    strtolower($type[1] ?? ''),
                    "{$file->getRelativePathname()} adds a script that script-src 'self' will block: {$tag}",
                );
            }
        }
    }
}
