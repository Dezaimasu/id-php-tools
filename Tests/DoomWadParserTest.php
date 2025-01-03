<?php

namespace Tests;

use Core\DoomWadParser;

class DoomWadParserTest extends Test{

    /**
     * @return void
     */
    public function test(): void{
        $this->testOpen('DOOM.WAD');

        $this->testOpen('PLUTONIA.WAD');
    }

    /**
     * @param string $wadFile
     * @return void
     */
    private function testOpen(string $wadFile): void{
        $wad = DoomWadParser::open("$this->testDir\\$wadFile", false);

        $this->checkResults($wadFile, [
            'header'    => $wad->header,
            'directory' => $wad->directory,
            'lumps'     => $wad->lumps,
        ]);
    }
}
