#!/usr/bin/env php
<?php

/*
 * Fails the build when line coverage falls below a floor.
 *
 * PHPUnit can print coverage but has no built-in way to insist on a minimum, so
 * the clover report is read here and the run is failed with a message that says
 * which number missed and by how much.
 *
 * Usage: coverage-threshold.php <clover.xml> <minimum percentage>
 */

declare(strict_types=1);

$report = $argv[1] ?? null;
$minimum = (float) ($argv[2] ?? '0');

if (null === $report || !is_file($report)) {
    fwrite(STDERR, sprintf("coverage: no report at %s\n", $report ?? '(no path given)'));

    exit(1);
}

$xml = @simplexml_load_file($report);

if (false === $xml) {
    fwrite(STDERR, sprintf("coverage: %s is not readable as XML\n", $report));

    exit(1);
}

$metrics = $xml->xpath('/coverage/project/metrics');

if (!is_array($metrics) || [] === $metrics) {
    fwrite(STDERR, "coverage: the report has no project metrics\n");

    exit(1);
}

$statements = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];

if ($statements <= 0) {
    fwrite(STDERR, "coverage: the report covers no statements at all\n");

    exit(1);
}

$percentage = $covered / $statements * 100;

printf("coverage: %.2f%% of lines (%d/%d), minimum %.2f%%\n", $percentage, $covered, $statements, $minimum);

if ($percentage + 0.005 < $minimum) {
    fwrite(STDERR, sprintf("coverage: below the %.2f%% floor\n", $minimum));

    exit(1);
}
