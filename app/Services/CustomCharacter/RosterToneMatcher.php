<?php

namespace App\Services\CustomCharacter;

/**
 * Pulls an uploaded character's colours toward the roster's muted look by
 * scaling saturation and lightness (hue is kept). Works on the palette
 * colours, so the same colour always maps to the same result and the sprite
 * never gains colours.
 */
class RosterToneMatcher
{
    /**
     * Returns copies of the sprites with their colours toned.
     *
     * @param  array<string, array<int, Sprite>>  $sprites  sprites keyed by row name
     * @return array<string, array<int, Sprite>>
     */
    public function match(array $sprites): array
    {
        $saturation = (float) config('token_slayer.custom_character.tone_saturation');
        $lightness = (float) config('token_slayer.custom_character.tone_lightness');
        $toned = [];

        return array_map(
            fn (array $row): array => array_map(function (Sprite $sprite) use ($saturation, $lightness, &$toned): Sprite {
                return $this->tint($sprite, $saturation, $lightness, $toned);
            }, $row),
            $sprites,
        );
    }

    /**
     * Copies a sprite, toning every drawn pixel.
     *
     * @param  Sprite  $sprite  the sprite to copy
     * @param  float  $saturation  saturation multiplier
     * @param  float  $lightness  lightness multiplier
     * @param  array<int, int>  $toned  colour cache shared across sprites: original => toned
     * @return Sprite
     */
    private function tint(Sprite $sprite, float $saturation, float $lightness, array &$toned): Sprite
    {
        $image = imagecreatetruecolor($sprite->width, $sprite->height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        for ($y = 0; $y < $sprite->height; $y++) {
            for ($x = 0; $x < $sprite->width; $x++) {
                if ($sprite->isOpaque($x, $y)) {
                    $colour = imagecolorat($sprite->image, $x, $y) & 0xFFFFFF;
                    $toned[$colour] ??= $this->tone($colour, $saturation, $lightness);
                    imagesetpixel($image, $x, $y, $toned[$colour]);
                }
            }
        }

        return new Sprite($sprite->width, $sprite->height, $image);
    }

    /**
     * Scales one colour's saturation and lightness in HSL space.
     *
     * @param  int  $rgb  0xRRGGBB colour
     * @param  float  $saturation  saturation multiplier
     * @param  float  $lightness  lightness multiplier
     * @return int the toned 0xRRGGBB colour
     */
    private function tone(int $rgb, float $saturation, float $lightness): int
    {
        $red = (($rgb >> 16) & 0xFF) / 255.0;
        $green = (($rgb >> 8) & 0xFF) / 255.0;
        $blue = ($rgb & 0xFF) / 255.0;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;
        $light = ($max + $min) / 2;

        if ($delta === 0.0) {
            return $this->pack($light * $lightness, $light * $lightness, $light * $lightness);
        }

        $sat = $delta / (1 - abs(2 * $light - 1));
        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta + 6, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        $sat = min(1.0, $sat * $saturation);
        $light = min(1.0, $light * $lightness);
        $chroma = (1 - abs(2 * $light - 1)) * $sat;
        $second = $chroma * (1 - abs(fmod($hue, 2) - 1));
        $offset = $light - $chroma / 2;

        [$r, $g, $b] = match ((int) floor($hue)) {
            0 => [$chroma, $second, 0],
            1 => [$second, $chroma, 0],
            2 => [0, $chroma, $second],
            3 => [0, $second, $chroma],
            4 => [$second, 0, $chroma],
            default => [$chroma, 0, $second],
        };

        return $this->pack($r + $offset, $g + $offset, $b + $offset);
    }

    /**
     * Packs 0..1 channels into 0xRRGGBB.
     *
     * @param  float  $red  red channel
     * @param  float  $green  green channel
     * @param  float  $blue  blue channel
     * @return int
     */
    private function pack(float $red, float $green, float $blue): int
    {
        $byte = fn (float $channel): int => max(0, min(255, (int) round($channel * 255)));

        return ($byte($red) << 16) | ($byte($green) << 8) | $byte($blue);
    }
}
