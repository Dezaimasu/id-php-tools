<?php

require_once 'DoomWadParser.php';

class DoomIntermissionConverter {

    /**
     * https://doomwiki.org/wiki/UMAPINFO
     * https://zdoom.org/wiki/Intermission_script
     * https://docs.google.com/document/d/1IVJ4nt5f20_NUrMrr9gl-UDStq1jexo5y4XTL1qd4vs
     */

    private string $wadName;
    private DoomWadParser $wad;
    private array $data = [
        'mapinfo'       => [],
        'intermissions' => [],
    ];

    private array $savedGraphics = [];
    private string $tmpDir = 'D:\Code\_tmp';

    /**
     * @param string $wadPath
     */
    private function __construct(string $wadPath){
        $this->wadName = pathinfo($wadPath)['filename'];
        $this->wad = DoomWadParser::open($wadPath);

        $this->readMapinfo();
        $this->readIntermissionScripts();
        $this->saveGraphics();
    }

    /**
     * @param string $wadPath
     * @return self
     */
    public static function convert(string $wadPath): self{
        return new self($wadPath);
    }

    /**
     * @param string $lumpName
     * @return string|null
     */
    private function getLump(string $lumpName): ?string{
        $tmpFilePath = "$this->tmpDir/$lumpName";

        if (!file_exists($tmpFilePath)) {
            if (empty($lump = $this->wad->lumps['unknown'][$lumpName])) {
                return null;
            }

            file_put_contents($tmpFilePath, $lump);
        }

        return file_get_contents($tmpFilePath);
    }

    /**
     * @return void
     */
    private function readMapinfo(): void{
        $gzMapinfo = $this->getLump('MAPINFO'); // ZMAPINFO

        $mapRegex = /** @lang PhpRegExp*/ "/
          [\n\r]MAP\s(?<map>\w{1,8})[^{]*
          [\n\r]+{
          (?<props>[^}]+)
          [\n\r]+}
        /ix";

        preg_match_all($mapRegex, $gzMapinfo, $mapMatches);
        foreach ($mapMatches['map'] as $i => $map) {
            $data = [];
            $data['map'] = $map;

            $propsRaw = $mapMatches['props'][$i];

            $propsRegex = /** @lang PhpRegExp*/ "/(?<keys>\w+) = (?<values>[^\n\r]+|\"[^\"]+\")/";
            preg_match_all($propsRegex, $propsRaw, $propsMatches);
            $props = [];
            foreach ($propsMatches['keys'] as $j => $key) {
                $value = trim($propsMatches['values'][$j], '"');
                $props[$key] = $value;
            }

            $data['levelpic'] = @$props['titlepatch'];
            if (strpos(@$props['enterpic'], '$') === 0) {
                $data['$enteranim'] = ltrim($props['enterpic'], '$');
            }
            if (strpos(@$props['exitpic'], '$') === 0) {
                $data['$exitanim'] = ltrim($props['exitpic'], '$');
            }

            $this->data['mapinfo'][$i] = $data;
        }
    }

    /**
     * @return void
     */
    private function readIntermissionScripts(): void{
        $scripts = array_unique(array_merge(
            array_column($this->data['mapinfo'], '$enteranim'),
            array_column($this->data['mapinfo'], '$exitanim'),
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
                $data['bg'] = $matches['bg'];

            } elseif (preg_match('/^SPLAT (?<splat>\w{1,8})$/i', $str, $matches)) {
                $data['splat'] = $matches['splat'];

            } elseif (preg_match('/^POINTER (?<p1>\w{1,8}) (?<p2>\w{1,8})$/i', $str, $matches)) {
                $data['pointers'] = [$matches['p1'], $matches['p2']];

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
                $animation['speed'] = round($matches['speed'] / 35, 2);
                $animation['patches'] = [];

                $i += 2;
                while (($str = $strings[$i]) !== '}') {
                    $animation['patches'][] = $str;
                    $i++;
                }

                $data['animations'][] = $animation;
            }
        }

        $this->data['intermissions'][$scriptName] = $data;
    }

    /**
     * @return void
     */
    private function saveGraphics(): void{
        $saveDir = "$this->tmpDir/$this->wadName";
        @mkdir($saveDir);

        foreach ($this->data['intermissions'] as $intermission) {
            $this->savePng($intermission['bg'], $saveDir);
            $this->savePng($intermission['splat'], $saveDir);
            $this->savePng($intermission['pointers'][0], $saveDir);
            $this->savePng($intermission['pointers'][1], $saveDir);
            foreach ($intermission['animations'] as $animation) {
                foreach (array_unique($animation['patches']) as $patch) {
                    $this->savePng($patch, $saveDir);
                }
            }
        }
    }

    /**
     * @param string|null $lumpName
     * @param string $saveDir
     * @return void
     */
    private function savePng(?string $lumpName, string $saveDir): void{
        if ($lumpName && !in_array($lumpName, $this->savedGraphics, true)) {
            $this->wad->savePicture(strtoupper($lumpName), $saveDir);
            $this->savedGraphics[] = $lumpName;
        }
    }
}

DoomIntermissionConverter::convert('D:\Code\_tmp\INTMAPEV_GZ.wad');
