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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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

        $newDisk = null;
        $newPath = null;

        if ($logoFile !== null) {
            $newDisk = (string) config('filesystems.media', 'local');
            $newPath = 'companies/'.$company->uuid.'/branding/'.Str::uuid().'.'.$logoMeta['extension'];
            $stored = Storage::disk($newDisk)->put(
                $newPath,
                File::get($logoFile->getRealPath() ?: $logoFile->getPathname()),
            );

            if (! $stored) {
                throw ValidationException::withMessages([
                    'logo' => 'Het logo kon niet veilig worden opgeslagen. Probeer het opnieuw.',
                ]);
            }

        }

        try {
            [$oldDisk, $oldPath] = DB::transaction(function () use (
                $company,
                $validated,
                $logoFile,
                $logoMeta,
                $tokens,
                $newDisk,
                $newPath,
            ): array {
                $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
                $oldLogo = [$lockedCompany->logo_disk, $lockedCompany->logo_path];

                if ($logoFile !== null) {
                    $lockedCompany->forceFill([
                        'logo_disk' => $newDisk,
                        'logo_path' => $newPath,
                        'logo_original_filename' => $logoFile->getClientOriginalName(),
                        'logo_mime_type' => $logoMeta['mime'],
                        'logo_size_bytes' => $logoMeta['size'],
                    ]);
                }

                $lockedCompany->name = (string) $validated['name'];

                if ($tokens !== null) {
                    $lockedCompany->primary_color = $tokens['primary'];
                    $lockedCompany->accent_color = $tokens['accent'];
                    $lockedCompany->on_primary_color = $tokens['on_primary'];
                }

                $lockedCompany->save();

                return $oldLogo;
            });
        } catch (Throwable $exception) {
            if ($newDisk !== null) {
                Storage::disk($newDisk)->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath !== null && is_string($oldDisk) && is_string($oldPath)) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return redirect()
            ->route('company.settings.edit')
            ->with('status', 'Bedrijfsinstellingen opgeslagen.');
    }
}
