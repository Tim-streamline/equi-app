<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel normally skips CSRF checks in feature tests.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_uploads_after_inertia_login_require_the_rotated_cookie_token(): void
    {
        AdminUser::create([
            'name' => 'Editor', 'email' => 'csrf-editor@example.test',
            'password' => 'test-password', 'role' => 'content_editor', 'active' => true,
        ]);
        $this->get('/admin/login')->assertOk();
        $originalToken = session()->token();

        $login = $this->withHeaders(['X-CSRF-TOKEN' => $originalToken, 'X-Inertia' => 'true'])
            ->post('/admin/login', ['email' => 'csrf-editor@example.test', 'password' => 'test-password'])
            ->assertRedirect('/admin');
        $this->assertNotSame($originalToken, session()->token());
        $cookie = $login->getCookie('XSRF-TOKEN', false)->getValue();
        $this->flushHeaders();

        // The HTML meta token from before the Inertia login is now invalid.
        $this->withHeader('X-CSRF-TOKEN', $originalToken)
            ->postJson('/admin/library/media', ['file' => UploadedFile::fake()->image('old.png')])
            ->assertStatus(419);
        $this->withHeader('Upload-Length', '68')->postJson('/admin/library/media/chunks')->assertStatus(419);
        $this->assertDatabaseCount('media_assets', 0);
        $this->flushHeaders();

        $this->withHeader('X-XSRF-TOKEN', $cookie)
            ->postJson('/admin/library/media', ['file' => UploadedFile::fake()->image('thumbnail.png'), 'purpose' => 'thumbnail'])
            ->assertOk()->assertJsonPath('asset.type', 'image');

        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $upload = $this->withHeader('Upload-Length', (string) strlen($contents))
            ->post('/admin/library/media/chunks')->assertOk()->getContent();

        $this->call('PATCH', "/admin/library/media/chunks/{$upload}", server: [
            'HTTP_X_CSRF_TOKEN' => $originalToken,
        ], content: $contents)->assertStatus(419);
        $this->flushHeaders();
        $this->withHeader('X-XSRF-TOKEN', $cookie)->call('PATCH', "/admin/library/media/chunks/{$upload}", server: [
            'HTTP_X_XSRF_TOKEN' => $cookie,
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => (string) strlen($contents),
            'HTTP_UPLOAD_NAME' => 'pixel.png',
        ], content: $contents)->assertNoContent();

        $asset = $this->getJson("/admin/library/media/chunks/{$upload}/asset")
            ->assertOk()->assertJsonPath('asset.type', 'image')->json('asset');
        Storage::disk('public')->assertExists($asset['path']);
        $this->assertDatabaseHas('media_assets', ['id' => $asset['id']]);
        $this->deleteJson("/admin/library/media/{$asset['id']}")->assertSuccessful();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset['id']]);
    }
}
