<?php

namespace App\Enums;

/**
 * Why an uploaded custom-character pose sheet was rejected. String-backed so
 * the code can travel to the UI and be matched to a translated message.
 */
enum PoseSheetProblem: string
{
    /**
     * The bytes are not a decodable image.
     */
    case NotAnImage = 'not_an_image';

    /**
     * A real image, but not PNG, JPEG or WebP.
     */
    case UnsupportedType = 'unsupported_type';

    /**
     * The file is above the byte limit.
     */
    case FileTooLarge = 'file_too_large';

    /**
     * A side is below the minimum needed to hold the poses.
     */
    case DimensionsTooSmall = 'dimensions_too_small';

    /**
     * A side or the total pixel count is above the limit.
     */
    case DimensionsTooLarge = 'dimensions_too_large';

    /**
     * The background is neither solid magenta nor transparent.
     */
    case BackgroundNotSupported = 'background_not_supported';

    /**
     * The sheet does not have the required four rows of poses.
     */
    case WrongRowCount = 'wrong_row_count';

    /**
     * A row has fewer or more poses than its animation allows.
     */
    case WrongPoseCount = 'wrong_pose_count';

    /**
     * A pose touches the sheet's edge, so it was probably cropped.
     */
    case PoseTouchesEdge = 'pose_touches_edge';

    /**
     * An idle or walk pose is much taller or shorter than the others.
     */
    case PoseSizeMismatch = 'pose_size_mismatch';

    /**
     * The idle and walk poses are drawn smaller than the sprite they are shrunk to.
     */
    case PosesTooSmall = 'poses_too_small';

    /**
     * Human-readable explanation shown to the uploader.
     *
     * @return string
     */
    public function message(): string
    {
        return match ($this) {
            self::NotAnImage => 'The file is not an image.',
            self::UnsupportedType => 'Only PNG, JPEG or WebP sheets are accepted.',
            self::FileTooLarge => 'The file is too large.',
            self::DimensionsTooSmall => 'The sheet is too small to hold the poses.',
            self::DimensionsTooLarge => 'The sheet is too large.',
            self::BackgroundNotSupported => 'The background must be solid magenta (#FF00FF) or transparent.',
            self::WrongRowCount => 'The sheet must have exactly four rows of poses.',
            self::WrongPoseCount => 'A row has the wrong number of poses.',
            self::PoseTouchesEdge => 'A pose touches the edge of the sheet and looks cropped.',
            self::PoseSizeMismatch => 'The idle and walk poses are not drawn at the same size.',
            self::PosesTooSmall => 'The characters are drawn too small. Ask for a bigger sheet, or for the characters to fill their cells.',
        };
    }

    /**
     * Tells the player which change could make the rejected sheet usable.
     *
     * @return string
     */
    public function hint(): string
    {
        return match ($this) {
            self::NotAnImage, self::UnsupportedType => 'Export the sheet as PNG, JPEG or WebP.',
            self::FileTooLarge => 'Keep the file under '.intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024 * 1024).' MB.',
            self::DimensionsTooSmall => 'Make each side at least '.config('token_slayer.custom_character.min_side').' pixels.',
            self::DimensionsTooLarge => 'Keep each side at most '.config('token_slayer.custom_character.max_side').' pixels.',
            self::BackgroundNotSupported => 'Ask the image AI for one flat magenta (#FF00FF) background, or export with transparency.',
            self::WrongRowCount => 'Leave a clear empty gap between the four rows and keep every pose inside its own row.',
            self::WrongPoseCount => 'Idle takes 2 to 4 poses, walk 4, attack 3 and death 3. Count the poses in each row.',
            self::PoseTouchesEdge => 'Leave a margin around every pose so nothing is cut at the sheet edge.',
            self::PoseSizeMismatch => 'Draw the idle and walk poses at the same size.',
            self::PosesTooSmall => 'Ask for a bigger sheet or for the characters to fill their cells.',
        };
    }
}
