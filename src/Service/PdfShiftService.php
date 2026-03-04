<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PdfShiftService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $apiKey = null,
        private readonly string $processorVersion = '142',
        private readonly string $baseUrl = 'https://api.pdfshift.io/v3/convert/pdf'
    ) {
    }

    public function convertHtmlToPdf(string $html): string
    {
        $key = trim((string) $this->apiKey);
        if ($key === '') {
            throw new \RuntimeException('PDFShift API key is missing. Configure PDFSHIFT_API_KEY.');
        }

        $response = $this->httpClient->request('POST', $this->baseUrl, [
            'headers' => [
                'X-API-Key' => $key,
                'X-Processor-Version' => $this->processorVersion,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'source' => $html,
                'landscape' => false,
                'use_print' => false,
            ],
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('PDFShift request failed with status %d.', $status));
        }

        return $response->getContent();
    }
}

