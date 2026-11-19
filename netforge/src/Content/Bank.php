<?php
declare(strict_types=1);

namespace NetForge\Content;

use NetForge\Db;

/**
 * Loads a question bank file (database/content/ccna.php) into worlds, levels and questions.
 * Every question goes through QuestionInput, the same checks as the lecturer editor and CSV import.
 * Levels are matched by world and title, questions by level and prompt, so loading twice changes
 * nothing; an edited question keeps its live Elo rating and answer count.
 */
final class Bank
{
    public const FILE = NF_ROOT . '/database/content/ccna.php';

    public static function read(string $file = self::FILE): array
    {
        return require $file;
    }

    /**
     * Checks the whole bank. Returns every problem as "World n, level: message".
     * @return list<string>
     */
    public static function errors(array $bank): array
    {
        $errors = [];
        $titles = [];
        foreach ($bank as $world => $levels) {
            foreach ($levels as $l) {
                $where = "World $world, {$l['title']}";
                if (isset($titles[$world][$l['title']])) {
                    $errors[] = "$where: two levels have this title.";
                }
                $titles[$world][$l['title']] = true;
                if (trim($l['lesson'] ?? '') === '') {
                    $errors[] = "$where: the lesson is empty.";
                }
                if (count($l['questions']) < ($l['perRun'] ?? 5)) {
                    $errors[] = "$where: has fewer questions than a run asks (" . count($l['questions']) . " < {$l['perRun']}).";
                }
                $prompts = [];
                foreach ($l['questions'] as $i => $q) {
                    $parsed = QuestionInput::parse(self::fields($q));
                    foreach ($parsed['errors'] as $e) {
                        $errors[] = "$where, question " . ($i + 1) . ": $e";
                    }
                    if (isset($prompts[$q['prompt']])) {
                        $errors[] = "$where, question " . ($i + 1) . ': the same prompt appears twice.';
                    }
                    $prompts[$q['prompt']] = true;
                }
            }
        }
        return $errors;
    }

    /** Questions per CCNA blueprint domain, from the levels' domain tags. */
    public static function spread(array $bank): array
    {
        $out = [];
        foreach ($bank as $levels) {
            foreach ($levels as $l) {
                $out[$l['domain']] = ($out[$l['domain']] ?? 0) + count($l['questions']);
            }
        }
        return $out;
    }

    /**
     * Writes the bank to the database in one transaction. Call errors() first.
     * @return array{levels:int, added:int, updated:int}
     */
    public static function apply(array $bank): array
    {
        return Db::transaction(function () use ($bank): array {
            $n = ['levels' => 0, 'added' => 0, 'updated' => 0];
            foreach ($bank as $number => $levels) {
                $world = Db::one('SELECT id FROM worlds WHERE number = ?', [$number]);
                if ($world === null) {
                    throw new \RuntimeException("World $number doesn’t exist. Import database/schema_content.sql first.");
                }
                $worldId = (int) $world['id'];
                foreach ($levels as $l) {
                    $row = Db::one('SELECT id FROM levels WHERE world_id = ? AND title = ?', [$worldId, $l['title']]);
                    if ($row === null) {
                        Db::exec(
                            'INSERT INTO levels (world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES (?, ?, ?, ?, ?, ?)',
                            [$worldId, $l['sort'], $l['title'], trim($l['lesson']), $l['perRun'], $l['pass'] ?? 70]
                        );
                        $levelId = (int) Db::pdo()->lastInsertId();
                        $n['levels']++;
                    } else {
                        $levelId = (int) $row['id'];
                        Db::exec('UPDATE levels SET sort_order = ?, lesson = ?, questions_per_run = ?, archived = 0 WHERE id = ?',
                            [$l['sort'], trim($l['lesson']), $l['perRun'], $levelId]);
                    }
                    foreach ($l['questions'] as $q) {
                        $r = QuestionInput::parse(self::fields($q))['row'];
                        $payload = json_encode($r['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $answer = $r['answer'] === null ? null : json_encode($r['answer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $have = Db::one('SELECT id FROM questions WHERE level_id = ? AND prompt = ?', [$levelId, $r['prompt']]);
                        if ($have === null) {
                            Db::exec(
                                'INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$worldId, $levelId, $r['type'], $r['prompt'], $payload, $answer, $r['explanation'], $r['topic'], $r['rating']]
                            );
                            $n['added']++;
                        } else {
                            // keep the live rating and answer count; refresh the wording and key
                            Db::exec('UPDATE questions SET type = ?, payload = ?, answer = ?, explanation = ?, topic = ?, archived = 0 WHERE id = ?',
                                [$r['type'], $payload, $answer, $r['explanation'], $r['topic'], $have['id']]);
                            $n['updated']++;
                        }
                    }
                }
            }
            return $n;
        });
    }

    /** A bank entry as the text fields QuestionInput reads. */
    private static function fields(array $q): array
    {
        $answer = $q['answer'];
        if (is_array($answer)) { // fill: one entry per blank, each a list of accepted spellings
            $answer = implode("\n", array_map(fn ($b) => implode('/', (array) $b), $answer));
        }
        return [
            'type' => $q['type'], 'prompt' => $q['prompt'], 'choices' => implode("\n", $q['choices']),
            'answer' => (string) $answer, 'topic' => $q['topic'], 'rating' => (string) $q['rating'], 'explanation' => $q['explanation'],
        ];
    }
}
