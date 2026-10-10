<?php

namespace App\Services\CustomCharacter;

/**
 * Adds a one-pixel dark outline around a sprite's silhouette, like the
 * hand-drawn roster fighters have. The sprite grows by one pixel on each
 * side; only empty pixels that touch the sprite on a side (not a corner)
 * are drawn.
 */
class SpriteOutliner
{
    /**
     * Returns the sprite with its outline.
     *
     * @param  Sprite  $sprite  the sprite to outline
     * @return Sprite a copy two pixels wider and taller
     */
    public function outline(Sprite $sprite): Sprite
    {
        $width = $sprite->width + 2;
        $height = $sprite->height + 2;

        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopy($image, $sprite->image, 1, 1, 0, 0, $sprite->width, $sprite->height);

        $outline = (int) hexdec(ltrim((string) config('token_slayer.custom_character.outline_color'), '#'));
        $padded = new Sprite($width, $height, $image);
        $edge = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (! $padded->isOpaque($x, $y) && $this->touchesSprite($padded, $x, $y)) {
                    $edge[] = [$x, $y];
                }
            }
        }

        foreach ($edge as [$x, $y]) {
            imagesetpixel($image, $x, $y, $outline);
        }

        return $padded;
    }

    /**
     * Whether any of the four side neighbours is drawn.
     *
     * @param  Sprite  $sprite  the padded sprite
     * @param  int  $x  column
     * @param  int  $y  row
     * @return bool
     */
    private function touchesSprite(Sprite $sprite, int $x, int $y): bool
    {
        return $sprite->isOpaque($x - 1, $y)
            || $sprite->isOpaque($x + 1, $y)
            || $sprite->isOpaque($x, $y - 1)
            || $sprite->isOpaque($x, $y + 1);
    }
}
