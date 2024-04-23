<?php

class DoomWadParser {

    /**
     * https://doomwiki.org/wiki/WAD
     * https://www.gamers.org/dhs/helpdocs/dmsp1666.html
     */

    private string $wad;

    public array $header = [];
    public array $directory = [];
    public array $palettes = [];
    public array $colormaps = [];
    public array $patchNames = [];
    public array $textures = [];
    public array $maps = [];
    public array $graphics = [
        'flats'     => [],
        'sprites'   => [],
        'patches'   => [],
        'misc'      => [],
    ];

    private array $markers = [
        'S_START'   => 'S_END',
        'F_START'   => 'F_END',
        'F1_START'  => 'F1_END',
        'F2_START'  => 'F2_END',
        'P_START'   => 'P_END',
        'P1_START'  => 'P1_END',
        'P2_START'  => 'P2_END',
        'P3_START'  => 'P3_END',
    ];

    private array $readLumps = [];

    /**
     * @param string $filepath
     */
    private function __construct(string $filepath){
        $this->wad = file_get_contents($filepath);

        $this->readHeader();
        $this->readDirectory();
        $this->readLumps();
    }

    /**
     * @param string $filepath
     * @return self
     */
    public static function open(string $filepath): self{
        return new self($filepath);
    }

    /**
     * @param string $lumpName
     * @param string $folderPath
     */
    public function savePicture(string $lumpName, string $folderPath): void{
        foreach ($this->graphics as $graphicsCategory) {
            if (isset($graphicsCategory[$lumpName])) {
                $pixelData = $graphicsCategory[$lumpName];
                break;
            }
        }
        if (empty($pixelData)) {
        	return;
        }

        $this->drawPicture($pixelData, "$folderPath/$lumpName.png");
    }

    /**
     *
     */
    private function readHeader(): void{
        $this->header = [
            'identification' => substr($this->wad, 0, 4),
        ] + $this->readStructure(substr($this->wad, 4, 8), [
            'numlumps'      => 'int32_t',
            'infotableofs'  => 'int32_t',
        ])[0];
    }

    /**
     *
     */
    private function readDirectory(): void{
        $dirLen = 16;
        $offset = $this->header['infotableofs'];
        for ($i = 0; $i < $this->header['numlumps']; $i++) {
            $dirStr = substr($this->wad, $offset, $dirLen);
            $this->directory[] = $this->readStructure($dirStr, [
                'filepos'   => 'int32_t',
                'size'      => 'int32_t',
                'name'      => 'string8',
            ])[0];

            $offset += $dirLen;
        }
    }

    /**
     *
     */
    private function readLumps(): void{
        $d1MapName = '/^E[1-4]M[1-9]$/';
        $d2MapName = '/^MAP([0-2][1-9]|3[0-2])$/';
        $texture = '/^TEXTURE[1-2]$/';
        $demo = '/^DEMO\d$/';

        $allStartMarkers = array_keys($this->markers);
        $allEndMarkers = array_values($this->markers);
        $skip = array_merge([
            'THINGS',
            'LINEDEFS',
            'SIDEDEFS',
            'VERTEXES',
            'SEGS',
            'SSECTORS',
            'NODES',
            'SECTORS',
            'REJECT',
            'BLOCKMAP',
        ], $allEndMarkers);

        $currentSequenceEndMarker = null;
        foreach ($this->directory as $lumpIndex => $lumpInfo) {
            $lumpName = $lumpInfo['name'];
            if ($lumpName === $currentSequenceEndMarker) {
                $currentSequenceEndMarker = null;
            }

            if ($currentSequenceEndMarker || @$this->readLumps[$lumpName] || in_array($lumpName, $skip, true)) {
            	continue;
            }

            if ($lumpName === 'PLAYPAL') {
                $this->readPalettes($lumpIndex);
            } elseif ($lumpName === 'COLORMAP') {
                $this->readColormap($lumpIndex);
            } elseif ($lumpName === 'ENDOOM') {
                continue; // TODO
            } elseif ($lumpName === 'PNAMES') {
                $this->readPatchNames($lumpIndex);
            } elseif ($lumpName === 'GENMIDI') {
                continue; // TODO
            } elseif ($lumpName === 'DMXGUS') {
                continue; // TODO
            } elseif (strpos($lumpName, 'DP') === 0) {
                continue; // TODO
            } elseif (strpos($lumpName, 'DS') === 0) {
                continue; // TODO
            } elseif (strpos($lumpName, 'D_') === 0) {
                continue; // TODO
            } elseif (in_array($lumpName, $allStartMarkers, true)) {
                if ($lumpName[0] !== 'P') {
                    $this->readDelimitedLumpSequences($lumpIndex);
                    $currentSequenceEndMarker = $this->markers[$lumpName];
                }
            } elseif (in_array($lumpName, $this->patchNames, true)) {
                $this->readPatch($lumpIndex);
            } elseif (preg_match($d1MapName, $lumpName) || preg_match($d2MapName, $lumpName)) {
                $this->readMap($lumpIndex);
            } elseif (preg_match($demo, $lumpName)) {
                continue; // TODO
            } elseif (preg_match($texture, $lumpName)) {
                $this->readTextures($lumpIndex);
            } else {
                $this->readMiscGraphics($lumpIndex);
            }
        }
    }

    /**
     * @param int $mapLumpIndex
     */
    private function readMap(int $mapLumpIndex): void{
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
            'VERTEXES' => [
                'X_coord' => 'int16_t',
                'Y_coord' => 'int16_t',
            ],
            'SEGS' => [
                'vertex_start'  => 'int16_t',
                'vertex_end'    => 'int16_t',
                'bams'          => 'int16_t',
                'line_num'      => 'int16_t',
                'segside'       => 'int16_t',
                'segoffset'     => 'int16_t',
            ],
            'SSECTORS' => [
                'numsegs'   => 'int16_t',
                'start_seg' => 'int16_t',
            ],
            'NODES' => [
                'x'             => 'int16_t',
                'y'             => 'int16_t',
                'dx'            => 'int16_t',
                'dy'            => 'int16_t',
                'r_boxtop'      => 'int16_t',
                'r_boxbottom'   => 'int16_t',
                'r_boxleft'     => 'int16_t',
                'r_boxright'    => 'int16_t',
                'l_boxtop'      => 'int16_t',
                'l_boxbottom'   => 'int16_t',
                'l_boxleft'     => 'int16_t',
                'l_boxright'    => 'int16_t',
                'r_child'       => 'int16_t',
                'l_child'       => 'int16_t',
            ],
            'SECTORS' => [
                'floorheight'       => 'int16_t',
                'ceilingheight'     => 'int16_t',
                'floorpic'          => 'string8',
                'ceilingpic'        => 'string8',
                'lightlevel'        => 'int16_t',
                'special_sector'    => 'int16_t',
                'tag'               => 'int16_t',
            ],
            'REJECT' => null,
            'BLOCKMAP' => null,
        ];
        $lumpNames = array_keys($structures);

        $map = [];
        foreach ($lumpNames as $lumpNum => $lumpName) {
            $lumpIndex = $mapLumpIndex + $lumpNum + 1;
            $lump = $this->getLumpByIndex($lumpIndex)['lump'];

            if ($lumpName === 'REJECT') {
                $map[$lumpName] = $this->readRejectLump($lump);
            } elseif ($lumpName === 'BLOCKMAP') {
                $map[$lumpName] = $this->readBlockmapLump($lump);
            } else {
                $map[$lumpName] = $this->readStructure($lump, $structures[$lumpName]);
            }
        }

        $mapName = $this->getLumpByIndex($mapLumpIndex)['name'];
        $this->maps[$mapName] = $map;
    }

    /**
     * @param int $startMarkerIndex
     * @return int      end marker index
     */
    private function readDelimitedLumpSequences(int $startMarkerIndex): int{
        $categories = [
            'F' => 'flats',
            'S' => 'sprites',
            'P' => 'patches',
        ];

        $startMarkerName = $this->getLumpByIndex($startMarkerIndex)['name'];
        $category = $categories[$startMarkerName[0]];

        $allStartMarkers = array_keys($this->markers);
        $endMarker = $this->markers[$startMarkerName];
        $lumpName = $startMarkerName;
        $lumpIndex = $startMarkerIndex;

        while ($lumpName !== $endMarker) {
            $lumpIndex++;
            ['lump' => $lump, 'name' => $lumpName] = $this->getLumpByIndex($lumpIndex);

            if ($lumpName === $endMarker) {
            	break;
            }
            if (in_array($lumpName, $allStartMarkers, true)) {
                $lumpIndex = $this->readDelimitedLumpSequences($lumpIndex);
                continue;
            }

            $this->graphics[$category][$lumpName] = $category === 'flats' ?
                $this->readFlatLump($lump) :
                $this->readPictureLump($lump);

            $this->readLumps[$lumpName] = true;
        }

        return $lumpIndex;
    }

    /**
     * @param $lumpIndex
     */
    private function readPatch($lumpIndex): void{
        ['lump' => $lump, 'name' => $lumpName] = $this->getLumpByIndex($lumpIndex);
        $this->graphics['patches'][$lumpName] = $this->readPictureLump($lump);
        $this->readLumps[$lumpName] = true;
    }

    /**
     * @param int $lumpIndex
     */
    private function readPalettes(int $lumpIndex): void{
        $lump = $this->getLumpByIndex($lumpIndex)['lump'];

        $palettes = str_split($lump, 768);
        foreach ($palettes as $paletteStr) {
            $palette = [];
            foreach (str_split($paletteStr, 3) as $colorStr) {
                $palette[] = array_values(unpack('C*', $colorStr));
            }

            $this->palettes[] = $palette;
        }
    }

    /**
     * @param int $lumpIndex
     */
    private function readColormap(int $lumpIndex): void{
        $lump = $this->getLumpByIndex($lumpIndex)['lump'];
        foreach (str_split($lump, 256) as $colormap) {
            $this->colormaps[] = array_map('ord', str_split($colormap));
        }
    }

    /**
     * @param int $lumpIndex
     */
    private function readPatchNames(int $lumpIndex): void{
        $lump = $this->getLumpByIndex($lumpIndex)['lump'];
        $patchesCount = self::int32_t($lump, 0);
        for ($patchNum = 0; $patchNum < $patchesCount; $patchNum++) {
            $this->patchNames[] = self::string8($lump, 4 + (8 * $patchNum));
        }
    }

    /**
     * @param int $lumpIndex
     */
    private function readTextures(int $lumpIndex): void{
        $lump = $this->getLumpByIndex($lumpIndex)['lump'];
        $texturesCount = self::int32_t($lump, 0);
        for ($textureNum = 0; $textureNum < $texturesCount; $textureNum++) {
            $textureOffset = self::int32_t($lump, 4 + (4 * $textureNum));
            $textureDataLength = 22;
            $textureData = $this->readStructure(substr($lump, $textureOffset, $textureDataLength), [
                'name'              => 'string8',
                'masked'            => 'int32_t',
                'width'             => 'int16_t',
                'height'            => 'int16_t',
                'columndirectory'   => 'int32_t',
                'patchcount'        => 'int16_t',
            ])[0];
            $textureData['patches'] = $this->readStructure(substr($lump, $textureOffset + $textureDataLength, $textureData['patchcount'] * 10), [
                'originx'   => 'int16_t',
                'originy'   => 'int16_t',
                'patch'     => 'int16_t',
                'stepdir'   => 'int16_t',
                'colormap'  => 'int16_t',
            ]);
            $this->textures[] = $textureData;
        }
    }

    /**
     * @param string $lump
     * @param array $structure
     * @return array[]|null
     */
    private function readStructure(string $lump, array $structure): ?array{
        $typesLength = [
            'int16_t'   => 2,
            'uint16_t'  => 2,
            'int32_t'   => 4,
            'string8'   => 8,
        ];

        $entryLen = 0;
        foreach ($structure as $type) {
            $entryLen += $typesLength[$type];
        }

        $entries = [];
        for ($offset = 0, $lumpLen = strlen($lump); $offset < $lumpLen; $offset += $entryLen) {
            $entry = [];
            $entryStr  = substr($lump, $offset, $entryLen);
            $subOffset = 0;
            foreach ($structure as $key => $type) {
                $entry[$key] = self::$type($entryStr, $subOffset);
                $subOffset += $typesLength[$type];
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param string $lump
     * @return array
     */
    private function readRejectLump(string $lump): array{
        return str_split(bin2hex($lump), 2);
    }

    /**
     * @param string $lump
     * @return array
     */
    private function readBlockmapLump(string $lump): array{
        $blockmap = $this->readStructure(substr($lump, 0, 8), [
            'xorigin' => 'int16_t',
            'yorigin' => 'int16_t',
            'xblocks' => 'int16_t',
            'yblocks' => 'int16_t',
        ])[0];

        $blocksCount = $blockmap['xblocks'] * $blockmap['yblocks'];

        $lumpLen = strlen($lump);
        $lumpWithoutHeader = substr($lump, 8);

        $blockmap['listoffsets'] = [];
        for ($blockIndex = 0; $blockIndex < $blocksCount; $blockIndex++) {
            $blockmap['listoffsets'][] = self::uint16_t($lumpWithoutHeader, $blockIndex * 2);
        }

        $blockmap['blocklists'] = [];
        foreach ($blockmap['listoffsets'] as $blockOffset) {
            $blocklist = [];
            $offset = $blockOffset * 2;
            do {
                $linedef = self::int16_t($lump, $offset);
                $blocklist[] = $linedef;
                $offset += 2;
            } while ($linedef !== -1 && $offset < $lumpLen);

            $blockmap['blocklists'][] = $blocklist;
        }

        return $blockmap;
    }

    /**
     * @param int $lumpIndex
     * @return void
     */
    private function readMiscGraphics(int $lumpIndex): void{
        ['lump' => $lump, 'name' => $lumpName] = $this->getLumpByIndex($lumpIndex);
        $this->graphics['misc'][$lumpName] = $this->readPictureLump($lump);
        $this->readLumps[$lumpName] = true;
    }

    /**
     * @param string $lump
     * @return array
     */
    private function readFlatLump(string $lump): array{
        $flatSize = 64;
        $picture = [
            'width'         => $flatSize,
            'height'        => $flatSize,
            'left_offset'   => 0,
            'top_offset'    => 0,
            'posts'         => [],
        ];

        $rowStart = 0;
        foreach (str_split($lump, $flatSize) as $rowNum => $rowBytes) {
            foreach (str_split($rowBytes) as $colNum => $byte) {
                $postId = "$colNum-$rowStart";
                if (!isset($picture['posts'][$postId])) {
                    $picture['posts'][$postId] = [
                        'column'    => $colNum,
                        'rowstart'  => $rowStart,
                        'pixels'    => [],
                    ];
                }

                $picture['posts'][$postId]['pixels'][$rowNum] = ord($byte);
            }
        }

        $picture['posts'] = array_values($picture['posts']);

        return $picture;
    }

    /**
     * @param string $lump
     * @return array
     */
    private function readPictureLump(string $lump): array{
        $picture = $this->readStructure(substr($lump, 0, 8), [
            'width'         => 'int16_t',
            'height'        => 'int16_t',
            'left_offset'   => 'int16_t',
            'top_offset'    => 'int16_t',
        ])[0];

        $picture['posts'] = [];
        for ($colNum = 0; $colNum < $picture['width']; $colNum++) {
            $colOffset = self::int32_t($lump, 8 + $colNum * 4);

            $postId = null;
            $postStart = 0;
            $postEnd = null;
            $byteNum = 0;

            while (($byte = ord($lump[$colOffset + $byteNum])) !== 255) {
                if ($byteNum === $postStart) {
                    $rowStart = $byte;
                    $postId = "$colNum-$rowStart";

                    $picture['posts'][$postId] = [
                        'column'    => $colNum,
                        'rowstart'  => $rowStart,
                        'pixels'    => [],
                    ];
                } elseif ($byteNum === $postStart + 1) {
                    $pixelsCount = $byte;
                    $postEnd = $postStart + $pixelsCount + 3;
                } elseif ($byteNum >= $postStart + 3 && $byteNum < $postEnd) {
                    $picture['posts'][$postId]['pixels'][] = $byte;
                } elseif ($byteNum === $postEnd) {
                    $postStart = $byteNum + 1;
                }

                $byteNum++;
            }
        }

        $picture['posts'] = array_values($picture['posts']);

        return $picture;
    }

    /**
     * @param array $pixelData
     * @param string $filepath
     */
    private function drawPicture(array $pixelData, string $filepath): void{
        $defaultPalette = $this->palettes[0];

        $pixels = [];
        foreach ($pixelData['posts'] as $postData) {
            foreach ($postData['pixels'] as $pixelNum => $pixelColor) {
                $xy = $postData['column'] . '-' . ($postData['rowstart'] + $pixelNum);
                $pixels[$xy] = $defaultPalette[$pixelColor];
            }
        }

        $gd = imagecreatetruecolor($pixelData['width'], $pixelData['height']);
        imagealphablending($gd, false);
        $transparentColor = imagecolorallocatealpha($gd, 0, 255, 255, 127);
        imagecolortransparent($gd, $transparentColor);
        imageresolution($gd, 72);

        for ($x = 0; $x < $pixelData['width']; $x++) {
        	for ($y = 0; $y < $pixelData['height']; $y++) {
                if (isset($pixels["$x-$y"])) {
                    [$red, $green, $blue] = $pixels["$x-$y"];
                    $color = imagecolorallocatealpha($gd, $red, $green, $blue, 0);
                } else {
                    $color = $transparentColor;
                }

                imagesetpixel($gd, $x, $y, $color);
            }
        }

//        imagetruecolortopalette($gd, false, 255); // GD ruins the 8-bit palette, using pngquant instead

        $tmpFilepath = "$filepath.tmp";
        imagepng($gd, $tmpFilepath);
        shell_exec("pngquant 256 $tmpFilepath --output $filepath");
    }

    /**
     * @param int $lumpIndex
     * @return string[]
     */
    private function getLumpByIndex(int $lumpIndex): array{
        $lumpInfo = $this->directory[$lumpIndex];
        $lump = substr($this->wad, $lumpInfo['filepos'], $lumpInfo['size']);

        return [
            'name' => $lumpInfo['name'],
            'lump' => $lump,
        ];
    }

    /**
     * @param string $str
     * @param int $offset
     * @return int
     */
    private static function int16_t(string $str, int $offset): int{
        return unpack('s', substr($str, $offset, 2))[1]; // length 2 is optional
    }

    /**
     * @param string $str
     * @param int $offset
     * @return int
     */
    private static function uint16_t(string $str, int $offset): int{
        return unpack('S', substr($str, $offset, 2))[1]; // length 2 is optional
    }

    /**
     * @param string $str
     * @param int $offset
     * @return int
     */
    private static function int32_t(string $str, int $offset): int{
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
//$wad->savePicture('FLOOR0_1', 'E:\Doom\IWADs');
