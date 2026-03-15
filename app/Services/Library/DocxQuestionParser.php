<?php

namespace App\Services\Library;

use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Throwable;

class DocxQuestionParser
{
    /**
     * Parse a DOCX document into structured questions.
     *
     * @return array{
     *   questions: array<int, array{
     *     item_number: int,
     *     text: string,
     *     question_type: string,
     *     options: array<int, array{label: string, text: string, is_correct: bool}>,
     *     answer_label: ?string,
     *     answer_text: ?string
     *   }>,
     *   warnings: array<int, string>,
     *   answer_key_detected: bool,
     *   answer_key_items: int
     * }
     */
    public function parse(string $filePath): array
    {
        try {
            $phpWord = IOFactory::load($filePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to read DOCX document.', previous: $exception);
        }

        $lines = $this->extractLines($phpWord);
        [$answerKeySection, $answerKeyLetters] = $this->detectAnswerKeySection($lines);

        $contentLines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => $answerKeySection === null || $line['section_index'] !== $answerKeySection
        ));

        $dominantQuestionNumId = $this->resolveDominantQuestionNumId($contentLines);
        $warnings = [];

        $questions = $this->buildQuestions($contentLines, $dominantQuestionNumId, $warnings);
        $this->applyDetectedAnswers($questions, $answerKeyLetters, $warnings);

        return [
            'questions' => $questions,
            'warnings' => array_values(array_unique($warnings)),
            'answer_key_detected' => $answerKeySection !== null,
            'answer_key_items' => count($answerKeyLetters),
        ];
    }

    /**
     * @return array<int, array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }>
     */
    private function extractLines(object $phpWord): array
    {
        $lines = [];

        foreach ($phpWord->getSections() as $sectionIndex => $section) {
            foreach ($section->getElements() as $element) {
                // Handle Table elements (Word often uses invisible tables for multi-column options)
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            foreach ($cell->getElements() as $cellElement) {
                                $line = $this->lineFromElement($cellElement, $sectionIndex);
                                if ($line !== null) {
                                    $lines[] = $line;
                                }
                            }
                        }
                    }
                    continue;
                }

                $line = $this->lineFromElement($element, $sectionIndex);
                if ($line === null) {
                    continue;
                }

                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }|null
     */
    private function lineFromElement(object $element, int $sectionIndex): ?array
    {
        $segments = $this->collectTextSegments($element);
        if (count($segments) === 0) {
            return null;
        }

        $rawText = implode('', array_column($segments, 'text'));
        $text = $this->normalizeText($rawText);
        if ($text === '') {
            return null;
        }

        $isRed = false;
        foreach ($segments as $segment) {
            if ($this->isLikelyRed($segment['color'] ?? null)) {
                $isRed = true;
                break;
            }
        }

        $numId = null;
        $depth = null;

        if ($element instanceof ListItemRun) {
            $style = $element->getStyle();
            $resolvedNumId = method_exists($style, 'getNumId') ? $style->getNumId() : null;
            $numId = is_numeric($resolvedNumId) ? (int) $resolvedNumId : null;
            $depth = $element->getDepth();
        }

        return [
            'section_index' => $sectionIndex,
            'text' => $text,
            'raw_text' => trim($rawText),
            'num_id' => $numId,
            'depth' => is_numeric($depth) ? (int) $depth : null,
            'is_red' => $isRed,
        ];
    }

    /**
     * @return array<int, array{text: string, color: ?string}>
     */
    private function collectTextSegments(object $element): array
    {
        if ($element instanceof Text) {
            $font = $element->getFontStyle();
            $color = is_object($font) && method_exists($font, 'getColor')
                ? $font->getColor()
                : null;

            return [[
                'text' => (string) $element->getText(),
                'color' => is_string($color) ? strtoupper(trim($color, '#')) : null,
            ]];
        }

        if ($element instanceof TextRun || $element instanceof ListItemRun) {
            $segments = [];
            foreach ($element->getElements() as $child) {
                if (!is_object($child)) {
                    continue;
                }

                foreach ($this->collectTextSegments($child) as $segment) {
                    $segments[] = $segment;
                }
            }

            return $segments;
        }

        $className = get_class($element);
        if (str_ends_with($className, '\\Tab')) {
            return [['text' => "\t", 'color' => null]];
        }
        if (str_ends_with($className, '\\TextBreak')) {
            return [['text' => "\n", 'color' => null]];
        }

        if (method_exists($element, 'getText')) {
            return [[
                'text' => (string) $element->getText(),
                'color' => null,
            ]];
        }

        return [];
    }

    private function normalizeText(string $text): string
    {
        $clean = str_replace(["\r", "\n", "\t"], ' ', $text);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    private function isLikelyRed(?string $color): bool
    {
        if (!is_string($color) || $color === '') {
            return false;
        }

        $normalized = strtoupper(trim($color, '#'));
        if (in_array($normalized, ['FF0000', 'EE0000', 'C00000', 'DC0000', 'RED'], true)) {
            return true;
        }

        if (!preg_match('/^[0-9A-F]{6}$/', $normalized)) {
            return false;
        }

        $red = hexdec(substr($normalized, 0, 2));
        $green = hexdec(substr($normalized, 2, 2));
        $blue = hexdec(substr($normalized, 4, 2));

        return $red >= 180 && $green <= 90 && $blue <= 90 && $red > ($green + 35) && $red > ($blue + 35);
    }

    /**
     * @param  array<int, array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }>  $lines
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function detectAnswerKeySection(array $lines): array
    {
        $stats = [];
        $lettersBySection = [];

        foreach ($lines as $line) {
            $sectionIndex = $line['section_index'];
            $stats[$sectionIndex]['total'] = ($stats[$sectionIndex]['total'] ?? 0) + 1;

            if (!preg_match('/^[A-H]$/i', $line['text'])) {
                continue;
            }

            $stats[$sectionIndex]['letter_total'] = ($stats[$sectionIndex]['letter_total'] ?? 0) + 1;
            $lettersBySection[$sectionIndex][] = strtoupper($line['text']);
        }

        $pickedSection = null;
        $pickedScore = 0.0;

        foreach ($stats as $sectionIndex => $sectionStats) {
            $total = (int) ($sectionStats['total'] ?? 0);
            $letterTotal = (int) ($sectionStats['letter_total'] ?? 0);

            if ($total < 5 || $letterTotal < 5) {
                continue;
            }

            $ratio = $total === 0 ? 0.0 : $letterTotal / $total;
            if ($ratio < 0.8) {
                continue;
            }

            $score = ($ratio * 1000) + $letterTotal;
            if ($score <= $pickedScore) {
                continue;
            }

            $pickedSection = (int) $sectionIndex;
            $pickedScore = $score;
        }

        return [$pickedSection, $pickedSection === null ? [] : ($lettersBySection[$pickedSection] ?? [])];
    }

    /**
     * @param  array<int, array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }>  $lines
     */
    private function resolveDominantQuestionNumId(array $lines): ?int
    {
        $counts = [];

        foreach ($lines as $line) {
            if ($line['num_id'] === null || $line['depth'] !== 0) {
                continue;
            }

            if (preg_match('/^[A-H]$/i', $line['text'])) {
                continue;
            }

            $counts[$line['num_id']] = ($counts[$line['num_id']] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * @param  array<int, array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }>  $lines
     * @param  array<int, string>  $warnings
     * @return array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, is_red: bool}>,
     *   red_labels: array<int, string>,
     *   has_labeled_options: bool,
     *   pending_structural: array<int, array{text: string, is_red: bool, num_id: ?int, depth: ?int}>
     * }>
     */
    private function buildQuestions(array $lines, ?int $dominantQuestionNumId, array &$warnings): array
    {
        $questions = [];
        $current = null;

        $commit = function () use (&$current, &$questions, &$warnings): void {
            if ($current === null) {
                return;
            }

            $current['text'] = $this->normalizeText($current['text']);
            if ($current['text'] === '') {
                $current = null;
                return;
            }

            if (count($current['pending_structural']) > 0) {
                if ($current['has_labeled_options']) {
                    $current['text'] = $this->appendStructuralLinesToQuestionText(
                        $current['text'],
                        $current['pending_structural']
                    );
                } else {
                    foreach ($current['pending_structural'] as $pendingLine) {
                        $current['options'][] = [
                            'label' => '',
                            'text' => $pendingLine['text'],
                            'is_red' => $pendingLine['is_red'],
                        ];
                    }
                }
            }

            $this->normalizeUnlabeledMixedOptions($current);

            $current['options'] = array_values(array_filter(
                $current['options'],
                fn (array $option): bool => $this->normalizeText($option['text']) !== ''
            ));

            $existingLabels = [];
            foreach ($current['options'] as $index => $option) {
                $label = strtoupper(trim($option['label']));
                if ($label === '' || isset($existingLabels[$label])) {
                    $label = $this->resolveNextOptionLabel($current['options'], $index);
                }

                $current['options'][$index]['label'] = $label;
                $current['options'][$index]['text'] = $this->normalizeText($option['text']);
                $existingLabels[$label] = true;
            }

            usort(
                $current['options'],
                fn (array $left, array $right): int => $this->compareOptionLabels(
                    (string) ($left['label'] ?? ''),
                    (string) ($right['label'] ?? ''),
                )
            );

            $redLabels = [];
            foreach ($current['options'] as $option) {
                if ($option['is_red']) {
                    $redLabels[] = $option['label'];
                }
            }

            if (count($redLabels) > 1) {
                $warnings[] = sprintf(
                    'Question "%s..." has multiple red options (%s).',
                    substr($current['text'], 0, 60),
                    implode(', ', $redLabels),
                );
            }

            $current['red_labels'] = array_values(array_unique($redLabels));
            unset($current['has_labeled_options'], $current['pending_structural']);
            $questions[] = $current;
            $current = null;
        };

        foreach ($lines as $line) {
            $text = $line['text'];
            $rawText = $line['raw_text'] ?? $text;

            if ($text === '') {
                continue;
            }

            if ($this->isIgnorableHeader($text)) {
                continue;
            }

            if ($current === null && $this->looksLikeHeading($text)) {
                continue;
            }

            $strippedQuestion = $this->stripQuestionPrefix($text);
            $optionsWithLabels = $this->extractOptionsWithLabels($rawText);

            $isQuestionAnchor = $dominantQuestionNumId !== null
                && $line['num_id'] !== null
                && $line['num_id'] === $dominantQuestionNumId
                && $line['depth'] === 0;

            if ($strippedQuestion !== null || $isQuestionAnchor) {
                $commit();
                $current = [
                    'text' => $this->normalizeText($strippedQuestion ?? $text),
                    'options' => [],
                    'red_labels' => [],
                    'has_labeled_options' => false,
                    'pending_structural' => [],
                ];
                continue;
            }

            if ($current === null) {
                if (!$this->looksLikeQuestionText($text)) {
                    continue;
                }

                $current = [
                    'text' => $this->normalizeText($text),
                    'options' => [],
                    'red_labels' => [],
                    'has_labeled_options' => false,
                    'pending_structural' => [],
                ];
                continue;
            }

            if ($optionsWithLabels !== null) {
                if (count($current['pending_structural']) > 0) {
                    $current['text'] = $this->appendStructuralLinesToQuestionText(
                        $current['text'],
                        $current['pending_structural']
                    );
                    $current['pending_structural'] = [];
                }

                $current['has_labeled_options'] = true;
                foreach ($optionsWithLabels as $extractedOption) {
                    $current['options'][] = [
                        'label' => $extractedOption['label'],
                        'text' => $extractedOption['text'],
                        'is_red' => $line['is_red'],
                    ];
                }
                continue;
            }

            $isStructuralOption = ($line['depth'] !== null && $line['depth'] > 0)
                || ($dominantQuestionNumId !== null && $line['num_id'] !== null && $line['num_id'] !== $dominantQuestionNumId);

            if ($isStructuralOption) {
                // Check for embedded secondary option after tabs in raw text.
                // Word often uses list numbering for A./B. labels (not in text),
                // while C./D. appear inline after tab characters.
                // e.g. raw: "Analytic Engine\t\t\tc. Punch Cards"
                $splitOptions = $this->splitStructuralLineWithEmbeddedLabel($rawText);

                if ($splitOptions !== null) {
                    // We found an embedded label — produce two options from this one line
                    foreach ($splitOptions as $splitOption) {
                        $current['options'][] = [
                            'label' => $splitOption['label'],
                            'text' => $splitOption['text'],
                            'is_red' => $line['is_red'],
                        ];
                    }
                    $current['has_labeled_options'] = true;
                    continue;
                }

                if (!$current['has_labeled_options'] && count($current['options']) === 0) {
                    if ($this->shouldStartStructuralOptions($current['pending_structural'], $line)) {
                        $current['text'] = $this->appendStructuralLinesToQuestionText(
                            $current['text'],
                            $current['pending_structural']
                        );
                        $current['pending_structural'] = [];
                        $current['options'][] = [
                            'label' => '',
                            'text' => $text,
                            'is_red' => $line['is_red'],
                        ];
                        continue;
                    }

                    $current['pending_structural'][] = [
                        'text' => $text,
                        'is_red' => $line['is_red'],
                        'num_id' => $line['num_id'],
                        'depth' => $line['depth'],
                    ];
                    continue;
                }

                $current['options'][] = [
                    'label' => '',
                    'text' => $text,
                    'is_red' => $line['is_red'],
                ];
                continue;
            }

            if ($this->looksLikeQuestionText($text) && count($current['options']) >= 2) {
                $commit();
                $current = [
                    'text' => $this->normalizeText($text),
                    'options' => [],
                    'red_labels' => [],
                    'has_labeled_options' => false,
                    'pending_structural' => [],
                ];
                continue;
            }

            if (count($current['options']) > 0 && !$this->looksLikeQuestionText($text)) {
                $lastOptionIndex = count($current['options']) - 1;
                $current['options'][$lastOptionIndex]['text'] = $this->normalizeText(
                    $current['options'][$lastOptionIndex]['text'] . ' ' . $text
                );
                $current['options'][$lastOptionIndex]['is_red'] = $current['options'][$lastOptionIndex]['is_red']
                    || $line['is_red'];
                continue;
            }

            $current['text'] = $this->normalizeText($current['text'] . ' ' . $text);
        }

        $commit();

        return $questions;
    }

    /**
     * @param  array<int, array{text: string, is_red: bool}>  $lines
     */
    private function appendStructuralLinesToQuestionText(string $questionText, array $lines): string
    {
        $parts = [];

        foreach ($lines as $index => $line) {
            $clean = $this->normalizeText($line['text'] ?? '');
            if ($clean === '') {
                continue;
            }

            $clean = preg_replace('/^\(?\d+\)?[.)]\s*/u', '', $clean) ?? $clean;
            $clean = preg_replace('/^[A-H][.)]\s*/iu', '', $clean) ?? $clean;
            $clean = $this->normalizeText($clean);
            if ($clean === '') {
                continue;
            }

            $parts[] = sprintf('%d. %s', $index + 1, $clean);
        }

        if ($parts === []) {
            return $this->normalizeText($questionText);
        }

        return $this->normalizeText(trim($questionText . ' ' . implode(' ', $parts)));
    }

    /**
     * Decide if a new structural line should start option parsing after accumulating
     * statement-like structural lines.
     *
     * @param  array<int, array{text: string, is_red: bool, num_id: ?int, depth: ?int}>  $pending
     * @param  array{
     *   section_index: int,
     *   text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   is_red: bool
     * }  $line
     */
    private function shouldStartStructuralOptions(array $pending, array $line): bool
    {
        if (count($pending) < 2) {
            return false;
        }

        $first = $pending[0];

        if (
            $first['num_id'] !== null
            && $line['num_id'] !== null
            && (int) $first['num_id'] !== (int) $line['num_id']
        ) {
            return true;
        }

        if (
            $first['depth'] !== null
            && $line['depth'] !== null
            && (int) $first['depth'] !== (int) $line['depth']
        ) {
            return true;
        }

        $statementLikeCount = 0;
        foreach ($pending as $pendingLine) {
            if ($this->looksLikeStatementLine($pendingLine['text'] ?? '')) {
                $statementLikeCount++;
            }
        }

        if ($statementLikeCount < 2) {
            return false;
        }

        return $this->looksLikeCompactChoice($line['text'] ?? '');
    }

    /**
     * If structural parsing produced unlabeled mixed content (statement list + compact choices),
     * move statement-like prefix lines back into question text.
     *
     * @param  array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, is_red: bool}>,
     *   red_labels: array<int, string>,
     *   has_labeled_options: bool,
     *   pending_structural: array<int, array{text: string, is_red: bool, num_id: ?int, depth: ?int}>
     * }  $current
     */
    private function normalizeUnlabeledMixedOptions(array &$current): void
    {
        if ($current['has_labeled_options']) {
            return;
        }

        if (count($current['options']) < 5) {
            return;
        }

        foreach ($current['options'] as $option) {
            if (trim((string) ($option['label'] ?? '')) !== '') {
                return;
            }
        }

        $splitIndex = null;
        $optionCount = count($current['options']);

        for ($index = 2; $index <= $optionCount - 2; $index++) {
            $prefix = array_slice($current['options'], 0, $index);
            $suffix = array_slice($current['options'], $index);

            $suffixAllCompact = true;
            foreach ($suffix as $option) {
                if (!$this->looksLikeCompactChoice((string) ($option['text'] ?? ''))) {
                    $suffixAllCompact = false;
                    break;
                }
            }

            if (!$suffixAllCompact) {
                continue;
            }

            $prefixStatementLike = 0;
            foreach ($prefix as $option) {
                if ($this->looksLikeStatementLine((string) ($option['text'] ?? ''))) {
                    $prefixStatementLike++;
                }
            }

            if ($prefixStatementLike < 2) {
                continue;
            }

            if ($prefixStatementLike / max(1, count($prefix)) < 0.6) {
                continue;
            }

            $splitIndex = $index;
            break;
        }

        if ($splitIndex === null) {
            return;
        }

        $prefix = array_slice($current['options'], 0, $splitIndex);
        $suffix = array_slice($current['options'], $splitIndex);

        $structuralPrefix = array_map(
            static fn (array $option): array => [
                'text' => $option['text'],
                'is_red' => (bool) ($option['is_red'] ?? false),
            ],
            $prefix
        );

        $current['text'] = $this->appendStructuralLinesToQuestionText($current['text'], $structuralPrefix);
        $current['options'] = array_values($suffix);
    }

    private function looksLikeCompactChoice(string $text): bool
    {
        $clean = $this->normalizeText($text);
        if ($clean === '') {
            return false;
        }

        if (strlen($clean) > 24) {
            return false;
        }

        if (preg_match('/^[0-9][0-9\s\/,\.-]{1,}$/u', $clean)) {
            return true;
        }

        if (preg_match('/^[A-H0-9][A-H0-9\s\/,\.-]{1,}$/iu', $clean) && substr_count($clean, ' ') <= 2) {
            return true;
        }

        return false;
    }

    private function looksLikeStatementLine(string $text): bool
    {
        $clean = $this->normalizeText($text);
        if ($clean === '') {
            return false;
        }

        if (!preg_match('/[A-Za-z]/u', $clean)) {
            return false;
        }

        return strlen($clean) >= 6 && substr_count($clean, ' ') >= 1;
    }

    private function looksLikeHeading(string $text): bool
    {
        if (strlen($text) <= 70 && strtoupper($text) === $text && preg_match('/[A-Z]/', $text)) {
            return true;
        }

        if (preg_match('/^LLE\s+REVIEW/i', $text)) {
            return true;
        }

        return false;
    }

    private function stripQuestionPrefix(string $text): ?string
    {
        if (!preg_match('/^(?:Q(?:uestion)?\s*)?\d+[\).\:-]\s*(.+)$/iu', $text, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? '');
    }

    private function isIgnorableHeader(string $text): bool
    {
        // Ignore lines like "1. Name ____ Direction: Choose the best answer..."
        // Or "Directions: Choose the best..."
        if (preg_match('/^(?:\d+[\).\:-]\s*)?(?:Name|Date|Score|Section|Direction)s?\b\s*[_:\-]/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{label: string, text: string}>|null
     */
    private function extractOptionsWithLabels(string $rawText): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        // Must start with a valid option label pattern
        if (!preg_match('/^([A-H])[\).\:-]\s*/iu', $rawText)) {
            return null;
        }

        // Normalize: replace tabs with a marker we can detect
        $normalized = str_replace("\t", '    ', $rawText);

        // Strategy: find ALL option label positions in the text, then split between them.
        // This handles any number of spaces/tabs between side-by-side options.
        $labelPositions = [];
        $offset = 0;
        $length = strlen($normalized);

        while ($offset < $length) {
            // Match a label like "A." or "A)" or "A:" at position $offset
            // The \G\s* consumes any leading whitespace before the label letter
            if (preg_match('/\G(\s*)([A-H])[\).\:-]\s*/iu', $normalized, $m, 0, $offset)) {
                $leadingWhitespace = $m[1];
                $labelLetter = $m[2];

                // Valid label position: at start of string, or has 2+ whitespace chars before it
                $isAtStart = ($offset === 0 && $leadingWhitespace === '');
                $hasGap = strlen($leadingWhitespace) >= 2;

                if ($isAtStart || $hasGap) {
                    $labelPositions[] = [
                        'label' => strtoupper($labelLetter),
                        'fullMatchStart' => $offset,
                        'textStart' => $offset + strlen($m[0]),
                    ];
                }

                $offset += strlen($m[0]);
                continue;
            }

            $offset++;
        }

        // If we only found one or zero labels, try a more lenient search
        // that allows single-space separation (for collapsed formatting)
        if (count($labelPositions) <= 1) {
            $labelPositions = [];
            preg_match_all('/(?:^|\s)([A-H])[\).\:-]\s*/iu', $normalized, $allMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($allMatches as $m) {
                $labelPositions[] = [
                    'label' => strtoupper($m[1][0]),
                    'fullMatchStart' => $m[0][1],
                    'textStart' => $m[0][1] + strlen($m[0][0]),
                ];
            }

            // For the lenient parse, all labels must be unique to be valid
            $labels = array_column($labelPositions, 'label');
            if (count($labels) !== count(array_unique($labels))) {
                // Ambiguous — fall back to treating it as a single option
                $labelPositions = [];
                if (preg_match('/^([A-H])[\).\:-]\s*(.+)$/iu', $normalized, $singleMatch)) {
                    return [[
                        'label' => strtoupper($singleMatch[1]),
                        'text' => $this->normalizeText($singleMatch[2]),
                    ]];
                }
                return null;
            }
        }

        if (count($labelPositions) === 0) {
            return null;
        }

        // Extract text between consecutive label positions
        $options = [];
        $count = count($labelPositions);
        for ($i = 0; $i < $count; $i++) {
            $textStart = $labelPositions[$i]['textStart'];
            $textEnd = ($i + 1 < $count)
                ? $labelPositions[$i + 1]['fullMatchStart']
                : $length;

            $optionText = $this->normalizeText(substr($normalized, $textStart, $textEnd - $textStart));

            $options[] = [
                'label' => $labelPositions[$i]['label'],
                'text' => $optionText,
            ];
        }

        // Filter out empty options
        $options = array_values(array_filter(
            $options,
            fn(array $opt): bool => $this->normalizeText($opt['text']) !== ''
        ));

        return $options === [] ? null : $options;
    }

    /**
     * Word sometimes renders two answer choices on one structural line where the
     * first choice is carried by list numbering and the second choice appears
     * inline after tab spacing, e.g. "Worm\t\t\tc. Spam".
     *
     * @return array<int, array{label: string, text: string}>|null
     */
    private function splitStructuralLineWithEmbeddedLabel(string $rawText): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        if (preg_match('/^([A-H])[\).\:-]\s*/iu', $rawText)) {
            return null;
        }

        $normalized = str_replace("\t", '    ', $rawText);
        if (!preg_match('/\s{2,}([A-H])[\).\:-]\s*/iu', $normalized, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $embeddedLabelOffset = $match[1][1] ?? null;
        if (!is_int($embeddedLabelOffset) || $embeddedLabelOffset <= 0) {
            return null;
        }

        $leadingText = $this->normalizeText(substr($normalized, 0, $embeddedLabelOffset));
        if ($leadingText === '') {
            return null;
        }

        $tail = ltrim(substr($normalized, $embeddedLabelOffset));
        if ($tail === '') {
            return null;
        }

        $tailOptions = $this->extractOptionsWithLabels($tail);
        if ($tailOptions === null || $tailOptions === []) {
            return null;
        }

        return array_merge([[
            'label' => '',
            'text' => $leadingText,
        ]], $tailOptions);
    }

    private function looksLikeQuestionText(string $text): bool
    {
        if (preg_match('/\?$/', $text)) {
            return true;
        }

        if (preg_match('/_{3,}/', $text)) {
            return true;
        }

        if (preg_match('/^(which|what|who|where|when|why|how|this|these|that|those|an|a|the|within|in|at)\b/i', $text)) {
            return true;
        }

        return strlen($text) >= 70;
    }

    /**
     * @param  array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, is_red: bool}>,
     *   red_labels: array<int, string>
     * }>  $questions
     * @param  array<int, string>  $answerKeyLetters
     * @param  array<int, string>  $warnings
     */
    private function applyDetectedAnswers(array &$questions, array $answerKeyLetters, array &$warnings): void
    {
        if ($answerKeyLetters !== [] && count($answerKeyLetters) !== count($questions)) {
            $warnings[] = sprintf(
                'Answer key item count (%d) does not match detected questions (%d).',
                count($answerKeyLetters),
                count($questions),
            );
        }

        foreach ($questions as $index => &$question) {
            $redAnswerLabel = $question['red_labels'][0] ?? null;
            $keyAnswerLabel = $answerKeyLetters[$index] ?? null;

            if ($redAnswerLabel !== null && $keyAnswerLabel !== null && $redAnswerLabel !== $keyAnswerLabel) {
                $warnings[] = sprintf(
                    'Question %d has conflicting answers: red=%s, key=%s. Using red.',
                    $index + 1,
                    $redAnswerLabel,
                    $keyAnswerLabel,
                );
            }

            $resolvedAnswerLabel = $redAnswerLabel ?? $keyAnswerLabel;
            $resolvedAnswerText = null;

            foreach ($question['options'] as $optionIndex => $option) {
                $isCorrect = $resolvedAnswerLabel !== null && strtoupper($option['label']) === strtoupper($resolvedAnswerLabel);
                $question['options'][$optionIndex]['is_correct'] = $isCorrect;

                if ($isCorrect) {
                    $resolvedAnswerText = $option['text'];
                }

                unset($question['options'][$optionIndex]['is_red']);
            }

            if ($resolvedAnswerLabel !== null && $resolvedAnswerText === null && count($question['options']) > 0) {
                $warnings[] = sprintf(
                    'Question %d answer key "%s" does not match any option label.',
                    $index + 1,
                    $resolvedAnswerLabel,
                );
                $resolvedAnswerLabel = null;
            }

            $normalizedOptions = array_values($question['options']);
            $questionType = $this->resolveQuestionType($normalizedOptions);

            if ($questionType !== 'open_ended' && count($normalizedOptions) < 2) {
                $warnings[] = sprintf(
                    'Question %d has fewer than 2 options.',
                    $index + 1,
                );
            }

            if ($questionType !== 'open_ended' && $resolvedAnswerLabel === null) {
                $warnings[] = sprintf(
                    'Question %d has no detected correct answer.',
                    $index + 1,
                );
            }

            $question = [
                'item_number' => $index + 1,
                'text' => $question['text'],
                'question_type' => $questionType,
                'options' => $normalizedOptions,
                'answer_label' => $resolvedAnswerLabel,
                'answer_text' => $resolvedAnswerText,
            ];
        }
        unset($question);
    }

    /**
     * @param  array<int, array{label: string, text: string, is_correct: bool}>  $options
     */
    private function resolveQuestionType(array $options): string
    {
        if ($options === []) {
            return 'open_ended';
        }

        if (count($options) === 2) {
            $normalized = array_map(
                static fn (array $option): string => strtolower(trim($option['text'], " \t\n\r\0\x0B.")),
                $options
            );

            sort($normalized);
            if ($normalized === ['false', 'true']) {
                return 'true_false';
            }
        }

        return 'multiple_choice';
    }

    /**
     * @param  array<int, array{label: string, text: string, is_red: bool}>  $options
     */
    private function resolveNextOptionLabel(array $options, int $targetIndex): string
    {
        $used = [];
        foreach ($options as $index => $option) {
            if ($index === $targetIndex) {
                continue;
            }

            $label = strtoupper(trim($option['label']));
            if ($label !== '') {
                $used[$label] = true;
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $candidate) {
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        return (string) ($targetIndex + 1);
    }

    private function compareOptionLabels(string $leftLabel, string $rightLabel): int
    {
        return $this->optionLabelSortKey($leftLabel) <=> $this->optionLabelSortKey($rightLabel);
    }

    private function optionLabelSortKey(string $label): int
    {
        $normalized = strtoupper(trim($label));
        $alphaLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $alphaIndex = array_search($normalized, $alphaLabels, true);

        if ($alphaIndex !== false) {
            return (int) $alphaIndex;
        }

        if (ctype_digit($normalized)) {
            return 100 + (int) $normalized;
        }

        return 1000;
    }
}
