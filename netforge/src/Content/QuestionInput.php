<?php
declare(strict_types=1);

namespace NetForge\Content;

/**
 * One plain-text format for questions, shared by the question editor and CSV import
 * (FR-2.2, FR-2.3), so both accept exactly the same thing.
 *
 * Fields: type, prompt, choices, answer, topic, rating, explanation.
 * "choices" lists items one per line or separated by "|":
 *   mcq       choices: the options                 answer: number (1 = first) or the option's text
 *   matching  choices: "left = right" pairs        answer: (empty)
 *   ordering  choices: items in the correct order  answer: (empty)
 *   fill      prompt contains ___ for each blank   answer: one entry per blank; "/" separates accepted spellings
 *   blitz     choices: mode=bin2dec|dec2bin bits=4..8 seconds=5..120
 *   subnet    choices: mode=hosts|mask|network|broadcast min=8..30 max=8..30
 */
final class QuestionInput
{
    public const TYPES = ['mcq', 'matching', 'ordering', 'fill', 'blitz', 'subnet'];

    /**
     * @return array{ok:bool, errors:string[], row:array} row has prompt, topic, rating, explanation, type, payload (array), answer (?array)
     */
    public static function parse(array $f): array
    {
        $e = [];
        $type = strtolower(trim((string) ($f['type'] ?? '')));
        $prompt = trim((string) ($f['prompt'] ?? ''));
        $topic = trim((string) ($f['topic'] ?? ''));
        $explanation = trim((string) ($f['explanation'] ?? ''));
        $ratingRaw = trim((string) ($f['rating'] ?? ''));
        $rating = $ratingRaw === '' ? 1000 : (ctype_digit($ratingRaw) ? (int) $ratingRaw : -1);
        $choices = self::items((string) ($f['choices'] ?? ''));
        $answerRaw = trim((string) ($f['answer'] ?? ''));

        if (!in_array($type, self::TYPES, true)) {
            $e[] = 'Type must be one of: ' . implode(', ', self::TYPES) . '.';
        }
        if ($prompt === '') {
            $e[] = 'Write the question prompt.';
        } elseif (mb_strlen($prompt) > 1000) {
            $e[] = 'Keep the prompt under 1000 characters.';
        }
        if ($topic === '') {
            $e[] = 'Add a subtopic tag, for example "Subnetting".';
        } elseif (mb_strlen($topic) > 64) {
            $e[] = 'Keep the subtopic under 64 characters.';
        }
        if ($explanation === '') {
            $e[] = 'Add an explanation. Students see it after answering.';
        }
        if ($rating < 400 || $rating > 2000) {
            $e[] = 'Starting difficulty rating must be a whole number from 400 to 2000.';
        }

        $payload = [];
        $answer = null;
        switch ($type) {
            case 'mcq':
                if (count($choices) < 2 || count($choices) > 6) {
                    $e[] = 'Multiple choice needs 2 to 6 options.';
                    break;
                }
                if (count(array_unique(array_map('mb_strtolower', $choices))) !== count($choices)) {
                    $e[] = 'Each option must be different.';
                }
                $idx = null;
                if (ctype_digit($answerRaw) && (int) $answerRaw >= 1 && (int) $answerRaw <= count($choices)) {
                    $idx = (int) $answerRaw - 1;
                } else {
                    foreach ($choices as $i => $c) {
                        if (mb_strtolower($c) === mb_strtolower($answerRaw)) {
                            $idx = $i;
                        }
                    }
                }
                if ($idx === null) {
                    $e[] = 'Answer must be the number of the correct option (1 = first) or its exact text.';
                }
                $payload = ['options' => $choices];
                $answer = ['correct' => $idx ?? 0];
                break;

            case 'matching':
                $left = $right = [];
                foreach ($choices as $c) {
                    $parts = array_map('trim', explode('=', $c, 2));
                    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                        $e[] = "Write each matching pair as \"left = right\" (problem: \"$c\").";
                        continue;
                    }
                    [$left[], $right[]] = $parts;
                }
                if (count($left) < 2 || count($left) > 6) {
                    $e[] = 'Matching needs 2 to 6 pairs.';
                }
                if (count(array_unique(array_map('mb_strtolower', $right))) !== count($right)) {
                    $e[] = 'Each right-hand item must be different, or the answer is ambiguous.';
                }
                $payload = ['left' => $left, 'right' => $right];
                break;

            case 'ordering':
                if (count($choices) < 2 || count($choices) > 8) {
                    $e[] = 'Ordering needs 2 to 8 items, listed in the correct order.';
                }
                if (count(array_unique(array_map('mb_strtolower', $choices))) !== count($choices)) {
                    $e[] = 'Each item must be different.';
                }
                $payload = ['items' => $choices];
                break;

            case 'fill':
                $blanks = substr_count($prompt, '___');
                $given = self::items($answerRaw);
                if ($blanks < 1 || $blanks > 5) {
                    $e[] = 'Put 1 to 5 blanks in the prompt, each written as ___ (three underscores).';
                } elseif (count($given) !== $blanks) {
                    $e[] = "The prompt has $blanks blank(s) but the answer lists " . count($given) . '. Separate answers with |.';
                }
                $payload = ['text' => $prompt];
                $answer = ['blanks' => array_map(
                    fn ($g) => array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim($s)), explode('/', $g)), 'strlen')),
                    $given
                )];
                break;

            case 'blitz':
                $kv = self::settings($choices);
                $mode = $kv['mode'] ?? 'bin2dec';
                $bits = (int) ($kv['bits'] ?? 8);
                $secs = (int) ($kv['seconds'] ?? 20);
                if (!in_array($mode, ['bin2dec', 'dec2bin'], true)) {
                    $e[] = 'Binary Blitz mode must be bin2dec or dec2bin.';
                }
                if ($bits < 4 || $bits > 8) {
                    $e[] = 'Binary Blitz bits must be from 4 to 8.';
                }
                if ($secs < 5 || $secs > 120) {
                    $e[] = 'Binary Blitz seconds must be from 5 to 120.';
                }
                $payload = ['mode' => $mode, 'bits' => $bits, 'seconds' => $secs];
                break;

            case 'subnet':
                $kv = self::settings($choices);
                $mode = $kv['mode'] ?? 'hosts';
                $min = (int) ($kv['min'] ?? 24);
                $max = (int) ($kv['max'] ?? 30);
                if (!in_array($mode, ['hosts', 'mask', 'network', 'broadcast'], true)) {
                    $e[] = 'Subnet mode must be hosts, mask, network or broadcast.';
                }
                if ($min < 8 || $max > 30 || $min > $max) {
                    $e[] = 'Subnet prefixes need 8 ≤ min ≤ max ≤ 30.';
                }
                $payload = ['mode' => $mode, 'minPrefix' => $min, 'maxPrefix' => $max];
                break;
        }

        return [
            'ok' => $e === [],
            'errors' => $e,
            'row' => compact('type', 'prompt', 'topic', 'rating', 'explanation', 'payload', 'answer'),
        ];
    }

    /** Turns a stored question back into editable text fields (the inverse of parse). */
    public static function format(array $q): array
    {
        $p = json_decode((string) $q['payload'], true) ?: [];
        $a = $q['answer'] === null ? null : json_decode((string) $q['answer'], true);
        $choices = '';
        $answer = '';
        switch ($q['type']) {
            case 'mcq':
                $choices = implode("\n", $p['options'] ?? []);
                $answer = (string) (($a['correct'] ?? 0) + 1);
                break;
            case 'matching':
                $choices = implode("\n", array_map(fn ($l, $r) => "$l = $r", $p['left'] ?? [], $p['right'] ?? []));
                break;
            case 'ordering':
                $choices = implode("\n", $p['items'] ?? []);
                break;
            case 'fill':
                $answer = implode(' | ', array_map(fn ($alts) => implode(' / ', $alts), $a['blanks'] ?? []));
                break;
            case 'blitz':
                $choices = sprintf('mode=%s bits=%d seconds=%d', $p['mode'] ?? 'bin2dec', $p['bits'] ?? 8, $p['seconds'] ?? 20);
                break;
            case 'subnet':
                $choices = sprintf('mode=%s min=%d max=%d', $p['mode'] ?? 'hosts', $p['minPrefix'] ?? 24, $p['maxPrefix'] ?? 30);
                break;
        }
        return [
            'type' => $q['type'], 'prompt' => $q['prompt'], 'topic' => $q['topic'], 'rating' => (string) $q['rating'],
            'explanation' => $q['explanation'], 'choices' => $choices, 'answer' => $answer,
        ];
    }

    /** Splits a list on new lines or "|", trimming blanks. */
    public static function items(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|\|/', $s)), fn ($x) => $x !== ''));
    }

    /** "mode=network min=24 max=30" (spaces, commas or lines between) -> [key => value]. */
    private static function settings(array $items): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', implode(' ', $items)) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $out[strtolower(trim($k))] = strtolower(trim($v));
            }
        }
        return $out;
    }
}
