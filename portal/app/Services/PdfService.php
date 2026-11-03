<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    /** Render a Blade view to PDF bytes. Remote files are disabled; only the public folder can be read. */
    public function render(string $view, array $data, string $paper = 'A4', string $orientation = 'portrait'): string
    {
        // Large broadsheets need more than PHP's usual 128 MB; raise it for this request only.
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes($limit) < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

        $cache = storage_path('app/dompdf');
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', [public_path()]);
        $options->set('tempDir', $cache);
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render());
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    private function bytes(string $value): int
    {
        $n = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
