<?php

namespace App\Livewire\Concerns;

use App\Support\CacheKeys;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * What an editor needs to take a pose sheet from the form: the `sheet` field, its
 * validation rule and reading the chosen file under the player's hourly allowance. The
 * fighter editor and the minion editor take a sheet through the same pipeline, so they
 * share it.
 *
 * It counts the attempt through `ActsAsSheetPanel::withinLimit()`, so a component that
 * uses this trait uses that one too.
 *
 * Method names avoid `upload`, `removeUpload`, `uploadMultiple` and `cancelUpload`: in the
 * browser those are Livewire's own file-upload functions and win over a component method of
 * the same name.
 */
trait UploadsPoseSheet
{
    use WithFileUploads;

    /**
     * The pose sheet chosen in the form, before it is built.
     *
     * @var TemporaryUploadedFile|null
     */
    public $sheet = null;

    /**
     * The validation rule of the `sheet` field. The byte limit is the one the decoder
     * enforces (this only fails fast, before the file is read).
     *
     * @return array<string, array<int, string>>
     */
    protected function sheetRules(): array
    {
        $maxKilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);

        return [
            'sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', "max:{$maxKilobytes}"],
        ];
    }

    /**
     * Validates the chosen sheet, takes one from the player's hourly build allowance and
     * reads it. Null means the player was turned away and told why, under the file box.
     *
     * @return string|null the sheet's bytes, or null when it was refused
     */
    protected function takeSheetBytes(): ?string
    {
        $this->resetErrorBag();
        $this->validateOnly('sheet');

        $user = auth()->user();

        if (! $this->withinLimit(CacheKeys::customCharacterUpload($user->id), (int) config('token_slayer.custom_character.uploads_per_hour'), 3600, 'sheet', 'uploads')) {
            return null;
        }

        return (string) file_get_contents($this->sheet->getRealPath());
    }
}
