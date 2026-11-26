<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cpanel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCpanelIsStaffOnly;
use App\Support\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports `/cpanel` finance, PPN 11%, promo ROI, and settlement reports
 * as UTF-8 BOM CSV (Excel-safe) or native `.xlsx` via `openspout/openspout`.
 */
final class FinanceExportController extends Controller
{
    public function __invoke(Request $request, FinanceReportService $service): Response
    {
        abort_unless(
            EnsureCpanelIsStaffOnly::userIsStaff($request->user()) && Gate::allows('finance-dashboard'),
            403,
        );

        $tab = (string) $request->query('tab', 'margin');
        $format = strtolower((string) $request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $from = Carbon::parse((string) ($request->query('from') ?: now()->subDays(29)->toDateString()));
        $to = Carbon::parse((string) ($request->query('to') ?: now()->toDateString()));

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        [$headers, $rows] = match ($tab) {
            'ppn' => [
                ['Invoice', 'No Order', 'Tanggal Bayar', 'Pelanggan', 'DPP', 'PPN 11%', 'Total'],
                array_map(
                    static fn (array $r): array => [
                        $r['invoice_number'],
                        $r['order_number'],
                        $r['paid_at'],
                        $r['customer'],
                        $r['dpp'],
                        $r['ppn'],
                        $r['total'],
                    ],
                    $service->ppnReport($from, $to)['rows'],
                ),
            ],
            'promo' => [
                ['Kode Promo', 'Pesanan Dibayar', 'Total Diskon', 'GMV Teratribusi', 'Margin Bersih'],
                array_map(
                    static fn (array $r): array => [
                        $r['code'],
                        $r['orders'],
                        $r['total_discount'],
                        $r['attributed_gmv'],
                        $r['net_margin'],
                    ],
                    $service->promoRoi($from, $to)['rows'],
                ),
            ],
            'settlement' => [
                ['Driver', 'Metode Pembayaran', 'Pesanan', 'Bruto', 'Biaya Gateway', 'Net Settlement'],
                array_map(
                    static fn (array $r): array => [
                        $r['driver'],
                        $r['method'],
                        $r['orders'],
                        $r['gross'],
                        $r['gateway_fee'],
                        $r['net_settlement'],
                    ],
                    $service->settlementAndCod($from, $to)['channels'],
                ),
            ],
            default => [
                ['Invoice', 'No Order', 'Tanggal Bayar', 'Pelanggan', 'Bruto', 'Diskon', 'Ongkir', 'COGS', 'Refund', 'Biaya Gateway', 'Net Revenue', 'Gross Profit', 'Margin %'],
                array_map(
                    static fn (array $r): array => [
                        $r['invoice_number'],
                        $r['order_number'],
                        $r['paid_at'],
                        $r['customer'],
                        $r['gross'],
                        $r['discount'],
                        $r['shipping'],
                        $r['cogs'],
                        $r['refunds'],
                        $r['gateway_fee'],
                        $r['net_revenue'],
                        $r['gross_profit'],
                        $r['margin_pct'],
                    ],
                    $service->profitAndMargin($from, $to)['rows'],
                ),
            ],
        };

        $filename = sprintf('oceanmall-%s-%s-to-%s.%s', $tab, $from->format('Ymd'), $to->format('Ymd'), $format);

        if ($format === 'xlsx') {
            $tmpPath = tempnam(sys_get_temp_dir(), 'om-finance-').'.xlsx';
            $writer = new Writer;
            $writer->openToFile($tmpPath);
            $writer->addRow(Row::fromValues($headers));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();

            return response()->download($tmpPath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(static function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
