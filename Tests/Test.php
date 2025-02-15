<?php

namespace Tests;

abstract class Test {

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
        $testResultsFile = $this->testDirPath("$wadFile-expected.serialized");

        if ($this->generateExpected) {
            if (!file_exists($testResultsFile)) {
                file_put_contents($testResultsFile, serialize($results));
            }
            return;
        }

        $expected = unserialize(file_get_contents($testResultsFile));

        if ($expected !== $results) {
            $diff = self::arrayDiffRecursive($expected, $results);

            echo "-------------------------------------------------- $wadFile FAILED --------------------------------------------------\n";
            echo var_export($diff, true) . "\n";
            echo "--------------------------------------------------------------------------------\n";

        } else {
            echo "-------------------------------------------------- $wadFile SUCCESS --------------------------------------------------\n";
        }
    }

    /**
     * @param string $testName
     * @return string
     */
    protected function testDirPath(string $testName): string{
        $testDir = 'D:\Code\_wads\_tests';
        $filename = basename((new \ReflectionClass($this))->getFileName());

        return "$testDir\\$filename\\$testName";
    }

    /**
     * @param array $arr1
     * @param array $arr2
     * @return array
     */
    public static function arrayDiffRecursive(array $arr1, array $arr2): array{
        $diff = [];

        foreach ($arr1 as $key => $value) {
            if (!isset($arr2[$key])) {
                $diff[$key] = "MISSING: $key";
            } else if ($value !== $arr2[$key]) {
                if (is_array($value)) {
                    if (self::isMultidimensionalArray($value)) {
                        $diff[$key] = self::arrayDiffRecursive($value, $arr2[$key]);
                    } else {
                        $diff[$key] = 'NOT IN 2: ' . json_encode(array_diff($value, $arr2[$key])) . ', NOT IN 1: ' . json_encode(array_diff($arr2[$key], $value)) . '.';
                    }
                } else {
                    $diff[$key] = "$value != $arr2[$key]";
                }
            }
        }

        return $diff;
    }

    /**
     * @param array $arr
     * @return bool
     */
    private static function isMultidimensionalArray(array $arr): bool{
        foreach ($arr as $val) {
            if (is_array($val)) {
                return true;
            }
        }

        return false;
    }

}
