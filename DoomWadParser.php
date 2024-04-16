<?php

class DoomWadParser {

    /**
     * https://doomwiki.org/wiki/WAD
     * https://www.gamers.org/dhs/helpdocs/dmsp1666.html
     */

    private string $wad;

    public array $header = [];
    public array $directory = [];
    public array $maps = [];

    /**
     * @param string $filepath
     */
    private function __construct(string $filepath){
        $this->wad = file_get_contents($filepath);

        $this->getHeader();
        $this->getDirectory();
        $this->getMaps();
    }

    /**
     * @param string $filepath
     * @return self
     */
    public static function open(string $filepath): self{
        return new self($filepath);
    }

    /**
     *
     */
    private function getHeader(): void{
        $this->header = [
            'identification'    => substr($this->wad, 0, 4),
            'numlumps'          => self::int32_t($this->wad, 4),
            'infotableofs'      => self::int32_t($this->wad, 8),
        ];
    }

    /**
     *
     */
    private function getDirectory(): void{
        $dirLen = 16;
        $pos = $this->header['infotableofs'];
        for ($i = 0; $i < $this->header['numlumps']; $i++) {
            $dirStr = substr($this->wad, $pos, $dirLen);
            $this->directory[] = [
                'filepos'   => self::int32_t($dirStr, 0),
                'size'      => self::int32_t($dirStr, 4),
                'name'      => self::string8($dirStr, 8),
            ];

            $pos += $dirLen;
        }
    }

    private function getMaps(): void{
        $d1MapName = '/^E[1-4]M[1-9]$/';
        $d2MapName = '/^MAP([0-2][1-9]|3[0-2])$/';

        foreach ($this->directory as $mapIndex => $mapLumpInfo) {
            if (!preg_match($d1MapName, $mapLumpInfo['name']) && !preg_match($d2MapName, $mapLumpInfo['name'])) {
                continue;
            }

            $map = [];
            $lumpNames = ['THINGS', 'LINEDEFS', 'SIDEDEFS', 'VERTEXES', 'SEGS', 'SSECTORS', 'NODES', 'SECTORS', 'REJECT', 'BLOCKMAP'];
            foreach ($lumpNames as $lumpNameIndex => $lumpName) {
                $lumpIndex = $mapIndex + $lumpNameIndex + 1;
                $lumpEntry = $this->directory[$lumpIndex];
                $lump = substr($this->wad, $lumpEntry['filepos'], $lumpEntry['size']);
                $map[$lumpName] = $this->parseLump($lumpName, $lump);
            }

            $this->maps[$mapLumpInfo['name']] = $map;
        }
    }

    /**
     * @param string $lumpName
     * @param string $lump
     * @return array|null
     */
    private function parseLump(string $lumpName, string $lump): ?array{
    	switch ($lumpName) {
    		case 'THINGS'   : return $this->parseThings($lump);
    		case 'LINEDEFS' : return $this->parseLinedefs($lump);
    		case 'SIDEDEFS' : return $this->parseSidedefs($lump);
            default         : return null;
    	}
    }

    /**
     * @param string $lump
     * @return array
     */
    private function parseThings(string $lump): array{
        $things = [];
        $lumpLen = strlen($lump);
        $entryLen = 10;
        for ($pos = 0; $pos < $lumpLen; $pos += $entryLen) {
            $thing = substr($lump, $pos, $entryLen);
            $things[] = [
                'x_position'    => self::int16_t($thing, 0),
                'y_position'    => self::int16_t($thing, 2),
                'angle'         => self::int16_t($thing, 4),
                'type'          => self::int16_t($thing, 6),
                'options'       => self::int16_t($thing, 8),
            ];
        }

        return $things;
    }

    /**
     * @param string $lump
     * @return array
     */
    private function parseLinedefs(string $lump): array{
        $linedefs = [];
        $lumpLen = strlen($lump);
        $entryLen = 14;
        for ($pos = 0; $pos < $lumpLen; $pos += $entryLen) {
            $thing = substr($lump, $pos, $entryLen);
            $linedefs[] = [
                'vertex_start'  => self::int16_t($thing, 0),
                'vertex_end'    => self::int16_t($thing, 2),
                'flags'         => self::int16_t($thing, 4),
                'function'      => self::int16_t($thing, 6),
                'tag'           => self::int16_t($thing, 8),
                'sidedef_right' => self::int16_t($thing, 10),
                'sidedef_left'  => self::int16_t($thing, 12),
            ];
        }

        return $linedefs;
    }

    /**
     * @param string $lump
     * @return array
     */
    private function parseSidedefs(string $lump): array{
        $sidedefs = [];
        $lumpLen = strlen($lump);
        $entryLen = 30;
        for ($pos = 0; $pos < $lumpLen; $pos += $entryLen) {
            $thing = substr($lump, $pos, $entryLen);
            $sidedefs[] = [
                'xoffset'       => self::int16_t($thing, 0),
                'yoffset'       => self::int16_t($thing, 2),
                'uppertexture'  => self::string8($thing, 4),
                'lowertexture'  => self::string8($thing, 12),
                'middletexture' => self::string8($thing, 20),
                'sector_ref'    => self::int16_t($thing, 28),
            ];
        }

        return $sidedefs;
    }

    /**
     * @param string $str
     * @param int $offset
     * @return mixed
     */
    private static function int16_t(string $str, int $offset){
        return unpack('s', substr($str, $offset, 2))[1]; // length 2 is optional
    }

    /**
     * @param string $str
     * @param int $offset
     * @return mixed
     */
    private static function int32_t(string $str, int $offset){
        return unpack('l', substr($str, $offset, 4))[1]; // length 4 is optional
    }

    /**
     * @param string $str
     * @param int $offset
     * @return string
     */
    private static function string8(string $str, int $offset): string{
    	return rtrim(substr($str, $offset, 8));
    }

}

$wad = DoomWadParser::open('E:\Doom\IWADs\DOOM.WAD');
