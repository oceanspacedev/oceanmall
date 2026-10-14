<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\LegalContent;
use Illuminate\Contracts\View\View;

/**
 * Public pages the store listings link to: privacy policy, terms, support and account deletion.
 * Plain Blade, no login and no Inertia, so a reviewer (or a crawler) can open them cold.
 *
 * The legal text and the support page are published only when approved and a support contact is
 * configured (see LegalContent::publishable()); until then they answer 404, except in local
 * development, where they render with a visible draft notice so the pages can still be checked.
 */
final class LegalPageController extends Controller
{
    public function __construct(private readonly LegalContent $legal) {}

    public function show(string $slug): View
    {
        $this->authorizeVisit();

        $doc = $this->legal->document($slug);
        abort_if($doc === null, 404);

        return view('legal.show', [
            'title' => $doc['title'],
            'updated' => $doc['updated'],
            'sections' => $doc['sections'],
            'draft' => $this->legal->isDraft(),
        ]);
    }

    public function support(): View
    {
        $this->authorizeVisit();

        return view('legal.support', [
            'email' => $this->legal->supportEmail(),
            'whatsapp' => $this->legal->supportWhatsapp(),
            'draft' => $this->legal->isDraft(),
        ]);
    }

    /**
     * The account-deletion page Play Console asks for. It describes mechanics that exist in the app
     * and on the web, so it does not wait for the legal text to be approved, only for a contact.
     */
    public function accountDeletion(): View
    {
        abort_unless($this->legal->hasSupportContact() || app()->isLocal(), 404);

        return view('legal.account-deletion', [
            'email' => $this->legal->supportEmail(),
            'whatsapp' => $this->legal->supportWhatsapp(),
        ]);
    }

    private function authorizeVisit(): void
    {
        abort_unless($this->legal->publishable() || app()->isLocal(), 404);
    }
}
