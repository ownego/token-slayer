<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Builds a user's character from an uploaded pose sheet and makes it theirs,
 * replacing any earlier one. The sheet is fully built before anything is
 * written, so a rejected upload leaves the existing character untouched.
 */
class SaveCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterBuilder  $builder  turns the sheet into strips
     * @param  CustomCharacterStore  $store  writes the strips to disk
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterBuilder $builder,
        private readonly CustomCharacterStore $store,
    ) {}

    /**
     * Saves the user's character from the uploaded sheet.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @return CustomCharacter the saved character
     *
     * @throws InvalidPoseSheetException when the sheet does not meet the pose sheet contract
     */
    public function execute(User $user, string $bytes): CustomCharacter
    {
        $saved = $this->store->put($user->id, $this->builder->execute($bytes));

        return CustomCharacter::updateOrCreate(
            ['user_id' => $user->id],
            ['version_hash' => $saved['hash'], 'animations' => $saved['animations']],
        );
    }
}
