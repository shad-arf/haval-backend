<?php

namespace App\Services;

use App\Models\Order;
use App\Support\InvoiceFormat;
use App\Support\InvoiceLabels;
use App\Support\SiteSettings;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders the invoice PDF shared by the customer-facing download
 * (CheckoutController::invoice, gated by the order's access key) and the
 * admin dashboard (AdminShopController::orderInvoice, gated by the admin
 * session instead).
 *
 * mPDF is used rather than dompdf because it reshapes and reorders Arabic
 * and Kurdish (Sorani) glyphs correctly; dompdf renders both as
 * disconnected, visually-reversed letters.
 */
class InvoicePdfRenderer
{
    /**
     * Payment methods as they should read on paper. The raw column values
     * ('cod', 'fib_qr') are internal and were never meant for a customer.
     */
    private const METHOD_LABELS = [
        Order::METHOD_COD => 'Cash on delivery',
        Order::METHOD_FIB_CARD => 'FIB card',
        Order::METHOD_FIB_QR => 'FIB QR',
    ];

    public function render(Order $order, string $lang): string
    {
        $t = static fn (string $key): string => InvoiceLabels::get($lang, $key);
        $site = SiteSettings::all();
        $rtl = $lang !== 'en';
        $issuedAt = $order->paid_at ?? $order->created_at;

        $html = view('invoices.pdf', [
            'order' => $order,
            't' => $t,
            'rtl' => $rtl,
            'lang' => $lang,
            'issuedDate' => InvoiceFormat::date($issuedAt, $lang),
            'paidAt' => $order->paid_at !== null ? InvoiceFormat::dateTime($order->paid_at, $lang) : null,
            'paymentMethod' => $t(self::METHOD_LABELS[$order->payment_method] ?? $order->payment_method),
            'phone' => $site['phone_number'],
            'address' => $site['store_address'] !== '' ? $site['store_address'] : $t('North Industrial Area, Erbil'),
        ])->render();

        $mpdf = new Mpdf([
            'format' => 'A4',
            'default_font_size' => 10.5,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            // Room for the running footer, which mPDF draws inside the bottom
            // margin rather than in the content area.
            'margin_bottom' => 20,
            'margin_footer' => 8,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');

        // Shown in the viewer's title bar and in "document properties", which
        // is all most people see of an attachment before opening it.
        $mpdf->SetTitle($t('Invoice').' '.$order->reference.'-INV');
        $mpdf->SetAuthor($t('Masdar Al-Adawat'));
        $mpdf->SetSubject($t('Order reference').' '.$order->reference);
        $mpdf->SetCreator('Masdar Al-Adawat');

        $mpdf->SetHTMLFooter($this->footer($order, $t, $rtl));
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * A running footer, so a one-page invoice ends in a line of type instead
     * of half a page of blank paper, and a long one is still traceable to its
     * order after the pages are separated.
     *
     * {PAGENO} and {nbpg} are substituted by mPDF at output time.
     */
    private function footer(Order $order, callable $t, bool $rtl): string
    {
        $align = $rtl ? 'right' : 'left';
        $opposite = $rtl ? 'left' : 'right';
        $brand = e($t('Masdar Al-Adawat'));
        $reference = e($order->reference);
        $pages = e($t('Page')).' {PAGENO} '.e($t('of')).' {nbpg}';

        return '<table width="100%" style="font-size: 8pt; color: #8a9199; border-top: 0.6pt solid #e3e6e5; padding-top: 3mm;"><tr>'
            .'<td style="text-align: '.$align.';">'.$brand.' &middot; <span dir="ltr">'.$reference.'-INV</span></td>'
            .'<td style="text-align: '.$opposite.';">'.$pages.'</td>'
            .'</tr></table>';
    }
}
