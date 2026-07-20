<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Config\Config;
use Native\Mobile\Edge\NativeComponent;

/**
 * Shader gate: boots SNES declaratively with the grayscale verification
 * preset in the config, so a screenshot alone proves the librashader chain
 * ran (colors → luma gray). Preset files seed from the bundle like ROMs do.
 */
class ShaderProbeScreen extends NativeComponent
{
    public function render(): View
    {
        return view('shader-probe', [
            'config' => new Config(volume: 80, shader: $this->seedPreset()),
            'rom' => BundledRoms::forSystem('sfc'),
        ]);
    }

    private function seedPreset(): string
    {
        $dir = storage_path('app/shaders');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach (['grayscale.slangp', 'grayscale.slang'] as $file) {
            if (! file_exists("$dir/$file")) {
                copy(base_path("resources/shaders/$file"), "$dir/$file");
            }
        }

        return "$dir/grayscale.slangp";
    }
}
