<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report as CSV or XLSX. Text that a spreadsheet would run as a
 * formula (starting with = + - @, tab or carriage return) is prefixed with
 * an apostrophe so it is shown as text.
 */
class ReportExporter
{
    public function stream(Report $report, Period $period, Filters $filters, string $format): StreamedResponse
    {
        $name = $report->key().($report->usesPeriod() ? "_{$period->from->toDateString()}_{$period->to->toDateString()}" : '_'.CarbonImmutable::now(Period::timezone())->toDateString());
        $columns = $report->columns();

        if ($format === 'xlsx') {
            return new StreamedResponse(function () use ($report, $period, $filters, $columns) {
                $writer = new Writer;
                $writer->openToFile('php://output');
                $writer->addRow(Row::fromValuesWithStyle(array_map(fn (Column $c) => $c->label, $columns), new Style(fontBold: true)));
                foreach ($report->rows($period, $filters) as $row) {
                    $writer->addRow(Row::fromValues(array_map(fn (Column $c) => $this->cell($c, $row[$c->key] ?? null, true), $columns)));
                }
                $writer->close();
            }, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        return new StreamedResponse(function () use ($report, $period, $filters, $columns) {
            $out = fopen('php://output', 'w');
            // Byte order mark so Excel opens UTF-8 (Nepali names) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn (Column $c) => $c->label, $columns), escape: '');
            foreach ($report->rows($period, $filters) as $row) {
                fputcsv($out, array_map(fn (Column $c) => $this->cell($c, $row[$c->key] ?? null, false), $columns), escape: '');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * A display value for previews: same formatting as CSV.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function preview(array $columns, array $row): array
    {
        $out = [];
        foreach ($columns as $c) {
            $value = $row[$c->key] ?? null;
            $out[$c->key] = match ($c->type) {
                'int', 'money', 'percent' => $value,
                'datetime', 'date' => $this->cell($c, $value, false),
                default => $value === null ? null : (string) $value,
            };
        }

        return $out;
    }

    public function cell(Column $column, mixed $value, bool $native): string|int|float|null
    {
        if ($value === null || $value === '') {
            return $native ? null : '';
        }

        return match ($column->type) {
            'int' => (int) $value,
            'money' => $native ? round(((int) $value) / 100, 2) : number_format(((int) $value) / 100, 2, '.', ''),
            'percent' => $native ? (float) $value : number_format((float) $value, 2, '.', ''),
            'datetime' => $this->local($value)->format('Y-m-d H:i'),
            'date' => $this->local($value)->format('Y-m-d'),
            default => self::safeText((string) $value),
        };
    }

    public static function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function local(mixed $value): CarbonImmutable
    {
        $at = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value, 'UTC');

        return $at->setTimezone(Period::timezone());
    }
}
