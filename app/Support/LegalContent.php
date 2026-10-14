<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Terms and Privacy Policy text, read from mobile/lib/legal-content.json: the same file the
 * mobile app bundles, so the in-app text and the public web page can never disagree.
 *
 * A page is only published (outside local development) when the text is approved and a support
 * contact exists. The file's "draft" flag and any "[TODO" marker both count as unapproved, so a
 * store-facing URL can never show the draft banner or a placeholder.
 */
final class LegalContent
{
    public const array SLUGS = ['syarat-ketentuan', 'kebijakan-privasi'];

    /** @var array{draft: bool, docs: array<string, array{title: string, updated: string, sections: list<array{heading: string, body: string}>}>}|null */
    private ?array $data = null;

    private bool $loaded = false;

    /** The file is there and readable. */
    public function available(): bool
    {
        return $this->data() !== null;
    }

    public function isDraft(): bool
    {
        $data = $this->data();
        if ($data === null) {
            return true;
        }

        return $data['draft'] || str_contains((string) json_encode($data['docs']), '[TODO');
    }

    public function supportEmail(): ?string
    {
        $email = trim((string) config('support.email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** WhatsApp number as digits in international format, without '+'. */
    public function supportWhatsapp(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) config('support.whatsapp')) ?? '';
        if ($digits === '') {
            return null;
        }

        // 0812... (national format) -> 62812...
        $digits = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        return strlen($digits) >= 8 ? $digits : null;
    }

    public function hasSupportContact(): bool
    {
        return $this->supportEmail() !== null || $this->supportWhatsapp() !== null;
    }

    /** Wording for {{contact}}: the email, else the WhatsApp number (same order as the app). */
    public function contact(): string
    {
        $email = $this->supportEmail();
        if ($email !== null) {
            return 'email ke '.$email;
        }

        $whatsapp = $this->supportWhatsapp();

        return $whatsapp !== null ? 'WhatsApp ke +'.$whatsapp : 'tim OceanMall lewat halaman Bantuan';
    }

    /** Whether the public pages may be shown to visitors. */
    public function publishable(): bool
    {
        return $this->available() && ! $this->isDraft() && $this->hasSupportContact();
    }

    /**
     * @return array{title: string, updated: string, sections: list<array{heading: string, body: string}>}|null
     */
    public function document(string $slug): ?array
    {
        $doc = $this->data()['docs'][$slug] ?? null;
        if ($doc === null) {
            return null;
        }

        $contact = $this->contact();
        $doc['sections'] = array_map(
            static fn (array $section): array => [
                'heading' => $section['heading'],
                'body' => str_replace('{{contact}}', $contact, $section['body']),
            ],
            $doc['sections'],
        );

        return $doc;
    }

    /**
     * @return array{draft: bool, docs: array<string, array{title: string, updated: string, sections: list<array{heading: string, body: string}>}>}|null
     */
    private function data(): ?array
    {
        if ($this->loaded) {
            return $this->data;
        }
        $this->loaded = true;

        $path = (string) config('support.legal_source');
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['docs']) || ! is_array($decoded['docs'])) {
            return null;
        }

        $decoded['draft'] = (bool) ($decoded['draft'] ?? true);

        return $this->data = $decoded;
    }
}
