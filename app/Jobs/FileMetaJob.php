<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\Str;
use RuntimeException;
use Smalot\PdfParser\Parser as PDFParser;

/**
 * Processes the metadata of the file, which retrieves document contents,
 * metadata and the number of pages.
 * @author Roelof Roos <github@roelof.io>
 * @license MPL-2.0
 */
class FileMetaJob extends FileJob
{
    /**
     * Try job 3 times
     * @var int
     */
    protected $tries = 3;

    /**
     * Allow 1 minute to get metadata
     * @var int
     */
    protected $timeout = 60;

    /**
     * Get the tags that should be assigned to the job.
     * @return array
     */
    public function tags(): array
    {
        return ['pdf-process', 'pdf-meta', 'file:' . $this->file->id];
    }

    /**
     * Execute the job.
     * @return void
     */
    public function handle(): void
    {
        // Make sure file is valid
        $file = $this->file;
        if (!$file) {
            return;
        }

        // Get a temporary file
        try {
            $tempFile = $this->getTempFileFromAttachment($this->file->file);
        } catch (RuntimeException $e) {
            return;
        }

        // Extract meta
        $this->getPdfContent($tempFile);

        // Get PDF metadata, using pdfinfo
        $this->getMetadata($tempFile);

        // Remve temp PDF
        $this->deleteTempFile($tempFile);

        // Save the proposed changes
        $file->save();
    }

    /**
     * Handle PDF content, which is extracted by the PDF parser.
     * @param string $filePath
     * @return void
     */
    protected function getPdfContent(string $filePath): void
    {
        // Load PDF parser
        $parser = new PDFParser();
        $pdf = $parser->parseFile($filePath);

        // Handle OCR contents
        $this->file->file_contents = $pdf->getText();
    }

    /**
     * Retrieves metadata from listed file using exiftool and
     * saves it to the file.
     * @param string $filePath
     * @return void
     */
    protected function getMetadata(string $filePath): void
    {
        // Build request list
        $requestList = collect([
            'PDF:all',
            'XMP-pdfaid:all',
            'XMP-pdf:all'
        ])->map(static fn ($value) => Str::start($value, '-'));

        // Build command. The structure is [exittool + commands] + [fields] + [filename].
        $command = array_merge(
            ['exiftool'],
            ['-a', '-G1', '-json'],
            $requestList->toArray(),
            [$filePath]
        );

        // Run meta command
        $ok = $this->runCliCommand($command, $stdout, $stderr);

        // Check if everything went OK
        if (!$ok) {
            logger()->notice('Failed to retrieve metadata from [{filename}].', [
                'filename' => $this->file->filename,
                'file' => $this->file,
                'output' => $stdout . PHP_EOL . $stderr,
            ]);

            // Abort
            return;
        }

        // Decode JSON
        $metaFields = json_decode($stdout);

        // Abort on error
        if (json_last_error() !== JSON_ERROR_NONE) {
            logger()->notice('Failed to parse JSON metadata for [{filename}].', [
                'filename' => $this->file->filename,
                'file' => $this->file,
                'output' => $stdout,
            ]);

            return;
        }

        // Serialize data into one-dimensional array
        foreach ($metaFields as $property => $value) {
            $value = implode(', ', array_wrap($value));
            $fileMeta->put($property, $value);
        }

        // Store meta
        $this->file->file_meta = $fileMeta;
    }
}
