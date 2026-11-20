<?php
declare(strict_types=1);

namespace NetForge;

/**
 * The tutorial's characters (names and portrait art, editable by staff), each student's
 * tutorial progress, and the background music list. Uploads are checked by their real
 * content, not their file name.
 */
final class Story
{
    /** Portraits live in the database; MySQL's default 1 MB packet limit leaves room for 700 KB. */
    public const MAX_ART_BYTES = 700 * 1024;
    public const ART_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    /** Music files are kept on disk in storage/music/ (too big for the database). */
    public const MAX_MUSIC_BYTES = 30 * 1024 * 1024;
    public const MUSIC_TYPES = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/webm' => 'webm', 'video/webm' => 'webm',
    ];

    /* ---------- characters ---------- */

    /** Every character, with the address of its art (or null: the game draws a built-in portrait). */
    public static function characters(): array
    {
        return array_map(fn ($c) => [
            'code' => $c['code'], 'name' => $c['name'], 'role' => $c['role'], 'side' => $c['side'],
            'art' => $c['has_art'] ? 'api/art.php?c=' . rawurlencode($c['code']) . '&v=' . strtotime((string) $c['art_updated']) : null,
        ], Db::all('SELECT code, name, role, side, art IS NOT NULL has_art, art_updated FROM characters ORDER BY side, code'));
    }

    public static function art(string $code): ?array
    {
        return Db::one('SELECT art, art_mime, art_updated FROM characters WHERE code = ? AND art IS NOT NULL', [$code]);
    }

    public static function updateCharacter(string $code, string $name, string $role, string $side): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $role = trim(preg_replace('/\s+/u', ' ', $role));
        if ($name === '' || mb_strlen($name) > 40 || preg_match('/[<>]/', $name)) {
            throw new GameError('Give the character a name, up to 40 characters, without < or >.');
        }
        if (mb_strlen($role) > 80 || preg_match('/[<>]/', $role)) {
            throw new GameError('Keep the role under 80 characters, without < or >.');
        }
        if (!in_array($side, ['left', 'right'], true)) {
            throw new GameError('Pick which side they stand on.');
        }
        if (Db::exec('UPDATE characters SET name = ?, role = ?, side = ? WHERE code = ?', [$name, $role, $side, $code]) === 0
            && Db::one('SELECT 1 FROM characters WHERE code = ?', [$code]) === null) {
            throw new GameError('That character doesn’t exist.');
        }
    }

    /** Saves an uploaded portrait ($_FILES entry) after checking it really is a small image. */
    public static function saveArt(string $code, array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new GameError('Choose an image to upload.');
        }
        if ($file['size'] > self::MAX_ART_BYTES) {
            throw new GameError('That image is over 700 KB. Use a smaller one (a 256×256 PNG is plenty).');
        }
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!isset(self::ART_TYPES[$mime]) || $info[0] < 16 || $info[1] < 16 || $info[0] > 4096 || $info[1] > 4096) {
            throw new GameError('Upload a PNG, JPEG, GIF or WebP image between 16 and 4096 pixels across.');
        }
        $bytes = file_get_contents($file['tmp_name']);
        if (Db::exec('UPDATE characters SET art = ?, art_mime = ?, art_updated = NOW() WHERE code = ?', [$bytes, $mime, $code]) === 0) {
            throw new GameError('That character doesn’t exist.');
        }
    }

    public static function removeArt(string $code): void
    {
        Db::exec('UPDATE characters SET art = NULL, art_mime = NULL, art_updated = NOW() WHERE code = ?', [$code]);
    }

    /* ---------- tutorial progress ---------- */

    public static function tutorial(int $userId): array
    {
        $u = Db::one('SELECT tutorial_step, tutorial_done FROM users WHERE id = ?', [$userId]);
        return ['step' => (int) ($u['tutorial_step'] ?? 0), 'done' => (bool) ($u['tutorial_done'] ?? 0)];
    }

    /** Moves the tutorial on (never back, except restart). */
    public static function setTutorial(int $userId, string $action, int $step = 0): array
    {
        match ($action) {
            'step'    => Db::exec('UPDATE users SET tutorial_step = GREATEST(tutorial_step, ?) WHERE id = ?', [max(0, min(500, $step)), $userId]),
            'done'    => Db::exec('UPDATE users SET tutorial_done = 1 WHERE id = ?', [$userId]),
            'restart' => Db::exec('UPDATE users SET tutorial_done = 0, tutorial_step = 0 WHERE id = ?', [$userId]),
            default   => throw new GameError('Unknown tutorial action.'),
        };
        return self::tutorial($userId);
    }

    /* ---------- music ---------- */

    /** Tracks in play order. Staff see disabled ones too. */
    public static function tracks(bool $all = false): array
    {
        return array_map(fn ($t) => [
            'id' => (int) $t['id'], 'kind' => $t['kind'], 'synth' => $t['synth_key'], 'title' => $t['title'],
            'artist' => $t['artist'], 'licence' => $t['licence'], 'enabled' => (bool) $t['enabled'], 'sort' => (int) $t['sort_order'],
            'bytes' => $t['bytes'] === null ? null : (int) $t['bytes'],
            'url' => $t['kind'] === 'file' ? 'api/music.php?id=' . (int) $t['id'] : null,
        ], Db::all('SELECT * FROM music_tracks' . ($all ? '' : ' WHERE enabled = 1') . ' ORDER BY sort_order, id'));
    }

    public static function musicDir(): string
    {
        $dir = NF_ROOT . '/storage/music';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Adds an uploaded music file after checking it really is audio. */
    public static function addTrack(array $file, string $title, string $artist, string $licence): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new GameError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is bigger than the server allows.' : 'Choose a music file to upload.');
        }
        if ($file['size'] > self::MAX_MUSIC_BYTES) {
            throw new GameError('That file is over 30 MB. Use a shorter or more compressed track (MP3 or OGG).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::MUSIC_TYPES[$mime])) {
            throw new GameError('Upload an MP3, OGG, WAV, M4A or WebM audio file.');
        }
        $title = trim($title);
        $licence = trim($licence);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new GameError('Give the track a title, up to 80 characters.');
        }
        if ($licence === '' || mb_strlen($licence) > 200) {
            throw new GameError('Say where the track is from and why it’s free to use (for example "CC0 from freepd.com").');
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::MUSIC_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], self::musicDir() . '/' . $name)) {
            throw new GameError('The file couldn’t be saved on the server.');
        }
        $sort = (int) (Db::one('SELECT COALESCE(MAX(sort_order), 0) + 10 n FROM music_tracks')['n']);
        Db::exec(
            'INSERT INTO music_tracks (kind, title, artist, licence, file, mime, bytes, sort_order) VALUES ("file", ?, ?, ?, ?, ?, ?, ?)',
            [$title, mb_substr(trim($artist), 0, 80), $licence, $name, $mime, (int) $file['size'], $sort]
        );
        return (int) Db::pdo()->lastInsertId();
    }

    public static function updateTrack(int $id, string $title, string $artist, int $sort, bool $enabled): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new GameError('Give the track a title, up to 80 characters.');
        }
        Db::exec('UPDATE music_tracks SET title = ?, artist = ?, sort_order = ?, enabled = ? WHERE id = ?',
            [$title, mb_substr(trim($artist), 0, 80), max(0, min(10000, $sort)), (int) $enabled, $id]);
    }

    /** Deletes an uploaded track and its file. Built-in tracks can only be switched off. */
    public static function deleteTrack(int $id): void
    {
        $t = Db::one('SELECT kind, file FROM music_tracks WHERE id = ?', [$id]);
        if ($t === null) {
            throw new GameError('That track doesn’t exist.');
        }
        if ($t['kind'] !== 'file') {
            throw new GameError('Built-in tracks can’t be deleted, but you can switch them off.');
        }
        Db::exec('DELETE FROM music_tracks WHERE id = ?', [$id]);
        $path = self::musicDir() . '/' . basename((string) $t['file']);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function trackFile(int $id): ?array
    {
        $t = Db::one('SELECT file, mime FROM music_tracks WHERE id = ? AND kind = "file"', [$id]);
        if ($t === null) {
            return null;
        }
        $path = self::musicDir() . '/' . basename((string) $t['file']);
        return is_file($path) ? ['path' => $path, 'mime' => $t['mime']] : null;
    }
}
