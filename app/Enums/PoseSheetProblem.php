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
        };
    }
}
