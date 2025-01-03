<?php

require_once '../DoomWadParser.php';

class DoomWadParserTest {

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
        $this->testOpen('DOOM.WAD');

        $this->testOpen('PLUTONIA.WAD');
    }

    /**
     * @param string $wadFile
     * @return void
     */
    private function testOpen(string $wadFile): void{
        $wad = DoomWadParser::open("$this->testDir\\$wadFile", false);

        $results = [
            'header'    => $wad->header,
            'directory' => $wad->directory,
            'lumps'     => $wad->lumps,
        ];

        $testResultsFile = "$this->testDir\\$wadFile-expected.serialized";

        if ($this->generateExpected) {
            if (!file_exists($testResultsFile)) {
                file_put_contents($testResultsFile, serialize($results));
            }
            return;
        }

        $expected = unserialize(file_get_contents($testResultsFile));

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

(new DoomWadParserTest())->test();
