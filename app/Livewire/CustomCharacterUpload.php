<?php

namespace App\Livewire;

use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\SaveCustomCharacter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The page where a player uploads a pose sheet they generated with an image
 * AI and gets their own fighter from it. Shows the prompt to use, the upload
 * form with the reason when a sheet is refused, and a live preview of the
 * character they have (with a way to remove it).
 */
class CustomCharacterUpload extends Component
{
    use WithFileUploads;

    /**
     * The pose sheet chosen in the form, before it is processed.
     *
     * @var TemporaryUploadedFile|null
     */
    public $sheet = null;

    /**
     * Validation rules for the chosen file. The byte limit is the same one
     * the decoder enforces; this only fails fast, before the file is read.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        $maxKilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);

        return [
            'sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', "max:{$maxKilobytes}"],
        ];
    }

    /**
     * Builds the user's character from the chosen sheet, replacing any earlier
     * one. A sheet that breaks the contract is refused with its reason and
     * leaves the existing character alone.
     *
     * @param  SaveCustomCharacter  $save  builds and stores the character
     * @return void
     */
    public function upload(SaveCustomCharacter $save): void
    {
        $this->validate();

        $user = auth()->user();
        $key = "custom-character-upload:{$user->id}";

        if (! RateLimiter::attempt($key, (int) config('token_slayer.custom_character.uploads_per_hour'), fn (): bool => true, 3600)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            $this->addError('sheet', "Too many uploads. Try again in {$minutes} minutes.");

            return;
        }

        try {
            $save->execute($user, (string) file_get_contents($this->sheet->getRealPath()));
        } catch (InvalidPoseSheetException $exception) {
            $this->addError('sheet', $exception->getMessage());

            return;
        } finally {
            $this->reset('sheet');
        }
    }

    /**
     * Deletes the user's character.
     *
     * @param  RemoveCustomCharacter  $remove  deletes the row and its files
     * @return void
     */
    public function remove(RemoveCustomCharacter $remove): void
    {
        $remove->execute(auth()->user());
    }

    /**
     * Renders the page with the user's current character and the prompt.
     *
     * @return View
     */
    public function render(): View
    {
        $character = auth()->user()->customCharacter;
        $disk = Storage::disk('public');

        $strips = $character === null ? [] : collect($character->animations)
            ->map(fn (array $animation, string $name): array => [
                'name' => $name,
                'url' => $disk->url("custom-characters/{$character->user_id}/{$character->version_hash}/{$name}.png"),
                'frames' => $animation['frames'],
                'rate' => $animation['rate'],
            ])
            ->values()
            ->all();

        return view('livewire.custom-character-upload', [
            'strips' => $strips,
            'prompt' => (string) config('token_slayer.custom_character.prompt'),
        ]);
    }
}
