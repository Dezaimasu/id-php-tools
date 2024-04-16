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
                $map[$lumpName] = $this->parseMapLump($lumpName, $lump);
            }

            $this->maps[$mapLumpInfo['name']] = $map;
        }
    }

    /**
     * @param string $lumpName
     * @param string $lump
     * @return array[]|null
     */
    private function parseMapLump(string $lumpName, string $lump): ?array{
        $structures = [
            'THINGS' => [
                'x_position'    => 'int16_t',
                'y_position'    => 'int16_t',
                'angle'         => 'int16_t',
                'type'          => 'int16_t',
                'options'       => 'int16_t',
            ],
            'LINEDEFS' => [
                'vertex_start'  => 'int16_t',
                'vertex_end'    => 'int16_t',
                'flags'         => 'int16_t',
                'function'      => 'int16_t',
                'tag'           => 'int16_t',
                'sidedef_right' => 'int16_t',
                'sidedef_left'  => 'int16_t',
            ],
            'SIDEDEFS' => [
                'xoffset'       => 'int16_t',
                'yoffset'       => 'int16_t',
                'uppertexture'  => 'string8',
                'lowertexture'  => 'string8',
                'middletexture' => 'string8',
                'sector_ref'    => 'int16_t',
            ],
        ];

        if (empty($struct = @$structures[$lumpName])) {
        	return null;
        }

        $typesLength = [
            'int16_t' => 2,
            'int32_t' => 4,
            'string8' => 8,
        ];

        $entryLen = 0;
        foreach ($struct as $type) {
            $entryLen += $typesLength[$type];
        }

        $entries = [];
        for ($pos = 0, $lumpLen = strlen($lump); $pos < $lumpLen; $pos += $entryLen) {
            $entry = [];
            $entryStr  = substr($lump, $pos, $entryLen);
            $subPos = 0;
            foreach ($struct as $key => $type) {
                $entry[$key] = self::$type($entryStr, $subPos);
                $subPos += $typesLength[$type];
            }

            $entries[] = $entry;
        }

        return $entries;
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
