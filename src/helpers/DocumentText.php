<?php

namespace cstudiossro\craftcschatbot\helpers;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Plain text out of an uploaded document.
 *
 * Price lists, catalogues, manuals and terms are what customers actually have
 * lying around, and they are almost never plain text. Reading a PDF's bytes
 * into the index — which is what happened before — produced binary noise that
 * embedded into nothing and quietly polluted retrieval.
 */
class DocumentText
{
    /** Extensions this can read, for upload validation and the file picker. */
    public const SUPPORTED = ['txt', 'md', 'pdf', 'docx'];

    /**
     * Where the optional PDF tools usually live. The queue worker that does the
     * indexing inherits a minimal PATH — often just /usr/bin:/bin — so a
     * Homebrew or /usr/local install is invisible to a bare command name.
     * The bare name is still tried last, for installs somewhere else on PATH.
     */
    private const TOOL_PATHS = [
        'pdftotext' => ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'],
        'qpdf' => ['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'],
    ];

    /**
     * A line this long or longer that repeats this often across a PDF is a
     * running header, footer or watermark, not content. Both thresholds are
     * deliberately conservative: keeping some boilerplate costs a little
     * retrieval precision, dropping a real repeated value — a class name, a
     * standard's number — costs an answer.
     */
    private const REPEAT_MIN_OCCURRENCES = 5;
    private const REPEAT_MIN_LENGTH = 15;

    public static function isSupported(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED, true);
    }

    /**
     * @throws RuntimeException when the file cannot be read as text
     */
    public static function extract(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('File missing: ' . $path);
        }
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => self::fromPdf($path),
            'docx' => self::fromDocx($path),
            default => (string)file_get_contents($path),
        };
    }

    private static function fromPdf(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException('PDF support needs the smalot/pdfparser package.');
        }
        try {
            $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
        } catch (Throwable $e) {
            if (!self::isEncryptionError($e)) {
                throw new RuntimeException('Could not read the PDF: ' . $e->getMessage(), 0, $e);
            }
            // Most "secured" PDFs in circulation — supplier catalogues, price
            // lists, standards — carry an owner password only: they open
            // without one and their own permission flags allow extraction.
            // The parser refuses all of them alike, so try the tools that read
            // what the file already permits.
            $text = self::fromSecuredPdf($path);
        }
        $text = self::tidy(self::stripRepeatedLines($text));
        if ($text === '') {
            // A scanned document is a picture of text; there is nothing to index
            // and saying so beats indexing an empty source that looks trained.
            throw new RuntimeException('No text in this PDF — it is probably a scan, which needs OCR first.');
        }
        return $text;
    }

    /**
     * Read a PDF the parser rejected as encrypted.
     *
     * Nothing here supplies, guesses or strips a password. A file that needs a
     * user password to open fails in both tools and lands on the message
     * below — which is the correct outcome, since the uploader is the one who
     * can legitimately unlock it.
     */
    private static function fromSecuredPdf(string $path): string
    {
        $pdftotext = self::tool('pdftotext');
        $qpdf = self::tool('qpdf');

        // Poppler reads the text directly, and handles the encryption itself.
        $stdout = null;
        if ($pdftotext !== null && self::run([$pdftotext, '-q', '-enc', 'UTF-8', $path, '-'], $stdout)) {
            if (trim((string)$stdout) !== '') {
                return (string)$stdout;
            }
        }

        // Otherwise rewrite it without the encryption layer and re-parse.
        if ($qpdf !== null) {
            $temporary = tempnam(sys_get_temp_dir(), 'cschatbot-pdf-') ?: null;
            if ($temporary !== null) {
                try {
                    $ignored = null;
                    if (self::run([$qpdf, '--decrypt', $path, $temporary], $ignored)) {
                        $text = (new \Smalot\PdfParser\Parser())->parseFile($temporary)->getText();
                        if (trim($text) !== '') {
                            return $text;
                        }
                    }
                } catch (Throwable) {
                    // fall through to the message below
                } finally {
                    @unlink($temporary);
                }
            }
        }

        // Two very different failures reach this point, and an admin can only
        // act on the one they actually have. Saying "re-save it without its
        // password" to someone whose file has no password reads as nonsense and
        // sends them looking in the wrong place.
        if ($pdftotext === null && $qpdf === null) {
            throw new RuntimeException(
                'This PDF is encrypted, and the tool that would read it is not installed on this server. '
                . 'Most such files carry an owner password only and open fine — they just need '
                . 'poppler-utils (pdftotext) or qpdf present. Install either one and train this file again. '
                . 'On DDEV, add `poppler-utils` to `webimage_extra_packages` in .ddev/config.yaml.'
            );
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                'This PDF is encrypted, and PHP on this server cannot run the tool that would read it: '
                . 'proc_open is disabled. Ask the host to allow it, or upload an unencrypted copy.'
            );
        }
        throw new RuntimeException(
            'This PDF is encrypted and could not be opened, which usually means it needs a password to open '
            . '(not merely an owner password). Open it with the password, re-save it without one, and upload that.'
        );
    }

    /**
     * True when the parser bailed out on encryption rather than on damage.
     */
    private static function isEncryptionError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'secured pdf')
            || str_contains($message, 'secured file')
            || str_contains($message, 'encrypt');
    }

    /**
     * Absolute path to a PDF tool, or null when it is not installed.
     *
     * Whether it was found decides which failure the admin is told about, so
     * PATH is probed too rather than handing back a bare name that only fails
     * later and looks the same as a file that needs a password.
     */
    private static function tool(string $name): ?string
    {
        foreach (self::TOOL_PATHS[$name] ?? [] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        $found = null;
        if (self::run(['/usr/bin/env', 'which', $name], $found) && trim((string)$found) !== '') {
            return trim((string)$found);
        }
        return null;
    }

    /**
     * Run a command and capture its stdout. Array form, so there is no shell
     * and therefore nothing to escape. Returns false when the binary is
     * missing, process spawning is disabled, or the command failed.
     *
     * @param list<string> $command
     */
    private static function run(array $command, ?string &$stdout): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        // Nothing is piped in; close it so a tool that reads stdin sees EOF
        // instead of waiting on the queue worker forever.
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        // Drain stderr too, or a chatty tool blocks on a full pipe.
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return proc_close($process) === 0;
    }

    /**
     * Drop the running headers, footers and watermarks a PDF repeats on every
     * page.
     *
     * They are the most common text in the document, so left in they embed into
     * every chunk and match every question a little — the same way site chrome
     * does when a page is indexed whole. A standard's catalogue URL stamped on
     * all forty pages is the clearest case: it says nothing, and it is the one
     * string guaranteed to appear in every chunk retrieval compares.
     */
    private static function stripRepeatedLines(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        if (count($lines) < self::REPEAT_MIN_OCCURRENCES * 2) {
            return $text;
        }

        $counts = [];
        foreach ($lines as $line) {
            $key = self::repeatKey($line);
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $kept = [];
        foreach ($lines as $line) {
            $key = self::repeatKey($line);
            if (
                $key !== ''
                && ($counts[$key] ?? 0) >= self::REPEAT_MIN_OCCURRENCES
                && mb_strlen($key) >= self::REPEAT_MIN_LENGTH
            ) {
                continue;
            }
            $kept[] = $line;
        }
        return implode("\n", $kept);
    }

    /**
     * Normalized form a line is counted under — whitespace collapsed, so the
     * same header laid out differently on two pages still counts as one.
     */
    private static function repeatKey(string $line): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $line));
    }

    private static function fromDocx(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('DOCX support needs the PHP zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('That DOCX has no document body.');
        }
        // Paragraph and line breaks become newlines so the chunker still sees
        // structure; every other tag goes.
        $xml = preg_replace('#<w:(p|br)\b[^>]*/?>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = self::tidy($text);
        if ($text === '') {
            throw new RuntimeException('No text in this DOCX.');
        }
        return $text;
    }

    private static function tidy(string $text): string
    {
        $text = preg_replace('/\R/u', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
