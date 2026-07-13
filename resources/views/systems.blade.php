<native:column class="flex-1 bg-black">
    <native:emulator name="showcase" input-capture="global" class="flex-1" />
    <native:text class="text-white p-2">{{ $status }}</native:text>
    <native:row class="p-3 gap-3 items-center justify-center">
        <native:button label="SNES" @press="playSfc" />
        <native:button label="NES" @press="playFc" />
        <native:button label="Game Boy" @press="playGb" />
        <native:button label="Mega Drive" @press="playMd" />
        <native:button label="Zelda" @press="playZelda" />
    </native:row>
</native:column>
