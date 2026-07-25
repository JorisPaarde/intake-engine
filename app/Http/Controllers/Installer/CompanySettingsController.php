<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Branding\Services\CompanyLogoColorExtractor;
use App\Domains\Branding\Services\CompanyLogoValidator;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class CompanySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('installer.company-settings.edit', [
            'company' => $request->user()?->company()->firstOrFail(),
        ]);
    }

    public function update(
        Request $request,
        CompanyLogoValidator $logoValidator,
        CompanyLogoColorExtractor $colorExtractor,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'max:2048'],
        ]);

        $company = $request->user()?->company()->firstOrFail();
        abort_unless($company instanceof Company, 403);

        $logoFile = $request->file('logo');
        $logoMeta = null;
        $tokens = null;

        if ($logoFile !== null) {
            $logoMeta = $logoValidator->validate($logoFile);
            $tokens = $colorExtractor->extract($logoFile->getRealPath() ?: $logoFile->getPathname());
        }

        if (is_string($validated['primary_color'] ?? null) && $validated['primary_color'] !== '') {
            $tokens = $colorExtractor->tokensFromHex($validated['primary_color']);
        }

        DB::transaction(function () use ($company, $validated, $logoFile, $logoMeta, $tokens): void {
            $oldDisk = $company->logo_disk;
            $oldPath = $company->logo_path;
            $newPath = null;

            if ($logoFile !== null) {
                $disk = (string) config('filesystems.media', 'local');
                $newPath = 'companies/'.$company->uuid.'/branding/logo.'.$logoMeta['extension'];
                Storage::disk($disk)->put($newPath, File::get($logoFile->getRealPath() ?: $logoFile->getPathname()));

                $company->forceFill([
                    'logo_disk' => $disk,
                    'logo_path' => $newPath,
                    'logo_original_filename' => $logoFile->getClientOriginalName(),
                    'logo_mime_type' => $logoMeta['mime'],
                    'logo_size_bytes' => $logoMeta['size'],
                ]);
            }

            $company->name = (string) $validated['name'];

            if ($tokens !== null) {
                $company->primary_color = $tokens['primary'];
                $company->accent_color = $tokens['accent'];
                $company->on_primary_color = $tokens['on_primary'];
            }

            $company->save();

            if ($newPath !== null && is_string($oldDisk) && is_string($oldPath) && $oldPath !== $newPath) {
                Storage::disk($oldDisk)->delete($oldPath);
            }
        });

        return redirect()
            ->route('company.settings.edit')
            ->with('status', 'Bedrijfsinstellingen opgeslagen.');
    }
}
