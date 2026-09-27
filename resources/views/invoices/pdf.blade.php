{{--
  The downloadable invoice, rendered by App\Services\InvoicePdfRenderer.

  Three mPDF constraints shape the markup:

  1. Every Latin-script value (phone, email, part number, reference, money)
     is wrapped in an inline <span dir="ltr">. Left to itself the bidi
     algorithm reorders a run like "123, 12, Erbil" into "Erbil ,12 ,123"
     inside an RTL paragraph, because the commas between the digit groups
     inherit the paragraph direction. Putting `dir` on the *block* does not
     fix it — it left-aligns the whole line inside an otherwise right-aligned
     column instead. Only the inline span both isolates the run and keeps the
     line's alignment.

  2. Layout is tables with width attributes, not floats or flex, and padding
     goes on table cells rather than inline-blocks. mPDF's CSS support stops
     short of the rest, and a float silently collapses the row it was meant
     to arrange.

  3. `letter-spacing` is applied to Latin only ($track below). Arabic and
     Sorani are cursive: spacing the glyphs apart severs the joins, so
     "الفاتورة" came out as six disconnected letters and the stamp's "دراوە"
     as "درئاوئه". This holds inside the SVG stamp as well as in the CSS.

  The stamp is inline SVG because that is the only element mPDF will rotate
  by an arbitrary angle — it ignores CSS `transform`, and its `text-rotate`
  applies to table cells and turns the text without the border that frames
  it. SVG text goes through the same shaping and font substitution as the
  rest of the document, so all three scripts set correctly inside it.
--}}
@php
    $ltr = fn (?string $value): string => '<span dir="ltr">'.e($value).'</span>';
    $money = \App\Support\InvoiceFormat::money(...);
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $track = fn (string $size): string => $rtl ? '' : 'letter-spacing: '.$size.';';
@endphp
<!DOCTYPE html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: sans-serif; font-size: 10.5pt; color: #202326; line-height: 1.45; }
  td { vertical-align: top; }

  /* Masthead ------------------------------------------------------------ */
  table.head { width: 100%; border-collapse: collapse; }
  table.head td { padding: 0; border: 0; }
  .brand-name { margin: 0 0 3pt; font-size: 15pt; font-weight: bold; }
  .brand p { margin: 1pt 0; color: #697179; font-size: 9pt; }
  /* mPDF applies a cell's text-align to everything inside it and ignores a
     nested block's own, so the masthead's right-hand column is aligned on
     the <td> and the meta lines are plain paragraphs rather than a second
     table — a table would have split the column in half and left a gap
     between each label and its value. */
  td.head-meta { text-align: {{ $end }}; }
  .doctype { margin: 0 0 6pt; font-size: 20pt; font-weight: bold; color: #0b835e;
             {{ $track('1pt') }} {{ $rtl ? '' : 'text-transform: uppercase;' }} }
  .meta-line { margin: 1.5pt 0; font-size: 9pt; font-weight: bold; }
  .k { color: #8a9199; font-weight: normal; }
  .rule { height: 1.6pt; background: #202326; margin: 9pt 0 11pt; font-size: 0; line-height: 0; }

  /* Bill-to / payment panels -------------------------------------------- */
  table.panels { width: 100%; border-collapse: separate; border-spacing: 7pt 0; margin-bottom: 14pt; }
  td.panel { padding: 9pt 11pt; background: #f7f8f6; border: 0.6pt solid #e9ebe8; }
  .panel-title { margin: 0 0 5pt; font-size: 7.5pt; font-weight: bold; color: #8a9199;
                 {{ $track('0.6pt') }} {{ $rtl ? '' : 'text-transform: uppercase;' }} }
  .who { margin: 0 0 4pt; font-size: 11pt; font-weight: bold; }
  table.pairs { width: 100%; border-collapse: collapse; }
  table.pairs td { padding: 1pt 0; font-size: 9.5pt; border: 0; }
  /* nowrap only, no width: a percentage wrapped "Amount due" onto two lines
     in the narrower payment panel, and the width:1% shrink-to-fit idiom
     collapsed the enclosing panels table entirely — mPDF resolves the nested
     table's width against the outer cell before the outer cell has one. */
  table.pairs td.k { white-space: nowrap; padding-{{ $end }}: 10pt; }

  /* Line items ----------------------------------------------------------- */
  table.items { width: 100%; border-collapse: collapse; }
  table.items th { padding: 6pt; font-size: 7.5pt; font-weight: bold; color: #4a5158;
                   background: #eceeea; border-bottom: 1pt solid #d5d9d3; text-align: {{ $start }};
                   {{ $track('0.5pt') }} {{ $rtl ? '' : 'text-transform: uppercase;' }} }
  table.items td { padding: 6.5pt 6pt; font-size: 9.5pt; border-bottom: 0.6pt solid #e9ebe8;
                   text-align: {{ $start }}; }
  table.items th.num, table.items td.num { text-align: {{ $end }}; }
  table.items th.mid, table.items td.mid { text-align: center; }
  table.items tr.alt td { background: #f8faf6; }
  td.sku { font-family: monospace; font-size: 9pt; color: #4a5158; }

  /* Totals --------------------------------------------------------------- */
  table.totals { width: 100%; border-collapse: collapse; margin-top: 9pt; }
  table.totals td { padding: 3.5pt 0; font-size: 9.5pt; border: 0;
                    text-align: {{ $end }}; white-space: nowrap; }
  table.totals td.k { color: #697179; padding-{{ $end }}: 14pt; }
  table.totals tr.grand td { padding-top: 6pt; border-top: 1.2pt solid #202326;
                             font-size: 12.5pt; font-weight: bold; color: #202326; }

  /* Closing matter -------------------------------------------------------- */
  .notes { margin: 12pt 0 0; padding: 8pt 10pt; background: #f7f8f6;
           border-{{ $start }}: 2pt solid #d5d9d3; color: #4a5158; font-size: 9pt; }
  .notes .lead { font-weight: bold; color: #202326; }
  table.closing { width: 100%; border-collapse: collapse; margin-top: 15pt;
                  border-top: 0.6pt dashed #d5d9d3; }
  table.closing > tbody > tr > td { padding: 12pt 0 0; border: 0; vertical-align: middle;
                                    font-size: 9.5pt; color: #4a5158; }
  .stamp { text-align: {{ $end }}; }
  .legal { margin: 9pt 0 0; font-size: 8pt; color: #a2a8ae; text-align: center; }
</style>
</head>
<body>

{{-- Masthead --}}
<table class="head">
  <tr>
    <td class="brand" width="50%">
      <div class="brand-name">{{ $t('Masdar Al-Adawat') }}</div>
      <p>{{ $address }}</p>
      <p>{!! $ltr($phone) !!}</p>
    </td>
    <td class="head-meta" width="50%">
      <div class="doctype">{{ $t('Invoice') }}</div>
      <p class="meta-line"><span class="k">{{ $t('Invoice no.') }}</span> {!! $ltr($order->reference.'-INV') !!}</p>
      <p class="meta-line"><span class="k">{{ $t('Order reference') }}</span> {!! $ltr($order->reference) !!}</p>
      <p class="meta-line"><span class="k">{{ $t('Date') }}</span> {{ $issuedDate }}</p>
    </td>
  </tr>
</table>

<div class="rule"></div>

{{-- Who it is for, and how it was settled. Side by side so the page answers
     both questions without the reader hunting for them. --}}
<table class="panels">
  <tr>
    <td class="panel" width="57%">
      <div class="panel-title">{{ $t('Billed & delivered to') }}</div>
      <p class="who">{{ $order->customer_name }}</p>
      <table class="pairs">
        <tr><td class="k">{{ $t('Phone') }}</td><td>{!! $ltr($order->customer_phone) !!}</td></tr>
        @if ($order->customer_email)
          <tr><td class="k">{{ $t('Email') }}</td><td>{!! $ltr($order->customer_email) !!}</td></tr>
        @endif
        <tr><td class="k">{{ $t('Address') }}</td><td>{!! $ltr($order->address_line.', '.$order->city) !!}</td></tr>
      </table>
    </td>
    <td class="panel" width="43%">
      <div class="panel-title">{{ $t('Payment method') }}</div>
      <p class="who">{{ $paymentMethod }}</p>
      <table class="pairs">
        @if ($paidAt)
          <tr><td class="k">{{ $t('Paid on') }}</td><td>{{ $paidAt }}</td></tr>
        @endif
        <tr><td class="k">{{ $t('Amount due') }}</td><td>{!! $ltr($money(0)) !!} {{ $t('IQD') }}</td></tr>
      </table>
    </td>
  </tr>
</table>

{{-- The <thead> repeats automatically wherever mPDF breaks the table across
     pages, so a second page of parts is still readable on its own. --}}
<table class="items">
  <thead>
    <tr>
      <th width="19%">{{ $t('Part No.') }}</th>
      <th width="39%">{{ $t('Product') }}</th>
      <th class="mid" width="10%">{{ $t('Quantity') }}</th>
      <th class="num" width="16%">{{ $t('Price') }}</th>
      <th class="num" width="16%">{{ $t('Line total') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($order->items as $item)
      <tr class="{{ $loop->index % 2 ? 'alt' : '' }}">
        <td class="sku">{!! $ltr($item->ref) !!}</td>
        <td>{{ $item->name }}</td>
        <td class="mid">{!! $ltr((string) $item->quantity) !!}</td>
        <td class="num">{!! $ltr($money($item->unit_price)) !!}</td>
        <td class="num">{!! $ltr($money($item->line_total)) !!}</td>
      </tr>
    @endforeach
  </tbody>
</table>

{{-- The empty first cell parks the totals under the money columns instead of
     floating them there. --}}
<table class="totals">
  <tr>
    <td width="58%"></td>
    <td class="k" width="26%">{{ $t('Items') }}</td>
    <td width="16%">{!! $ltr((string) $order->items->sum('quantity')) !!}</td>
  </tr>
  <tr>
    <td></td>
    <td class="k">{{ $t('Subtotal') }}</td>
    <td>{!! $ltr($money($order->subtotal)) !!}</td>
  </tr>
  <tr class="grand">
    <td></td>
    <td class="k">{{ $t('Total') }}</td>
    <td>{!! $ltr($money($order->total)) !!} {{ $t('IQD') }}</td>
  </tr>
</table>

@if ($order->notes)
  {{-- The note follows the customer's own script, not the invoice language:
       an English note on an Arabic invoice otherwise has its full stop
       carried to the head of the line by the bidi algorithm. --}}
  <p class="notes"><span class="lead">{{ $t('Order notes (optional)') }}:</span>
    @if ($rtl && ! \App\Support\InvoiceFormat::isArabicScript($order->notes))
      {!! $ltr($order->notes) !!}
    @else
      {{ $order->notes }}
    @endif
  </p>
@endif

{{-- The "paid" mark sits beside the thank-you rather than on its own line: at
     full width it pushed the closing matter down the page and left the
     invoice looking like it had been cut short. --}}
<table class="closing">
  <tr>
    <td width="65%">{{ $t('Thank you for your order.') }}</td>
    {{-- lang="und" on the cell, not a mistake: under lang="ku" mPDF applies a
         Kurdish OpenType feature set that its SVG text path mishandles, and
         the stamp's "دراوە" comes out as "درئاوئه". Undetermined sends it
         back to plain script detection, which sets all three scripts right;
         the document itself stays tagged with its real language. --}}
    <td class="stamp" width="35%" lang="und">
      {{-- The viewBox has to hold the frame's *rotated* footprint, not its
           upright one. A 206×74 box turned 8° sweeps out 214×102, and the
           6-unit stroke adds 3 more on every side — so a viewBox drawn to
           the upright size clips the two corners that swing outside it.
           240×120 around a centre of (120,60) leaves a margin all round. --}}
      <svg width="160" height="80" viewBox="0 0 240 120">
        <g transform="rotate(-8 120 60)">
          <rect x="17" y="23" width="206" height="74" rx="13" fill="none" stroke="#0b835e" stroke-width="6"/>
          {{-- letter-spacing would sever the joins in the cursive scripts. --}}
          <text x="120" y="74" text-anchor="middle" font-size="36" font-weight="bold" fill="#0b835e"
                @if (! $rtl) letter-spacing="3" @endif>{{ $rtl ? $t('Paid') : mb_strtoupper($t('Paid'), 'UTF-8') }}</text>
        </g>
      </svg>
    </td>
  </tr>
</table>

<p class="legal">{{ $t('This invoice was issued electronically and is valid without a signature.') }}</p>

</body>
</html>
