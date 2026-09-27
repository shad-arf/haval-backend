<?php

namespace App\Support;

/**
 * Mirrors the invoice-related strings in the frontend's src/i18n.js so the
 * downloaded PDF reads in whichever language the customer was browsing in.
 */
class InvoiceLabels
{
    private const LABELS = [
        'ar' => [
            'Masdar Al-Adawat' => 'شركة مصدر الأدوات',
            'North Industrial Area, Erbil' => 'المنطقة الصناعية الشمالية، أربيل',
            'Invoice' => 'الفاتورة',
            'Paid' => 'مدفوعة',
            'Invoice no.' => 'رقم الفاتورة',
            'Order reference' => 'رقم الطلب',
            'Date' => 'التاريخ',
            'Billed & delivered to' => 'الفاتورة والتوصيل إلى',
            'Part No.' => 'رقم القطعة',
            'Product' => 'المنتج',
            'Quantity' => 'الكمية',
            'Price' => 'السعر',
            'Subtotal' => 'المجموع الفرعي',
            'Total' => 'الإجمالي',
            'IQD' => 'د.ع',
            'Order notes (optional)' => 'ملاحظات الطلب (اختياري)',
            'Thank you for your order.' => 'شكراً لطلبك.',
            'Line total' => 'مجموع البند',
            'Items' => 'المواد',
            'Amount due' => 'المبلغ المستحق',
            'Payment method' => 'طريقة الدفع',
            'Paid on' => 'تاريخ الدفع',
            'Cash on delivery' => 'الدفع عند الاستلام',
            'FIB card' => 'بطاقة FIB',
            'FIB QR' => 'رمز FIB',
            'Phone' => 'الهاتف',
            'Address' => 'العنوان',
            'Email' => 'البريد الإلكتروني',
            'Page' => 'صفحة',
            'of' => 'من',
            'This invoice was issued electronically and is valid without a signature.' => 'صدرت هذه الفاتورة إلكترونياً وهي صالحة بدون توقيع.',
        ],
        'ku' => [
            'Masdar Al-Adawat' => 'کۆمپانیای مەسدەری ئەدەوات',
            'North Industrial Area, Erbil' => 'ناوچەی پیشەسازی باکوور، هەولێر',
            'Invoice' => 'پسووڵە',
            'Paid' => 'دراوە',
            'Invoice no.' => 'ژمارەی پسووڵە',
            'Order reference' => 'ژمارەی داواکاری',
            'Date' => 'بەروار',
            'Billed & delivered to' => 'پسووڵە و گەیاندن بۆ',
            'Part No.' => 'ژمارەی پارچە',
            'Product' => 'بەرهەم',
            'Quantity' => 'بڕ',
            'Price' => 'نرخ',
            'Subtotal' => 'کۆی لاوەکی',
            'Total' => 'کۆی گشتی',
            'IQD' => 'د.ع',
            'Order notes (optional)' => 'تێبینی داواکاری (ئارەزوومەندانە)',
            'Thank you for your order.' => 'سوپاس بۆ داواکارییەکەت.',
            'Line total' => 'کۆی ڕیز',
            'Items' => 'کاڵاکان',
            'Amount due' => 'بڕی پێویست',
            'Payment method' => 'شێوازی پارەدان',
            'Paid on' => 'بەرواری پارەدان',
            'Cash on delivery' => 'پارەدان لە کاتی گەیاندن',
            'FIB card' => 'کارتی FIB',
            'FIB QR' => 'کۆدی FIB',
            'Phone' => 'تەلەفۆن',
            'Address' => 'ناونیشان',
            'Email' => 'ئیمەیڵ',
            'Page' => 'لاپەڕە',
            'of' => 'لە',
            'This invoice was issued electronically and is valid without a signature.' => 'ئەم پسووڵەیە بە شێوەی ئەلیکترۆنی دەرچووە و بێ واژوو بەکاردێت.',
        ],
    ];

    public static function get(string $lang, string $key): string
    {
        return self::LABELS[$lang][$key] ?? $key;
    }
}
