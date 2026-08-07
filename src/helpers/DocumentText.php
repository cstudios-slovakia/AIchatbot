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
            throw new RuntimeException('Could not read the PDF: ' . $e->getMessage(), 0, $e);
        }
        $text = self::tidy($text);
        if ($text === '') {
            // A scanned document is a picture of text; there is nothing to index
            // and saying so beats indexing an empty source that looks trained.
            throw new RuntimeException('No text in this PDF — it is probably a scan, which needs OCR first.');
        }
        return $text;
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
