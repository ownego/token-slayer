<?php

namespace App\Http\Controllers;

use App\Services\CustomCharacter\CharacterPromptBuilder;
use Illuminate\Contracts\View\View;

/**
 * The /character page: the prompt guide (pick a character type, copy its costume
 * prompt into an image AI) and a link to the fighter sheet's Character tab, where the
 * sheet it makes is uploaded, previewed and managed.
 */
class CustomCharacterController extends Controller
{
    /**
     * Shows the page.
     *
     * @param  CharacterPromptBuilder  $prompts  builds one prompt per character type
     * @return View
     */
    public function __invoke(CharacterPromptBuilder $prompts): View
    {
        return view('custom-character', [
            'types' => collect((array) config('token_slayer.custom_character.types'))->map(fn (array $type): string => $type['label'])->all(),
            'prompts' => $prompts->execute(),
            'thumbs' => asset('assets/custom-character/types'),
        ]);
    }
}
