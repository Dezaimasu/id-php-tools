<?php

require '../DoomIntermissionConverter.php';

class DoomIntermissionConverterTest {

    private string $testDir = 'D:\Code\_wads\_tests';

    private bool $generateExpected;

    /**
     * @param bool $generateExpected
     */
    public function __construct(bool $generateExpected = false){
        $this->generateExpected = $generateExpected;
    }

    /**
     * @return void
     */
    public function test(): void{
    	$this->testConvert('INTMAPSG_GZ.wad', [
            'title'         => 'SIGIL',
            'author'        => 'Oliacym',
            'music'         => 'D_INTER',
            'mapinfo_lump'  => 'MAPINFO',
        ]);

        $this->testConvert('thyinterpic.wad', [
            'title'         => 'Thy Flesh Consumed',
            'author'        => 'Skunk',
            'music'         => 'D_INTER',
            'mapinfo_lump'  => 'MAPINFO',
        ]);

        $this->testConvert('thyint.pk3', [
            'title'         => 'Thy Flesh Consumed',
            'author'        => 'DevilMyEyes',
            'music'         => 'D_INTER',
            'mapinfo_lump'  => 'MAPINFO',
        ]);

        $this->testConvert('INTMAPET_GZ.wad', [
            'title'         => 'Eviternity',
            'author'        => 'Oliacym',
            'music'         => 'D_DM2INT',
            'mapinfo_lump'  => 'ZMAPINFO',
        ]);
    }

    /**
     * @param string $wadFile
     * @param array $wadInfo
     * @return void
     */
    private function testConvert(string $wadFile, array $wadInfo): void{
        $results = [];

        $outputDir = "$this->testDir\\$wadFile-results";
        if (is_dir($outputDir)) {
            exec("rmdir /S /Q \"$outputDir\"");
        }

        DoomIntermissionConverter::convert("$this->testDir\\$wadFile", $outputDir, $wadInfo, true, true);

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir));
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (in_array($file->getBasename(), ['.', '..'])) {
                continue;
            }

            $filepath = $file->getPathname();
            $ext = $file->getExtension();
            if (in_array($ext, ['png', 'gif'], true)) {
                $contents = md5_file($filepath);
            } else {
                $contents = file_get_contents($filepath);
                if ($ext === 'json') {
                    $contents = preg_replace('/"version": "\d{1,2}\.\d{1,2}\.\d{1,2}"/', '"version": "0.0.0"', $contents);
                    $contents = preg_replace('/"timestamp": "[^"]{25}"/', '"timestamp": "0000-00-00T00:00:00+00:00"', $contents);
                }
            }

            $results[$filepath] = $contents;
        }

        $testResultsFile = "$this->testDir\\$wadFile-expected.json";

        if ($this->generateExpected) {
            file_put_contents($testResultsFile, json_encode($results));
            return;
        }

        $expected = json_decode(file_get_contents($testResultsFile), true);

        if ($expected !== $results) {
            foreach (array_diff_assoc($expected, $results) as $filepath => $expectedContents) {
                echo "-------------------------------------------------- $wadFile EXPECTED --------------------------------------------------\n$expectedContents\n";
                echo "-------------------------------------------------- $wadFile GOT --------------------------------------------------\n$results[$filepath]\n";
                echo "--------------------------------------------------------------------------------\n";
            }
        } else {
            echo "-------------------------------------------------- $wadFile SUCCESS --------------------------------------------------\n";
        }
    }
}

(new DoomIntermissionConverterTest())->test();
