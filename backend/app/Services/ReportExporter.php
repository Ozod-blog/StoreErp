<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/** Hisobotni Excel (.xlsx) yoki PDF qilib qaytaradi. Kerak paketlar: openspout/openspout, barryvdh/laravel-dompdf. */
class ReportExporter
{
    public function download(array $report, string $format, array $filterLabels = []): Response
    {
        $name = 'hisobot-'.$report['key'].'-'.now()->format('Ymd-His');

        return $format === 'pdf' ? $this->pdf($report, $filterLabels, $name) : $this->xlsx($report, $filterLabels, $name);
    }

    private function xlsx(array $report, array $filterLabels, string $name): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'rep');
        $writer = new Writer();
        $writer->openToFile($path);
        $bold = (new Style())->setFontBold();

        $writer->addRow(Row::fromValues([$report['title']], $bold));
        if ($report['period']) {
            $writer->addRow(Row::fromValues(['Davr: '.$report['period']['date_from'].' — '.$report['period']['date_to']]));
        }
        foreach ($filterLabels as $label => $value) {
            $writer->addRow(Row::fromValues([$label.': '.$value]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_map(fn ($c) => $c['label'], $report['columns']), $bold));

        foreach ($report['rows'] as $row) {
            $writer->addRow(Row::fromValues($this->cells($report['columns'], $row)));
        }
        if ($report['totals']) {
            $totals = $report['totals'];
            $cells = $this->cells($report['columns'], $totals);
            $cells[0] = 'JAMI';
            $writer->addRow(Row::fromValues($cells, $bold));
        }
        $writer->close();

        return response()->download($path, $name.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Excelda sonlar son bo'lib chiqishi uchun: pul/miqdor/foiz ustunlari float. Matn qiymatlari matn bo'lib qoladi (formula emas). */
    private function cells(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $v = $row[$c['key']] ?? null;
            if ($v === null) {
                return '';
            }

            return in_array($c['type'], ['money', 'qty', 'percent'], true) ? (float) $v : (in_array($c['type'], ['int'], true) ? (int) $v : (string) $v);
        }, $columns);
    }

    private function pdf(array $report, array $filterLabels, string $name): Response
    {
        return Pdf::loadView('reports.table', ['report' => $report, 'filters' => $filterLabels])
            ->setPaper('a4', 'landscape')
            ->download($name.'.pdf');
    }
}
