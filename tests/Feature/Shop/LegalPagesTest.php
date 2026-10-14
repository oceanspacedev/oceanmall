<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Support\LegalContent;
use Tests\TestCase;

final class LegalPagesTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = tempnam(sys_get_temp_dir(), 'legal');
        config(['support.legal_source' => $this->source, 'support.email' => '', 'support.whatsapp' => '']);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $bodies  slug => body of the only section
     */
    private function writeSource(bool $draft, array $bodies = []): void
    {
        $bodies += [
            'syarat-ketentuan' => 'Syarat berlaku. Hubungi {{contact}}.',
            'kebijakan-privasi' => 'Data aman. Hubungi {{contact}}.',
        ];

        $docs = [];
        foreach ($bodies as $slug => $body) {
            $docs[$slug] = [
                'title' => $slug === 'kebijakan-privasi' ? 'Kebijakan Privasi' : 'Syarat & Ketentuan',
                'updated' => '2026-10-14',
                'sections' => [['heading' => '1. Umum', 'body' => $body]],
            ];
        }

        file_put_contents($this->source, json_encode(['draft' => $draft, 'docs' => $docs]));
    }

    public function test_approved_text_with_a_contact_is_public_without_login(): void
    {
        $this->writeSource(draft: false);
        config(['support.email' => 'halo@oceanmall.test']);

        $this->get('/legal/kebijakan-privasi')
            ->assertOk()
            ->assertSee('Kebijakan Privasi')
            ->assertSee('Data aman. Hubungi email ke halo@oceanmall.test.')
            ->assertDontSee('Draf:')
            ->assertDontSee('{{contact}}', false);

        $this->get('/legal/syarat-ketentuan')->assertOk();
    }

    public function test_a_draft_flag_keeps_the_legal_pages_off_the_web(): void
    {
        $this->writeSource(draft: true);
        config(['support.email' => 'halo@oceanmall.test']);

        $this->get('/legal/kebijakan-privasi')->assertNotFound();
        $this->get('/legal/syarat-ketentuan')->assertNotFound();
        $this->get('/bantuan')->assertNotFound();
    }

    public function test_a_todo_marker_keeps_the_legal_pages_off_the_web_even_when_not_flagged_as_draft(): void
    {
        $this->writeSource(draft: false, bodies: ['kebijakan-privasi' => '[TODO: lama penyimpanan data.]']);
        config(['support.email' => 'halo@oceanmall.test']);

        $this->assertTrue(resolve(LegalContent::class)->isDraft());
        $this->get('/legal/kebijakan-privasi')->assertNotFound();
    }

    public function test_without_a_support_contact_nothing_is_published(): void
    {
        $this->writeSource(draft: false);

        $this->get('/legal/kebijakan-privasi')->assertNotFound();
        $this->get('/bantuan')->assertNotFound();
        $this->get('/hapus-akun')->assertNotFound();
    }

    public function test_a_missing_source_file_is_a_404_not_a_server_error(): void
    {
        config(['support.legal_source' => '/nonexistent/legal-content.json', 'support.email' => 'halo@oceanmall.test']);

        $this->get('/legal/kebijakan-privasi')->assertNotFound();
    }

    public function test_unknown_legal_slugs_are_not_found(): void
    {
        $this->writeSource(draft: false);
        config(['support.email' => 'halo@oceanmall.test']);

        $this->get('/legal/rahasia')->assertNotFound();
    }

    public function test_support_page_shows_the_configured_contact(): void
    {
        $this->writeSource(draft: false);
        config(['support.email' => 'halo@oceanmall.test', 'support.whatsapp' => '0812 3456 7890']);

        $this->get('/bantuan')
            ->assertOk()
            ->assertSee('halo@oceanmall.test')
            ->assertSee('https://wa.me/6281234567890', false)
            ->assertSee('+6281234567890');
    }

    public function test_whatsapp_alone_is_enough_and_the_text_names_it(): void
    {
        $this->writeSource(draft: false);
        config(['support.whatsapp' => '6281234567890']);

        $this->get('/legal/syarat-ketentuan')
            ->assertOk()
            ->assertSee('Hubungi WhatsApp ke +6281234567890.');
    }

    public function test_account_deletion_page_needs_only_a_contact_and_names_the_in_app_path(): void
    {
        $this->writeSource(draft: true);
        config(['support.email' => 'halo@oceanmall.test']);

        $this->get('/hapus-akun')
            ->assertOk()
            ->assertSee('Profil &amp; keamanan', false)
            ->assertSee('Hapus akun')
            ->assertSee('halo@oceanmall.test');
    }

    public function test_the_shared_source_file_is_well_formed_for_both_the_app_and_the_web(): void
    {
        config(['support.legal_source' => base_path('mobile/lib/legal-content.json')]);

        $legal = resolve(LegalContent::class);
        $this->assertTrue($legal->available(), 'mobile/lib/legal-content.json must exist and be valid JSON.');

        foreach (LegalContent::SLUGS as $slug) {
            $doc = $legal->document($slug);
            $this->assertNotNull($doc, "Missing document {$slug}");
            $this->assertNotSame('', $doc['title']);
            $this->assertNotEmpty($doc['sections']);
            foreach ($doc['sections'] as $section) {
                $this->assertNotSame('', trim($section['heading']));
                $this->assertNotSame('', trim($section['body']));
                $this->assertStringNotContainsString('{{', $section['body'], 'Unreplaced placeholder.');
            }
        }

        // The privacy policy must mention in-app account deletion and the reviews disclosure.
        $privacy = json_encode($legal->document('kebijakan-privasi'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Hapus akun', (string) $privacy);
        $this->assertStringContainsString('ulasan', (string) $privacy);
    }
}
