<?php

namespace Tests;

abstract class Test {

    protected string $testDir = 'D:\Code\_wads\_tests';

    protected bool $generateExpected;

    /**
     * @param bool $generateExpected
     */
    public function __construct(bool $generateExpected = false){
        $this->generateExpected = $generateExpected;
    }

    /**
     * @return void
     */
    abstract public function test(): void;

    /**
     * @param string $wadFile
     * @param array $results
     * @return void
     */
    protected function checkResults(string $wadFile, array $results): void{
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
