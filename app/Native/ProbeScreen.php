<?php

namespace App\Native;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Render probe: no emulator element, maximum-visibility stock components.
 * Exists to answer one question — do text/button/background nodes draw at
 * all on this runtime — without a human in front of the device.
 */
class ProbeScreen extends NativeComponent
{
    public function render(): View
    {
        return view('probe');
    }
}
