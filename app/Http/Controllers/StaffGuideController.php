<?php

namespace App\Http\Controllers;

use App\Support\Guides;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Guides internes : celui de l'équipe (admin et super admin) et celui du super admin. Ils décrivent des procédures
 * d'exploitation : ils ne sont jamais publics, et le second est réservé au super admin.
 */
class StaffGuideController extends Controller
{
    public function show(Request $request, string $guide): View
    {
        $this->authorizeGuide($request, $guide);

        return view('staff.guide', [
            'key' => $guide,
            'meta' => Guides::all()[$guide],
            'guide' => Guides::render($guide),
            'hasPdf' => is_file(Guides::pdfPath($guide)),
        ]);
    }

    public function pdf(Request $request, string $guide): BinaryFileResponse
    {
        $this->authorizeGuide($request, $guide);
        abort_unless(is_file(Guides::pdfPath($guide)), 404);

        return response()->file(Guides::pdfPath($guide), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.Guides::all()[$guide]['pdf'].'"']);
    }

    private function authorizeGuide(Request $request, string $guide): void
    {
        abort_unless(in_array($guide, ['admin', 'super-admin'], true), 404);
        abort_if($guide === 'super-admin' && ! $request->user()->isSuperAdmin(), 403, 'Ce guide est réservé au super admin.');
    }
}
