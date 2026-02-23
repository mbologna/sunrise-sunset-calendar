#!/usr/bin/env php
<?php

/**
 * iCalendar Validator
 * Validates the generated .ics file for common issues.
 */
class ICalValidator
{
    private $errors = [];
    private $warnings = [];
    private $stats = [];

    public function validate($file)
    {
        echo "🔍 Validating iCalendar file: {$file}\n";
        echo str_repeat('=', 50) . "\n\n";

        if (!file_exists($file)) {
            $this->addError("File not found: {$file}");
            $this->printResults();

            return false;
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $this->checkStructure($lines);
        $this->checkEvents($lines);
        $this->checkEncoding($content);
        $this->checkLineEndings($content);
        $this->gatherStats($lines);

        $this->printResults();

        return empty($this->errors);
    }

    private function checkStructure($lines)
    {
        echo "📋 Checking iCalendar Structure...\n";

        // Check for required components
        $hasBeginCal = false;
        $hasEndCal = false;
        $hasVersion = false;
        $hasProdId = false;

        foreach ($lines as $line) {
            if (trim($line) === 'BEGIN:VCALENDAR') {
                $hasBeginCal = true;
            }
            if (trim($line) === 'END:VCALENDAR') {
                $hasEndCal = true;
            }
            if (strpos($line, 'VERSION:') === 0) {
                $hasVersion = true;
            }
            if (strpos($line, 'PRODID:') === 0) {
                $hasProdId = true;
            }
        }

        if (!$hasBeginCal) {
            $this->addError('Missing BEGIN:VCALENDAR');
        }
        if (!$hasEndCal) {
            $this->addError('Missing END:VCALENDAR');
        }
        if (!$hasVersion) {
            $this->addError('Missing VERSION property');
        }
        if (!$hasProdId) {
            $this->addWarning('Missing PRODID property');
        }

        if ($hasBeginCal && $hasEndCal && $hasVersion) {
            echo "  ✅ Calendar structure is valid\n";
        }
    }

    private function checkEvents($lines)
    {
        echo "\n📅 Checking Events...\n";

        $eventCount = 0;
        $inEvent = false;
        $currentEvent = [];

        foreach ($lines as $num => $line) {
            $trimmed = trim($line);

            if ($trimmed === 'BEGIN:VEVENT') {
                if ($inEvent) {
                    $this->addError('Line ' . ($num + 1) . ': Nested BEGIN:VEVENT');
                }
                $inEvent = true;
                $currentEvent = ['begin' => $num + 1];
                continue;
            }

            if ($trimmed === 'END:VEVENT') {
                if (!$inEvent) {
                    $this->addError('Line ' . ($num + 1) . ': END:VEVENT without BEGIN');
                } else {
                    $eventCount++;
                    $this->validateEvent($currentEvent);
                }
                $inEvent = false;
                $currentEvent = [];
                continue;
            }

            if ($inEvent) {
                // Collect event properties
                if (strpos($trimmed, 'UID:') === 0) {
                    $currentEvent['uid'] = substr($trimmed, 4);
                }
                if (strpos($trimmed, 'SUMMARY:') === 0) {
                    $currentEvent['summary'] = substr($trimmed, 8);
                }
                if (strpos($trimmed, 'DTSTART') === 0) {
                    $currentEvent['dtstart'] = true;
                }
                if (strpos($trimmed, 'DTEND') === 0) {
                    $currentEvent['dtend'] = true;
                }
                if (strpos($trimmed, 'DTSTAMP') === 0) {
                    $currentEvent['dtstamp'] = true;
                }
            }
        }

        if ($inEvent) {
            $this->addError('Event started but never closed');
        }

        echo "  ✅ Found {$eventCount} events\n";
        $this->stats['event_count'] = $eventCount;
    }

    private function validateEvent($event)
    {
        // Check required properties
        if (!isset($event['uid'])) {
            $this->addError("Event at line {$event['begin']} missing UID");
        }
        if (!isset($event['summary'])) {
            $this->addWarning("Event at line {$event['begin']} missing SUMMARY");
        }
        if (!isset($event['dtstart'])) {
            $this->addError("Event at line {$event['begin']} missing DTSTART");
        }
        if (!isset($event['dtstamp'])) {
            $this->addWarning("Event at line {$event['begin']} missing DTSTAMP");
        }
    }

    private function checkEncoding($content)
    {
        echo "\n🔤 Checking Encoding...\n";

        // Check for UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $this->addWarning('File may not be UTF-8 encoded');
        } else {
            echo "  ✅ File is UTF-8 encoded\n";
        }

        // Check for BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $this->addWarning('File contains UTF-8 BOM (may cause issues)');
        }

        // Check for null bytes
        if (strpos($content, "\0") !== false) {
            $this->addError('File contains null bytes');
        }
    }

    private function checkLineEndings($content)
    {
        echo "\n↩️  Checking Line Endings...\n";

        $crlfCount = substr_count($content, "\r\n");
        $lfCount = substr_count($content, "\n") - $crlfCount;
        $crCount = substr_count($content, "\r") - $crlfCount;

        echo "  CRLF (\\r\\n): {$crlfCount}\n";
        echo "  LF (\\n):     {$lfCount}\n";
        echo "  CR (\\r):     {$crCount}\n";

        if ($lfCount > $crlfCount * 2) {
            $this->addWarning('File uses mostly LF line endings (iCal spec prefers CRLF)');
        }

        if ($lfCount > 0 && $crlfCount > 0) {
            $this->addWarning('Mixed line endings detected');
        }
    }

    private function gatherStats($lines)
    {
        $this->stats['total_lines'] = count($lines);
        $this->stats['description_lines'] = 0;

        foreach ($lines as $line) {
            if (strpos($line, 'DESCRIPTION:') !== false ||
                (strpos($line, ' ') === 0 && isset($inDescription))) {
                $this->stats['description_lines']++;
            }
        }
    }

    private function addError($message)
    {
        $this->errors[] = $message;
    }

    private function addWarning($message)
    {
        $this->warnings[] = $message;
    }

    private function printResults()
    {
        echo "\n" . str_repeat('=', 50) . "\n";

        if (!empty($this->errors)) {
            echo "\n❌ ERRORS (" . count($this->errors) . "):\n";
            foreach ($this->errors as $error) {
                echo "  • {$error}\n";
            }
        }

        if (!empty($this->warnings)) {
            echo "\n⚠️  WARNINGS (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $warning) {
                echo "  • {$warning}\n";
            }
        }

        if (empty($this->errors) && empty($this->warnings)) {
            echo "\n✅ All validation checks passed!\n";
        }

        if (!empty($this->stats)) {
            echo "\n📊 Statistics:\n";
            foreach ($this->stats as $key => $value) {
                $label = str_replace('_', ' ', ucfirst($key));
                echo "  {$label}: {$value}\n";
            }
        }

        echo "\n";
    }
}

// Run validator
$file = $argv[1] ?? 'calendar.ics';
$validator = new ICalValidator();
$success = $validator->validate($file);

exit($success ? 0 : 1);
