<?php
declare(strict_types=1);

namespace NetForge\Learning;

/**
 * Turns a question row into what the student sees (serve) and marks answers (grade).
 * The answer key never leaves the server: serve() returns it separately as "key",
 * which the challenge run keeps in the PHP session.
 *
 * Row shapes (questions.payload / questions.answer):
 *   mcq       payload {"options":[...]}            answer {"correct":i}
 *   matching  payload {"left":[...],"right":[...]}  answer null (left[i] matches right[i])
 *   ordering  payload {"items":[...]}              answer null (items are in the right order)
 *   fill      payload {"text":"... ___ ..."}       answer {"blanks":[["accepted",...],...]}
 *   blitz     payload {"mode","bits","seconds"}     generated, see Generators::blitz
 *   subnet    payload {"mode","minPrefix","maxPrefix"} generated, see Generators::subnet
 */
final class Grader
{
    /** @return array{view:array, key:mixed, seconds:?int} */
    public static function serve(array $q): array
    {
        $p = json_decode($q['payload'], true);
        $a = $q['answer'] === null ? null : json_decode($q['answer'], true);
        $view = ['id' => (int) $q['id'], 'type' => $q['type'], 'topic' => $q['topic'], 'prompt' => $q['prompt']];

        switch ($q['type']) {
            case 'mcq':
                $order = self::shuffledKeys(count($p['options']));
                $view['options'] = array_map(fn ($i) => $p['options'][$i], $order);
                return ['view' => $view, 'key' => array_search($a['correct'], $order, true), 'seconds' => null];

            case 'matching':
                $order = self::shuffledKeys(count($p['right']));
                $view['left'] = $p['left'];
                $view['right'] = array_map(fn ($i) => $p['right'][$i], $order);
                // for left i, the correct choice is where right[i] landed
                $key = array_map(fn ($i) => array_search($i, $order, true), array_keys($p['left']));
                return ['view' => $view, 'key' => $key, 'seconds' => null];

            case 'ordering':
                $order = self::shuffledKeys(count($p['items']));
                $view['items'] = array_map(fn ($i) => $p['items'][$i], $order);
                // correct answer: shown positions listed in true order
                $key = array_map(fn ($i) => array_search($i, $order, true), array_keys($p['items']));
                return ['view' => $view, 'key' => $key, 'seconds' => null];

            case 'fill':
                $view['parts'] = explode('___', $p['text']);
                return ['view' => $view, 'key' => $a['blanks'], 'seconds' => null];

            case 'blitz':
            case 'subnet':
                $g = $q['type'] === 'blitz' ? Generators::blitz($p) : Generators::subnet($p);
                $view['prompt'] = $g['prompt'];
                $view['inputHint'] = $g['inputHint'];
                return ['view' => $view, 'key' => $g['key'], 'seconds' => $g['seconds']];
        }
        throw new \InvalidArgumentException('Unknown question type ' . $q['type']);
    }

    /**
     * @param array $view what serve() returned as view (needed to describe the right answer)
     * @return array{correct:bool, answerText:string}
     */
    public static function grade(string $type, array $view, mixed $key, mixed $answer): array
    {
        switch ($type) {
            case 'mcq':
                $ok = is_int($answer) && $answer === $key;
                return ['correct' => $ok, 'answerText' => $view['options'][$key]];

            case 'matching':
                $ok = is_array($answer) && array_map('intval', array_values($answer)) === $key && count($answer) === count($key);
                $text = implode('; ', array_map(fn ($l, $r) => $view['left'][$l] . ' → ' . $view['right'][$r], array_keys($key), $key));
                return ['correct' => $ok, 'answerText' => $text];

            case 'ordering':
                $ok = is_array($answer) && array_map('intval', array_values($answer)) === $key;
                return ['correct' => $ok, 'answerText' => implode(' → ', array_map(fn ($i) => $view['items'][$i], $key))];

            case 'fill':
                $ok = is_array($answer) && count($answer) === count($key);
                if ($ok) {
                    foreach ($key as $i => $accepted) {
                        $given = self::norm((string) ($answer[$i] ?? ''));
                        if (!in_array($given, array_map([self::class, 'norm'], $accepted), true)) {
                            $ok = false;
                            break;
                        }
                    }
                }
                return ['correct' => $ok, 'answerText' => implode(', ', array_map(fn ($acc) => $acc[0], $key))];

            case 'blitz':
                // leading zeros are optional: "0101" and "101" both count for binary 0101
                $given = preg_replace('/\s+/', '', (string) $answer);
                $ok = $given !== '' && ctype_digit($given) && ltrim($given, '0') === ltrim($key, '0');
                return ['correct' => $ok, 'answerText' => $key];

            case 'subnet':
                return ['correct' => self::norm((string) $answer) === $key, 'answerText' => $key];
        }
        return ['correct' => false, 'answerText' => ''];
    }

    public static function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** 0..n-1 in random order, never the original order when n > 1. */
    private static function shuffledKeys(int $n): array
    {
        $keys = range(0, $n - 1);
        if ($n < 2) {
            return $keys;
        }
        do {
            shuffle($keys);
        } while ($keys === range(0, $n - 1));
        return $keys;
    }
}
