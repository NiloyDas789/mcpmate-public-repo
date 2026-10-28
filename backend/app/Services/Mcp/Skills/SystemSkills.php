<?php

namespace McpMate\Services\Mcp\Skills;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * McpMate's own skills: tested playbooks for known kinds of work, shipped
 * as Markdown files in skills/ and read from disk per request
 * (docs/mcp-in-plugin.md §8; the cloud's src/skills/catalog.ts). Each file
 * starts with a front matter block:
 *
 *     ---
 *     name: elementor-pages
 *     description: One line, shown by list_skills.
 *     ---
 *     # Body in Markdown, returned by get_skill
 *
 * A malformed file is left out (SystemSkillsTest fails on it), so a broken
 * playbook never reaches a model.
 */
final class SystemSkills
{
    /** Lowercase words joined by hyphens, like the file names in skills/. */
    public const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public const MAX_NAME_LENGTH = 64;

    public const MAX_DESCRIPTION_LENGTH = 200;

    /** One get_skill answer carries the whole body, so it stays well under the response cap. */
    public const MAX_BODY_BYTES = 16000;

    /**
     * The files in skills/, by name. Static, so the instructions and tool
     * descriptions can name them; SystemSkillsTest checks the folder holds exactly these.
     */
    public const NAMES = ['gutenberg-pages', 'elementor-pages', 'theme-building', 'seo-audit', 'safe-bulk-changes'];

    /** @var string|null Override of the folder (tests). */
    private static ?string $dir = null;

    public static function useDirectory(?string $dir): void
    {
        self::$dir = $dir;
    }

    public static function directory(): string
    {
        return self::$dir ?? Config::get('ROOT_DIR') . 'skills';
    }

    /**
     * Every playbook, by name.
     *
     * @return array<int, array{owner: string, siteId: null, name: string, description: string, updatedAt: string, body: string}>
     */
    public static function all(): array
    {
        $files = glob(self::directory() . DIRECTORY_SEPARATOR . '*.md');
        $files = is_array($files) ? $files : [];
        sort($files);

        $skills = [];
        foreach ($files as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file shipped with the plugin.
            $skill = self::parse(basename($file), (string) file_get_contents($file));

            if (is_array($skill)) {
                $skills[] = $skill + ['updatedAt' => gmdate('Y-m-d\TH:i:s', (int) filemtime($file)) . '.000Z'];
            }
        }

        usort($skills, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_map(static fn (array $skill): array => ['owner' => 'system', 'siteId' => null] + $skill, $skills);
    }

    /**
     * @return array{name: string, description: string, body: string}|string The skill, or what is wrong with the file.
     */
    public static function parse(string $fileName, string $text)
    {
        if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', str_replace("\r\n", "\n", $text), $match)) {
            return $fileName . ': missing front matter (--- name / description ---)';
        }

        $fields = [];
        foreach (explode("\n", $match[1]) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                return $fileName . ': front matter line without "key: value": ' . $line;
            }

            $fields[trim((string) substr($line, 0, $colon))] = trim((string) substr($line, $colon + 1));
        }

        $skill   = ['name' => $fields['name'] ?? '', 'description' => $fields['description'] ?? '', 'body' => trim($match[2])];
        $problem = self::problem($skill['name'], $skill['description'], $skill['body']);

        if ($problem !== null) {
            return $fileName . ': ' . $problem;
        }

        return $skill['name'] . '.md' === $fileName ? $skill : $fileName . ': name "' . $skill['name'] . '" must match the file name';
    }

    /**
     * What makes a skill unusable, or null. The same limits apply to playbooks and save_skill.
     */
    public static function problem(string $name, string $description, string $body): ?string
    {
        if (!preg_match(self::NAME, $name) || strlen($name) > self::MAX_NAME_LENGTH) {
            return 'name must be lowercase words joined by hyphens, at most ' . self::MAX_NAME_LENGTH . ' characters';
        }

        $length = mb_strlen($description, 'UTF-8');
        if ($length === 0 || $length > self::MAX_DESCRIPTION_LENGTH || preg_match('/[\r\n]/', $description)) {
            return 'description must be one line of 1 to ' . self::MAX_DESCRIPTION_LENGTH . ' characters';
        }

        if (trim($body) === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return 'body must be 1 to ' . self::MAX_BODY_BYTES . ' bytes of Markdown';
        }

        return null;
    }
}
