<?php

require_once 'DoomWadParser.php';

class DoomIntermissionConverter {

    /**
     * https://doomwiki.org/wiki/UMAPINFO
     * https://zdoom.org/wiki/Intermission_script
     * https://docs.google.com/document/d/1IVJ4nt5f20_NUrMrr9gl-UDStq1jexo5y4XTL1qd4vs
     */

    private string $wadPath;
    private DoomWadParser $wad;

    private string $outputDir;
    private string $tmpDir;

    private array $data = [
        'mapinfo'       => [],
        'intermissions' => [],
    ];

    private array $savedGraphics = [];
    private array $jsonPieces = [];


    /**
     * @param string $wadPath
     * @param string $outputDir
     * @param bool $saveGraphics
     * @param bool $debug
     */
    private function __construct(string $wadPath, string $outputDir,bool $saveGraphics, bool $debug){
        $this->wadPath = $wadPath;

        @mkdir($this->outputDir = $outputDir);
        if ($debug) {
            @mkdir($this->tmpDir = "$outputDir/_tmp");
        }

        $this->readZmapinfo();
        $this->readIntermissionScripts();
        if ($saveGraphics) {
            $this->saveGraphics();
        }
        $this->buildUmapinfo();
        $this->buildInterlevelLumps();
    }

    /**
     * @param string $wadPath
     * @param string $outputDir
     * @param bool $saveGraphics
     * @param bool $debug       If true, lumps from WAD file will be saved on disk to make subsequent runs faster. Otherwise WAD will be parsed on each run.
     * @return self
     */
    public static function convert(string $wadPath, string $outputDir, bool $saveGraphics = true, bool $debug = false): self{
        return new self($wadPath, $outputDir, $saveGraphics, $debug);
    }

    /**
     * @param string $lumpName
     * @return string|null
     */
    private function getLump(string $lumpName): ?string{
        if (empty($this->tmpDir)) {
        	return $this->getLumpFromWad($lumpName);
        }

        $tmpFilePath = "$this->tmpDir/$lumpName";
        if (!file_exists($tmpFilePath)) {
            if (empty($lump = $this->getLumpFromWad($lumpName))) {
                return null;
            }

            file_put_contents($tmpFilePath, $lump);
            return $lump;
        }

        return file_get_contents($tmpFilePath);
    }

    /**
     * @param string $lumpName
     * @return string|null
     */
    private function getLumpFromWad(string $lumpName): ?string{
        if (empty($this->wad)) {
            $this->wad = DoomWadParser::open($this->wadPath);
        }

        return @$this->wad->lumps['unknown'][$lumpName];
    }

    /**
     * @return void
     */
    private function readZmapinfo(): void{
        $zMapinfo = $this->getLump('MAPINFO'); // TODO: might also be ZMAPINFO lump

        $mapRegex = /** @lang PhpRegExp*/ "/
          [\n\r]MAP\s(?<map>\w{1,8})[^{]*
          [\n\r]+{
          (?<props>[^}]+)
          [\n\r]+}
        /ix";

        preg_match_all($mapRegex, $zMapinfo, $mapMatches);
        foreach ($mapMatches['map'] as $i => $map) {
            $data = [];
            $data['map'] = $map;

            $propsRaw = $mapMatches['props'][$i];

            $propsRegex = /** @lang PhpRegExp*/ "/(?<keys>\w+) = (?<values>\"[^\"]+\"|[^\n\r]+)/m";
            preg_match_all($propsRegex, $propsRaw, $propsMatches);
            $props = [];
            foreach ($propsMatches['keys'] as $j => $key) {
                $value = trim($propsMatches['values'][$j], '"');
                $props[$key] = $value;
            }

            $data['levelpic'] = @$props['titlepatch'];
            if (strpos(@$props['enterpic'], '$') === 0) {
                $data['enteranim'] = ltrim($props['enterpic'], '$');
            }
            if (strpos(@$props['exitpic'], '$') === 0) {
                $data['exitanim'] = ltrim($props['exitpic'], '$');
            }
            // not trying to read "[secret]next" props since they might be inaccurate

            $this->data['mapinfo'][$i] = $data;
        }
    }

    /**
     * @return void
     */
    private function readIntermissionScripts(): void{
        $scripts = array_unique(array_merge(
            array_column($this->data['mapinfo'], 'enteranim'),
            array_column($this->data['mapinfo'], 'exitanim'),
        ));

        sort($scripts);
        foreach ($scripts as $scriptName) {
            $this->readIntermissionScript($scriptName);
        }

    }

    /**
     * @param string $scriptName
     * @return void
     */
    private function readIntermissionScript(string $scriptName): void{
        $script = $this->getLump($scriptName);

        $data = [
            'script_name'=> $scriptName,
            'bg'         => null,
            'splat'      => null,
            'pointers'   => [],
            'spots'      => [],
            'animations' => [],
        ];
        $strings = explode("\r\n", $script);
        for ($i = 0, $len = count($strings); $i < $len; $i++) {
            $str = $strings[$i];

            if (preg_match('/^BACKGROUND (?<bg>\w{1,8})$/i', $str, $matches)) {
                $data['bg'] = strtoupper($matches['bg']);

            } elseif (preg_match('/^SPLAT (?<splat>\w{1,8})$/i', $str, $matches)) {
                $data['splat'] = strtoupper($matches['splat']);

            } elseif (preg_match('/^POINTER (?<p1>\w{1,8}) (?<p2>\w{1,8})$/i', $str, $matches)) {
                $data['pointers'] = [strtoupper($matches['p1']), strtoupper($matches['p2'])];

            } elseif (strtoupper($str) === 'SPOTS' && $strings[$i+1] === '{') {
                $i += 2;
                while (($str = $strings[$i]) !== '}') {
                    [$map, $x, $y] = explode(' ', $str);
                    $data['spots'][$map] = compact('x', 'y');
                    $i++;
                }

            } elseif (preg_match('/^ANIMATION (?<x>\d{1,3}) (?<y>\d{1,3}) (?<speed>\d{1,2})( ONCE)?$/i', $str, $matches) && $strings[$i+1] === '{') {
                $animation['x'] = $matches['x'];
                $animation['y'] = $matches['y'];
                $animation['speed'] = $matches['speed'];
                $animation['patches'] = [];

                $i += 2;
                while (($str = $strings[$i]) !== '}') {
                    $animation['patches'][] = strtoupper($str);
                    $i++;
                }

                $data['animations'][] = $animation;
            }
        }

        $this->data['intermissions'][] = $data;
    }

    /**
     * @return void
     */
    private function saveGraphics(): void{
        foreach ($this->data['intermissions'] as $intermission) {
            $this->savePng($intermission['bg']);
            $this->savePng($intermission['splat']);
            $this->savePng($intermission['pointers'][0]);
            $this->savePng($intermission['pointers'][1]);
            foreach ($intermission['animations'] as $animation) {
                foreach (array_unique($animation['patches']) as $patch) {
                    $this->savePng($patch);
                }
            }
        }
    }

    /**
     * @param string|null $lumpName
     * @return void
     */
    private function savePng(?string $lumpName): void{
        if (
            $lumpName &&
            !in_array($lumpName, $this->savedGraphics, true) &&
            !file_exists("$this->outputDir/$lumpName.png")
        ) {
            $this->wad->savePicture($lumpName, $this->outputDir);
            $this->savedGraphics[] = $lumpName;
        }
    }

    /**
     * @return void
     */
    private function buildUmapinfo(): void{
        $umapinfo = '';

        usort($this->data['mapinfo'], static function($a, $b){
            return $a['map'] <=> $b['map'];
        });
        foreach ($this->data['mapinfo'] as $mapinfo) {
            $umapinfo .= "MAP {$mapinfo['map']}\n";
            $umapinfo .= "{\n";
            $umapinfo .= "  levelpic = \"{$mapinfo['levelpic']}\"\n";
            $umapinfo .= "  enteranim = \"{$mapinfo['enteranim']}\"\n";
            $umapinfo .= "  exitanim = \"{$mapinfo['exitanim']}\"\n";
            $umapinfo .= "}\n\n";
        }

        file_put_contents("$this->outputDir/UMAPINFO.txt", $umapinfo);
    }

    /**
     * @return void
     */
    private function buildInterlevelLumps(): void{
        foreach ($this->data['intermissions'] as $data) {
            $this->buildInterlevelLump($data);
        }
    }

    /**
     * @param array $data
     * @return void
     */
    private function buildInterlevelLump(array $data): void{
        $interlevel = [
            'type'      => 'interlevel',
            'version'   => '0.1.0',
            'metadata'  => [
                'author'        => 'Deil',
                'application'   => 'id-php-tools',
                'timestamp'     => date('c'),
                'comment'       => 'Intermission screen for TODO (ported from GZDoom mod)',
            ],
            'data' => [
                'music'             => 'TODO',
                'backgroundimage'   => $data['bg'],
                'layers'            => [],
            ],
        ];

        if (empty($data['spots']) && empty($data['animations'])) {
        	$interlevel['data']['layers'] = null;
        }

        if (!empty($data['animations'])) {
            $interlevelAnims = [];
            foreach ($data['animations'] as $anim) {
                $duration = round($anim['speed'] / 35, 2);
                $interlevelFrames = [];
                foreach ($anim['patches'] as $patch) {
                    $interlevelFrames[] = $this->addJsonPiece([
                        'image'         => $patch,
                        'type'          => 2,
                        'duration'      => $duration,
                        'maxduration'   => 0,
                    ]);
                }

                $interlevelAnims[] = [
                    'x'          => (int)$anim['x'],
                    'y'          => (int)$anim['y'],
                    'frames'     => $interlevelFrames,
                    'conditions' => null,
                ];
            }

            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelAnims,
                'conditions' => null,
            ];
        }

        if (!empty($data['spots'])) {
            $mapNumbers = array_combine(
                array_column($this->data['mapinfo'], 'map'),
                array_keys($this->data['mapinfo']),
            );

        	$interlevelSplats = [];
        	$interlevelArrows = [];
            foreach ($data['spots'] as $map => $coords) {
                $x = (int)$coords['x'];
                $y = (int)$coords['y'];

                $mapNumber = $mapNumbers[$map];

                $interlevelSplats[] = [
                    'x' => $x,
                    'y' => $y,
                    'frames' => $this->addJsonPiece([[
                        'image'         => $data['splat'],
                        'type'          => 1,
                        'duration'      => 0,
                        'maxduration'   => 0,
                    ]]),
                    'conditions' => $this->addJsonPiece([[
                        'condition' => 3,
                        'param'     => $mapNumber,
                    ]]),
                ];

                // implied pointers width is 60px, same as Doom pointers
                // implied pointer 0 is right aligned, 1 is left aligned, same as Doom pointers
                $arrow = $x > 320 - 60 ? $data['pointers'][1] : $data['pointers'][0];

                $interlevelArrows[] = [
                    'x' => $x,
                    'y' => $y,
                    'frames' => [$this->addJsonPiece([
                        'image'         => $arrow,
                        'type'          => 2,
                        'duration'      => 0.667,
                        'maxduration'   => 0,
                    ]), $this->addJsonPiece([
                        'image'         => 'TNT1A0',
                        'type'          => 2,
                        'duration'      => 0.333,
                        'maxduration'   => 0,
                    ])],
                    'conditions' => $this->addJsonPiece([[
                        'condition' => 2,
                        'param'     => $mapNumber,
                    ]]),
                ];
            }

            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelSplats,
                'conditions' => $this->addJsonPiece([['condition' => 7, 'param' => 0]]),
            ];
            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelArrows,
                'conditions' => $this->addJsonPiece([['condition' => 7, 'param' => 0]]),
            ];
        }

        $this->saveJson($interlevel, $data['script_name']);
    }

    /**
     * @param array $interlevel
     * @param string $scriptName
     * @return void
     */
    private function saveJson(array $interlevel, string $scriptName): void{
        $placeholders = array_map(
            static function($a){
                return "\"%%%$a\"";
            },
            array_keys($this->jsonPieces)
        );
        $values = array_map(
            static function($a){
                return str_replace([',', ':'], [', ', ': '], json_encode($a));
            },
            $this->jsonPieces
        );

        $json = str_replace([...$placeholders, '    '], [...$values, '  '], json_encode($interlevel, JSON_PRETTY_PRINT));

        file_put_contents("$this->outputDir/$scriptName.json", $json);
    }

    /**
     * Each $piece (frame/condition/whatever) will take only 1 string in resulting json instead of ~6.
     *
     * Keys/values inside $piece array should NOT have commas or colons.
     *
     * @param array $piece
     * @return string
     */
    private function addJsonPiece(array $piece): string{
        $this->jsonPieces[] = $piece;

        return '%%%' . array_key_last($this->jsonPieces);
    }

}

DoomIntermissionConverter::convert('D:\Code\_wads\INTMAPEV_GZ.wad', 'D:\Code\_wads\INTMAPEV_GZ');
