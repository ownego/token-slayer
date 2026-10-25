<?php

namespace App\Services\CustomCharacter;

use GdImage;

/**
 * Lays outlined sprites into the game's fighter strips: one horizontal strip
 * per animation, every frame 100 x 100 with the feet on the roster's foot line
 * and the body centred. Every animation plays the poses drawn on the sheet in
 * order, each held for a beat (two frames for idle, walk and attack, one for death),
 * so the motion on the sheet is the motion in the game.
 */
class SpriteStripBuilder
{
    /**
     * Side of one square frame, fixed by the fighter atlas.
     *
     * @var int
     */
    public const int FRAME = 100;

    /**
     * Row of the lowest ink pixel in a standing frame, matching the roster fighters.
     *
     * @var int
     */
    public const int FOOT_ROW = 58;

    /**
     * Share of the sprite's height, from the bottom, that decides where it is centred.
     *
     * @var float
     */
    private const float ANCHOR_BAND = 0.35;

    /**
     * Playback rate in frames per second for each animation.
     *
     * @var array<string, int>
     */
    private const array RATES = ['idle' => 8, 'walk' => 16, 'attack' => 12, 'death' => 6];

    /**
     * Builds one strip per animation.
     *
     * @param  array<string, array<int, Sprite>>  $sprites  outlined sprites keyed by row name
     * @return array<string, array{image: GdImage, frames: int, rate: int}> strips keyed by animation
     */
    public function build(array $sprites): array
    {
        $strips = [];

        foreach (self::RATES as $name => $rate) {
            $sequence = $this->sequence($name, count($sprites[$name]));
            $strip = $this->blankStrip(count($sequence));

            foreach ($sequence as $frame => [$pose, $lift]) {
                $this->placeFrame($strip, $frame, $sprites[$name][$pose], $lift);
            }

            $strips[$name] = ['image' => $strip, 'frames' => count($sequence), 'rate' => $rate];
        }

        return $strips;
    }

    /**
     * The frame order of one animation.
     *
     * @param  string  $name  animation name
     * @param  int  $poseCount  poses available in that row
     * @return array<int, array{0: int, 1: int}> [pose index, vertical offset in px] per frame
     */
    private function sequence(string $name, int $poseCount): array
    {
        return match ($name) {
            'idle' => array_map(fn (int $frame): array => [intdiv($frame, 2), 0], range(0, $poseCount * 2 - 1)),
            'walk' => array_map(fn (int $frame): array => [intdiv($frame, 2), $frame % 2 === 1 ? -1 : 0], range(0, 7)),
            'attack' => array_map(fn (int $frame): array => [intdiv($frame, 2), 0], range(0, 5)),
            'death' => array_map(fn (int $frame): array => [$frame, 0], range(0, 2)),
        };
    }

    /**
     * A transparent strip wide enough for the frames.
     *
     * @param  int  $frames  number of frames
     * @return GdImage
     */
    private function blankStrip(int $frames): GdImage
    {
        $strip = imagecreatetruecolor($frames * self::FRAME, self::FRAME);
        imagealphablending($strip, false);
        imagesavealpha($strip, true);
        imagefill($strip, 0, 0, imagecolorallocatealpha($strip, 0, 0, 0, 127));

        return $strip;
    }

    /**
     * Copies a sprite into its frame, feet on the foot row, centred by the lower body.
     *
     * @param  GdImage  $strip  the strip being built
     * @param  int  $frame  frame index within the strip
     * @param  Sprite  $sprite  the pose to place
     * @param  int  $lift  pixels to shift vertically (negative is up)
     * @return void
     */
    private function placeFrame(GdImage $strip, int $frame, Sprite $sprite, int $lift): void
    {
        $sprite = $this->fitted($sprite);
        $left = $frame * self::FRAME + intdiv(self::FRAME, 2) - $this->anchorColumn($sprite);
        $top = self::FOOT_ROW - $sprite->height + 1 + $lift;

        imagecopy($strip, $sprite->image, $left, $top, 0, 0, $sprite->width, $sprite->height);
    }

    /**
     * The sprite shrunk, if it needs it, to what a frame can hold: a little inside
     * the frame's width, and the rows between its top edge and the foot line (one
     * spare for the idle bob). A wide attack pose or a tall raised weapon is made
     * smaller instead of bleeding into the next frame or being cropped at the top.
     *
     * @param  Sprite  $sprite  the pose
     * @return Sprite the same sprite when it fits, a shrunk copy when it does not
     */
    private function fitted(Sprite $sprite): Sprite
    {
        $maxWidth = self::FRAME - 2;
        $maxHeight = self::FOOT_ROW;

        if ($sprite->width <= $maxWidth && $sprite->height <= $maxHeight) {
            return $sprite;
        }

        $scale = min($maxWidth / $sprite->width, $maxHeight / $sprite->height);
        $width = max(1, (int) round($sprite->width * $scale));
        $height = max(1, (int) round($sprite->height * $scale));
        $image = imagescale($sprite->image, $width, $height, IMG_NEAREST_NEIGHBOUR);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return new Sprite($width, $height, $image);
    }

    /**
     * Column that should sit at the frame's centre: the mean of the drawn
     * pixels in the sprite's lower band, so a raised sword or a trailing cape
     * does not drag the feet off-centre.
     *
     * @param  Sprite  $sprite  the pose
     * @return int column inside the sprite
     */
    private function anchorColumn(Sprite $sprite): int
    {
        $sum = 0;
        $count = 0;

        for ($y = (int) floor($sprite->height * (1 - self::ANCHOR_BAND)); $y < $sprite->height; $y++) {
            for ($x = 0; $x < $sprite->width; $x++) {
                if ($sprite->isOpaque($x, $y)) {
                    $sum += $x;
                    $count++;
                }
            }
        }

        return $count === 0 ? intdiv($sprite->width, 2) : (int) round($sum / $count);
    }
}
