<native:column class="flex-1 bg-black p-6 gap-3">
    <native:emulator name="err" class="flex-1" />
    <native:text class="text-white text-2xl">Error-channel probe</native:text>
    <native:text class="text-yellow-300 text-xl">throw → {{ $threw }}</native:text>
    <native:text class="text-cyan-300 text-xl">event → {{ $event }}</native:text>
</native:column>
