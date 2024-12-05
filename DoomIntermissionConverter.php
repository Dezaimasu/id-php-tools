<?php

require_once 'DoomWadParser.php';

class DoomIntermissionConverter {

    /**
     * https://doomwiki.org/wiki/UMAPINFO
     * https://zdoom.org/wiki/Intermission_script
     * https://docs.google.com/document/d/1IVJ4nt5f20_NUrMrr9gl-UDStq1jexo5y4XTL1qd4vs
     */

    private string $wadPath;
    private array $wadInfo;

    private DoomWadParser $wad;

    private string $outputDir;
    private string $tmpDir;

    private array $data = [
        'mapinfo'       => [],
        'intermissions' => [],
    ];
    private array $id24Data = [
        'umapinfo'      => '',
        'interlevels'   => [],
        'credits'       => '',
    ];

    private array $savedGraphics = [];
    private array $jsonPieces = [];

    private const EMPTY_PATCH = 'TNT1A0';

    /**
     * @param string $wadPath
     * @param string $outputDir
     * @param array $wadInfo
     * @param bool $saveGraphics
     * @param bool $debug
     */
    private function __construct(string $wadPath, string $outputDir, array $wadInfo, bool $saveGraphics, bool $debug){
        $this->wadPath = $wadPath;
        $this->wadInfo = $wadInfo;

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
        $this->buildInterlevels();
        $this->buildCredits();

        $this->saveLumps();
    }

    /**
     * @param string $wadPath
     * @param string $outputDir
     * @param array $wadInfo    ['title' => 'WAD name', 'author' => 'original mod author', 'music' => 'Intermission music lump', 'secrets' => [map_num => secret_map_num, ..], 'exits' => [map_num => next_map_num, ..]]
     * @param bool $saveGraphics
     * @param bool $debug       If true, lumps from WAD file will be saved on disk to make subsequent runs faster. Otherwise WAD will be parsed on each run.
     * @return self
     */
    public static function convert(string $wadPath, string $outputDir, array $wadInfo, bool $saveGraphics = true, bool $debug = false): self{
        return new self($wadPath, $outputDir, $wadInfo, $saveGraphics, $debug);
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
          [\n\r]MAP[ ](?<map>\w{1,8})[^{]*
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

        $this->data['mapinfo'] = array_combine(
            range(1, count($this->data['mapinfo'])),
            array_values($this->data['mapinfo'])
        );
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
        $data = [
            'script_name'   => $scriptName,
            'bg'            => null,
            'splat'         => null,
            'pointers'      => [],
            'spots'         => [],
            'animations'    => [],
        ];

        $script = strtoupper($this->getLump($scriptName));
        $strings = array_map('trim', explode("\n", $script));

        for ($i = 0, $len = count($strings); $i < $len; $i++) {
            $str = $strings[$i];

            if (preg_match('/^BACKGROUND (?<bg>\w{1,8})$/i', $str, $matches)) {
                $data['bg'] = $matches['bg'];

            } elseif (preg_match('/^SPLAT (?<splat>\w{1,8})$/i', $str, $matches)) {
                $data['splat'] = $matches['splat'];

            } elseif (preg_match('/^POINTER (?<p1>\w{1,8}) (?<p2>\w{1,8})$/i', $str, $matches)) {
                $data['pointers'] = [$matches['p1'], $matches['p2']];

            } elseif ($str === 'SPOTS' && $strings[$i+1] === '{') {
                $i += 2;
                while (($str = $strings[$i]) !== '}') {
                    [$map, $x, $y] = explode(' ', $str);
                    $data['spots'][$map] = compact('x', 'y');
                    $i++;
                }
            } else {
                // IFNOT commands can't be ported to id24 interlevel
                $regex = /** @lang PhpRegExp*/ '/
                  ^(
                    (IFENTERING[ ](?<entering>\w{1,8})[ ])
                    |
                    (IFLEAVING[ ](?<leaving>\w{1,8})[ ])
                    |
                    (IFVISITED[ ](?<visited>\w{1,8})[ ])
                    |
                    (IFTRAVELLING[ ](?<from>\w{1,8})[ ](?<to>\w{1,8})[ ])
                  )?
                  (
                    (PIC[ ](?<pic_x>\d{1,3})[ ](?<pic_y>\d{1,3})[ ](?<patch>\w{1,8}))
                    |
                    (ANIMATION[ ](?<anim_x>\d{1,3})[ ](?<anim_y>\d{1,3})[ ](?<speed>\d{1,2})(?<once>[ ]ONCE)?)
                  )$
                /ix';

                if (!preg_match($regex, $str, $matches)) {
                    continue;
                }

                $animation = [
                    'entering'  => $matches['entering'] ?: $matches['to'], // skipping 'from' because it's impossible to know next map number on tally screen
                    'leaving'   => $matches['leaving'],
                    'visited'   => $matches['visited'],
                    'once'      => false,
                    'speed'     => null,
                    'patches'   => [],
                ];

                if ($matches['pic_x'] === '') { // ANIMATION
                    $animation['x'] = $matches['anim_x'];
                    $animation['y'] = $matches['anim_y'];
                    $animation['once'] = !empty($matches['once']);
                    $animation['speed'] = $matches['speed'];

                    $i += 2;
                    while (($str = $strings[$i]) !== '}') {
                        $animation['patches'][] = $str;
                        $i++;
                    }

                } else { // PIC
                    $animation['x'] = $matches['pic_x'];
                    $animation['y'] = $matches['pic_y'];
                    $animation['patches'][] = $matches['patch'];
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
            $this->savePng($intermission['splat'], true);
            $this->savePng($intermission['pointers'][0], true);
            $this->savePng($intermission['pointers'][1], true);
            foreach ($intermission['animations'] as $animation) {
                foreach (array_unique($animation['patches']) as $patch) {
                    $this->savePng($patch);
                }
            }
        }
    }

    /**
     * @param string|null $lumpName
     * @param bool $potentiallyExternal     lumps such as WISPLAT might not be in $this->wad if they exist in IWAD
     * @return void
     */
    private function savePng(?string $lumpName, bool $potentiallyExternal = false): void{
        if (
            !$lumpName ||
            in_array($lumpName, $this->savedGraphics, true) ||
            file_exists("$this->outputDir/$lumpName.png") ||
            ($potentiallyExternal && empty($this->wad))
        ) {
        	return;
        }

        $this->wad->savePicture($lumpName, $this->outputDir);
        $this->savedGraphics[] = $lumpName;
    }

    /**
     * @return void
     */
    private function buildUmapinfo(): void{
        $umapinfo = '';

        foreach ($this->data['mapinfo'] as $mapNum => $mapinfo) {
            $umapinfo .= "MAP {$mapinfo['map']}\n";
            $umapinfo .= "{\n";

            if (isset($this->wadInfo['exits'][$mapNum])) {
                $nextMap = $this->data['mapinfo'][$this->wadInfo['exits'][$mapNum]]['map'];
                $umapinfo .= "  next = \"{$nextMap}\"\n";
            }
            if (isset($this->wadInfo['secrets'][$mapNum])) {
                $nextSecretMap = $this->data['mapinfo'][$this->wadInfo['secrets'][$mapNum]]['map'];
                $umapinfo .= "  nextsecret = \"{$nextSecretMap}\"\n";
            }

            $umapinfo .= "  levelpic = \"{$mapinfo['levelpic']}\"\n";
            $umapinfo .= "  enteranim = \"{$mapinfo['enteranim']}\"\n";
            $umapinfo .= "  exitanim = \"{$mapinfo['exitanim']}\"\n";
            $umapinfo .= "}\n\n";
        }

        $this->id24Data['umapinfo'] = preg_replace("/\n\n$/", "\n", $umapinfo);
    }

    /**
     * @return void
     */
    private function buildInterlevels(): void{
        foreach ($this->data['intermissions'] as $data) {
            $this->buildInterlevel($data);
        }
    }

    /**
     * @param array $data
     * @return void
     */
    private function buildInterlevel(array $data): void{
        $mapNums = array_combine(
            array_column($this->data['mapinfo'], 'map'),
            array_keys($this->data['mapinfo']),
        );

        $conditionLeaving   = ['condition' => 6, 'param' => 0];
        $conditionEntering  = ['condition' => 7, 'param' => 0];

        $interlevel = [
            'type'      => 'interlevel',
            'version'   => '0.1.0',
            'metadata'  => [
                'author'        => 'Deil',
                'application'   => 'id-php-tools',
                'timestamp'     => date('c'),
                'comment'       => "Intermission screen for {$this->wadInfo['title']}. Ported from GZDoom mod made by {$this->wadInfo['author']}.",
            ],
            'data' => [
                'music'             => $this->wadInfo['music'],
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

                $lastPatch = array_pop($anim['patches']);

                foreach ($anim['patches'] as $patch) {
                    $interlevelFrames[] = $this->addJsonPiece([
                        'image'         => $patch,
                        'type'          => 2,
                        'duration'      => $duration,
                        'maxduration'   => 0,
                    ]);
                }

                if ($anim['once'] || $anim['speed'] === null) {
                    $type = 1;
                    $duration = 0;
                } else {
                    $type = 2;
                }

                $interlevelFrames[] = $this->addJsonPiece([
                    'image'         => $lastPatch,
                    'type'          => $type,
                    'duration'      => $duration,
                    'maxduration'   => 0,
                ]);

                $conditions = null;
                if ($anim['entering']) {
                    $nextMapNum = $mapNums[$anim['entering']];
                    $conditions = [['condition' => 2, 'param' => $nextMapNum], $conditionEntering];
                } elseif ($anim['leaving']) {
                    $mapNum = $mapNums[$anim['leaving']];
                    $conditions = [['condition' => 2, 'param' => $mapNum], $conditionLeaving];
                } elseif ($anim['visited']) {
                    $mapNum = $mapNums[$anim['visited']];
                    $conditions = [['condition' => 3, 'param' => $mapNum]];
                }

                $interlevelAnims[] = [
                    'x'          => (int)$anim['x'],
                    'y'          => (int)$anim['y'],
                    'frames'     => $interlevelFrames,
                    'conditions' => $conditions ? $this->addJsonPiece($conditions) : null,
                ];
            }

            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelAnims,
                'conditions' => null,
            ];
        }

        if (!empty($data['spots'])) {
        	$interlevelSplats = [];
        	$interlevelArrows = [];

            foreach ($data['spots'] as $map => $coords) {
                $x = (int)$coords['x'];
                $y = (int)$coords['y'];

                $mapNum = $mapNums[$map];

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
                        'param'     => $mapNum,
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
                        'image'         => self::EMPTY_PATCH,
                        'type'          => 2,
                        'duration'      => 0.333,
                        'maxduration'   => 0,
                    ])],
                    'conditions' => $this->addJsonPiece([[
                        'condition' => 2,
                        'param'     => $mapNum,
                    ]]),
                ];
            }

            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelSplats,
                'conditions' => $this->addJsonPiece([$conditionEntering]),
            ];
            $interlevel['data']['layers'][] = [
                'anims'      => $interlevelArrows,
                'conditions' => $this->addJsonPiece([$conditionEntering]),
            ];
        }

        $this->id24Data['interlevels'][$data['script_name']] = $interlevel;
    }

    /**
     * @return void
     */
    private function buildCredits(): void{
        $this->id24Data['credits'] = "{$this->wadInfo['author']} - original GZDoom intermission screen mod, including all graphics and animations.";
    }

    /**
     * @return void
     */
    private function saveLumps(): void{
        file_put_contents("$this->outputDir/UMAPINFO.txt", $this->id24Data['umapinfo']);
        file_put_contents("$this->outputDir/CREDITS.txt", $this->id24Data['credits']);

        foreach ($this->id24Data['interlevels'] as $scriptName => $interlevel) {
            $this->saveJson($interlevel, $scriptName);
        }
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

DoomIntermissionConverter::convert('D:\Code\_wads\INTMAPD2_GZ.wad', 'D:\Code\_wads\INTMAPD2_GZ', [
    'title'     => 'Doom II',
    'author'    => 'Oliacym',
    'music'     => 'D_DM2INT',
    'secrets'   => [15 => 31, 31 => 32],
    'exits'     => [31 => 16, 32 => 16],
], true, true);
