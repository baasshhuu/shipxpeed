@extends('layouts.sellerdash')

@section('content')
@php
    $sellerId = auth('seller')->id();
    $q        = trim((string) request('awb', ''));
    $tracking = null;
    $notFound = false;

    if ($q !== '') {
        $order = \App\Services\Tracking\ShipmentTracker::findOrder($q, $sellerId);
        if ($order) {
            $tracking = app(\App\Services\Tracking\ShipmentTracker::class)->track($order);
            $cons = is_array($order->consignee) ? $order->consignee : (json_decode((string) $order->consignee, true) ?: []);
            $tracking['customer']    = $cons['name'] ?? '';
            $tracking['destination'] = trim(($cons['city'] ?? '') . (!empty($cons['pincode']) ? ' – ' . $cons['pincode'] : ''), ' –');
            $tracking['payment']     = strtoupper((string) $order->payment_type);
            $tracking['amount']      = $order->collectable_amount ?: $order->order_amount;
            $tracking['booked_on']   = optional($order->created_at)->format('d M Y');
        } else {
            $notFound = true;
        }
    }

    $recent = \App\Models\Order::where('seller_id', $sellerId)
        ->whereNotNull('awb_number')->where('awb_number', '!=', '')
        ->latest('id')->take(8)
        ->get(['id', 'order_number', 'awb_number', 'courier_id', 'all_courier_name', 'shipping_status', 'updated_at']);

    $pill = function ($s) {
        return match (strtolower((string) $s)) {
            'delivered' => 'ok',
            'out for delivery' => 'warn',
            'transit', 'in transit', 'picked up', 'in_transit' => 'blue',
            'ndr', 'rto', 'rto delivered', 'cancelled', 'lost' => 'bad',
            default => 'muted',
        };
    };
    $label = fn ($s) => \App\Services\Tracking\ShipmentTracker::STATUS_LABELS[$s] ?? ucwords((string) ($s ?: 'Pending'));
    $courierName = function ($o) {
        $k = \App\Services\Tracking\ShipmentTracker::trackerKey($o);
        return $k ? \App\Services\Tracking\ShipmentTracker::COURIER_NAMES[$k] : ($o->all_courier_name ?: ucfirst((string) $o->courier_id));
    };

    $steps = ['Booked', 'Picked up', 'In transit', 'Out for delivery', 'Delivered'];
    $st = $tracking['status'] ?? '';
    $reached = match ($st) { 'picked up' => 1, 'transit', 'NDR' => 2, 'out for delivery' => 3, 'delivered' => 4, default => 0 };
    $exception = in_array($st, ['NDR', 'rto', 'rto delivered', 'cancelled', 'lost'], true);
@endphp

<style>
    .trk { --r: 14px; color: var(--sx-fg, #e9ecf5); max-width: 1180px; margin: 0 auto; padding: 8px 4px 40px; }
    .trk * { box-sizing: border-box; }
    .trk-title { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .trk-title h1 { font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--sx-fg, #e9ecf5); letter-spacing: -.01em; }
    .trk-title p { margin: 4px 0 0; color: var(--sx-fg-2, #a3abc2); font-size: .92rem; }
    .trk-panel { background: var(--sx-surface, #0f1626); border: 1px solid var(--sx-line, rgba(196,206,235,.09)); border-radius: var(--r); }
    .trk-search { padding: 18px; display: flex; gap: 10px; align-items: center; }
    .trk-field { flex: 1; display: flex; align-items: center; gap: 10px; background: var(--sx-raised, #152036); border: 1px solid var(--sx-line-2, rgba(196,206,235,.16)); border-radius: 11px; padding: 0 14px; height: 50px; transition: border-color .15s, box-shadow .15s; }
    .trk-field:focus-within { border-color: var(--sx-blue, #3b7bff); box-shadow: 0 0 0 3px var(--sx-blue-bg, rgba(59,123,255,.14)); }
    .trk-field svg { flex: none; color: var(--sx-fg-3, #6d7690); }
    .trk-field input { flex: 1; background: transparent; border: 0; outline: 0; color: var(--sx-fg, #e9ecf5); font-size: 1rem; height: 100%; min-width: 0; }
    .trk-field input::placeholder { color: var(--sx-fg-3, #6d7690); }
    .trk-btn { height: 50px; padding: 0 22px; border-radius: 11px; border: 0; background: var(--sx-blue, #3b7bff); color: #fff; font-weight: 600; font-size: .95rem; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; white-space: nowrap; }
    .trk-btn:hover { filter: brightness(1.08); }
    .trk-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 18px 16px; align-items: center; }
    .trk-chips span { color: var(--sx-fg-3, #6d7690); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; margin-right: 4px; }
    .trk-chip { font-size: .82rem; color: var(--sx-fg-2, #a3abc2); background: var(--sx-raised, #152036); border: 1px solid var(--sx-line, rgba(196,206,235,.09)); padding: 5px 10px; border-radius: 999px; text-decoration: none; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .trk-chip:hover { color: var(--sx-fg, #e9ecf5); border-color: var(--sx-blue, #3b7bff); }

    .pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; white-space: nowrap; }
    .pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .pill.ok { color: var(--sx-ok, #5fd3a1); background: var(--sx-ok-bg, rgba(95,211,161,.12)); }
    .pill.warn { color: var(--sx-warn, #f2b45a); background: var(--sx-warn-bg, rgba(242,180,90,.13)); }
    .pill.blue { color: var(--sx-blue-2, #6b9cff); background: var(--sx-blue-bg, rgba(59,123,255,.14)); }
    .pill.bad { color: var(--sx-bad, #ff7b7b); background: var(--sx-bad-bg, rgba(255,123,123,.12)); }
    .pill.muted { color: var(--sx-fg-2, #a3abc2); background: var(--sx-raised, #152036); }
    .pill.lg { font-size: .9rem; padding: 7px 14px; }

    .trk-result { margin-top: 18px; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 18px; }
    .trk-head { padding: 20px 22px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 1px solid var(--sx-line, rgba(196,206,235,.09)); flex-wrap: wrap; }
    .trk-k { font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; color: var(--sx-fg-3, #6d7690); font-weight: 600; }
    .trk-awb { display: flex; align-items: center; gap: 8px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.25rem; font-weight: 600; margin-top: 4px; }
    .trk-copy { background: var(--sx-raised, #152036); border: 1px solid var(--sx-line, rgba(196,206,235,.09)); color: var(--sx-fg-2, #a3abc2); border-radius: 8px; padding: 4px 8px; font-size: .75rem; cursor: pointer; font-family: inherit; }
    .trk-copy:hover { color: var(--sx-fg, #e9ecf5); }
    .trk-courier { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; color: var(--sx-fg-2, #a3abc2); font-size: .9rem; }
    .trk-courier b { width: 26px; height: 26px; border-radius: 7px; display: grid; place-items: center; background: var(--sx-blue-bg, rgba(59,123,255,.14)); color: var(--sx-blue-2, #6b9cff); font-size: .8rem; }

    .trk-steps { display: grid; grid-template-columns: repeat(5, 1fr); padding: 22px 22px 8px; }
    .trk-step { text-align: center; position: relative; font-size: .76rem; color: var(--sx-fg-3, #6d7690); }
    .trk-step i { display: grid; place-items: center; width: 26px; height: 26px; margin: 0 auto 8px; border-radius: 50%; background: var(--sx-raised, #152036); border: 2px solid var(--sx-line-2, rgba(196,206,235,.16)); position: relative; z-index: 1; font-style: normal; font-size: .7rem; }
    .trk-step::before { content: ''; position: absolute; top: 12px; right: 50%; width: 100%; height: 2px; background: var(--sx-line-2, rgba(196,206,235,.16)); }
    .trk-step:first-child::before { display: none; }
    .trk-step.done { color: var(--sx-fg, #e9ecf5); }
    .trk-step.done i { background: var(--sx-ok, #5fd3a1); border-color: var(--sx-ok, #5fd3a1); color: #0a0f1c; }
    .trk-step.done::before { background: var(--sx-ok, #5fd3a1); }
    .trk-step.now i { box-shadow: 0 0 0 5px var(--sx-ok-bg, rgba(95,211,161,.12)); }
    .trk-alert { margin: 14px 22px 0; padding: 12px 14px; border-radius: 10px; font-size: .9rem; display: flex; gap: 10px; align-items: flex-start; }
    .trk-alert.bad { background: var(--sx-bad-bg, rgba(255,123,123,.12)); color: var(--sx-bad, #ff7b7b); }
    .trk-alert.warn { background: var(--sx-warn-bg, rgba(242,180,90,.13)); color: var(--sx-warn, #f2b45a); }

    .trk-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: var(--sx-line, rgba(196,206,235,.09)); margin-top: 18px; border-top: 1px solid var(--sx-line, rgba(196,206,235,.09)); border-radius: 0 0 var(--r) var(--r); overflow: hidden; }
    .trk-grid > div { background: var(--sx-surface, #0f1626); padding: 14px 22px; }
    .trk-grid .v { margin-top: 4px; color: var(--sx-fg, #e9ecf5); font-weight: 500; word-break: break-word; }

    .trk-tl { padding: 20px 22px; }
    .trk-tl h3 { font-size: 1rem; font-weight: 700; margin: 0 0 16px; display: flex; justify-content: space-between; align-items: center; color: var(--sx-fg, #e9ecf5); }
    .trk-tl h3 small { font-weight: 500; color: var(--sx-fg-3, #6d7690); font-size: .78rem; }
    .trk-ev { position: relative; padding: 0 0 20px 26px; }
    .trk-ev::before { content: ''; position: absolute; left: 5px; top: 14px; bottom: -2px; width: 2px; background: var(--sx-line-2, rgba(196,206,235,.16)); }
    .trk-ev:last-child::before { display: none; }
    .trk-ev::after { content: ''; position: absolute; left: 0; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: var(--sx-raised, #152036); border: 2px solid var(--sx-fg-3, #6d7690); }
    .trk-ev:first-of-type::after { background: var(--sx-ok, #5fd3a1); border-color: var(--sx-ok, #5fd3a1); box-shadow: 0 0 0 4px var(--sx-ok-bg, rgba(95,211,161,.12)); }
    .trk-ev .m { color: var(--sx-fg, #e9ecf5); font-size: .93rem; line-height: 1.4; }
    .trk-ev .s { color: var(--sx-fg-3, #6d7690); font-size: .8rem; margin-top: 3px; }
    .trk-ev:nth-of-type(n+7) { display: none; }
    .trk-tl.all .trk-ev { display: block; }
    .trk-more { background: none; border: 0; color: var(--sx-blue-2, #6b9cff); font-weight: 600; cursor: pointer; padding: 0; font-size: .88rem; }

    .trk-empty { padding: 44px 22px; text-align: center; color: var(--sx-fg-2, #a3abc2); }
    .trk-empty .ic { width: 52px; height: 52px; margin: 0 auto 12px; border-radius: 14px; display: grid; place-items: center; background: var(--sx-raised, #152036); color: var(--sx-fg-3, #6d7690); }
    .trk-empty b { display: block; color: var(--sx-fg, #e9ecf5); font-size: 1rem; margin-bottom: 4px; }

    .trk-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .trk-table th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; color: var(--sx-fg-3, #6d7690); font-weight: 600; padding: 12px 18px; border-bottom: 1px solid var(--sx-line, rgba(196,206,235,.09)); }
    .trk-table td { padding: 13px 18px; border-bottom: 1px solid var(--sx-line, rgba(196,206,235,.09)); color: var(--sx-fg-2, #a3abc2); }
    .trk-table tr:last-child td { border-bottom: 0; }
    .trk-table tr:hover td { background: var(--sx-hover, #1a2742); }
    .trk-table .mono { white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--sx-fg, #e9ecf5); }
    .trk-link { color: var(--sx-blue-2, #6b9cff); font-weight: 600; text-decoration: none; }
    .trk-sec { padding: 16px 18px; border-bottom: 1px solid var(--sx-line, rgba(196,206,235,.09)); font-weight: 700; color: var(--sx-fg, #e9ecf5); }

    @media (max-width: 991px) { .trk-result { grid-template-columns: 1fr; } }
    @media (max-width: 575px) {
        .trk-search { flex-direction: column; align-items: stretch; }
        .trk-btn { justify-content: center; }
        .trk-field { flex: none; }
        .trk-steps { padding: 18px 10px 4px; }
        .trk-step { font-size: .64rem; }
        .trk-grid { grid-template-columns: 1fr; }
        .trk-table .hide-sm { display: none; }
    }
</style>

<div class="pc-content">
<div class="trk">
    <div class="trk-title">
        <div>
            <h1>Track shipments</h1>
            <p>Live status from every courier, by AWB number or Order ID.</p>
        </div>
    </div>

    {{-- Search --}}
    <div class="trk-panel">
        <form class="trk-search" method="GET" action="{{ url()->current() }}">
            <label class="trk-field">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="text" name="awb" value="{{ $q }}" placeholder="Enter AWB number or Order ID" autocomplete="off" required autofocus>
            </label>
            <button class="trk-btn" type="submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>
                Track
            </button>
        </form>
        @if($recent->isNotEmpty())
            <div class="trk-chips">
                <span>Recent</span>
                @foreach($recent->take(4) as $r)
                    <a class="trk-chip" href="?awb={{ urlencode($r->awb_number) }}">{{ $r->awb_number }}</a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Not found --}}
    @if($notFound)
        <div class="trk-panel" style="margin-top:18px">
            <div class="trk-empty">
                <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8.5 8.5l5 5m0-5-5 5"/></svg></div>
                <b>No shipment found for “{{ $q }}”</b>
                Check the AWB number or Order ID. Only shipments from your account are shown.
            </div>
        </div>
    @endif

    {{-- Result --}}
    @if($tracking)
        <div class="trk-result">
            <div class="trk-panel">
                <div class="trk-head">
                    <div>
                        <div class="trk-k">AWB number</div>
                        <div class="trk-awb">
                            {{ $tracking['awb'] }}
                            <button type="button" class="trk-copy" onclick="navigator.clipboard.writeText('{{ $tracking['awb'] }}');this.textContent='Copied'">Copy</button>
                        </div>
                        <div class="trk-courier"><b>{{ strtoupper(substr($tracking['courier'], 0, 1)) }}</b>{{ $tracking['courier'] }}</div>
                    </div>
                    <span class="pill lg {{ $pill($st) }}">{{ $tracking['status_label'] ?: 'Pending' }}</span>
                </div>

                @if(!$exception)
                    <div class="trk-steps">
                        @foreach($steps as $i => $s)
                            <div class="trk-step {{ $i <= $reached ? 'done' : '' }} {{ $i === $reached ? 'now' : '' }}">
                                <i>{!! $i <= $reached ? '&#10003;' : $i + 1 !!}</i>{{ $s }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="trk-alert bad">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                        <div>
                            @if($st === 'NDR') Delivery attempt failed. Take action from the <a class="trk-link" href="{{ url('/seller/reverse-order') }}">NDR</a> page to re-attempt or return.
                            @elseif(in_array($st, ['rto', 'rto delivered'])) This shipment is being returned to your pickup address.
                            @elseif($st === 'cancelled') This shipment was cancelled.
                            @else The courier reported a problem with this shipment. Raise a support ticket if you need help.
                            @endif
                        </div>
                    </div>
                @endif

                @if(!empty($tracking['error']))
                    <div class="trk-alert warn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
                        <div>{{ $tracking['error'] }}</div>
                    </div>
                @endif

                <div class="trk-grid">
                    <div><div class="trk-k">Order</div><div class="v">{{ $tracking['order_number'] ?: '—' }}</div></div>
                    <div><div class="trk-k">Expected delivery</div><div class="v">{{ $tracking['edd'] ? \Illuminate\Support\Str::limit($tracking['edd'], 16, '') : '—' }}</div></div>
                    <div><div class="trk-k">Customer</div><div class="v">{{ $tracking['customer'] ?: '—' }}</div></div>
                    <div><div class="trk-k">Destination</div><div class="v">{{ $tracking['destination'] ?: '—' }}</div></div>
                    <div><div class="trk-k">Payment</div><div class="v">{{ $tracking['payment'] ?: '—' }}@if($tracking['payment'] === 'COD' && $tracking['amount']) · ₹{{ number_format((float) $tracking['amount'], 2) }}@endif</div></div>
                    <div><div class="trk-k">Booked on</div><div class="v">{{ $tracking['booked_on'] ?: '—' }}</div></div>
                </div>
            </div>

            <div class="trk-panel trk-tl" id="trkTl">
                <h3>Tracking history <small>{{ count($tracking['events']) }} updates</small></h3>
                @forelse($tracking['events'] as $e)
                    <div class="trk-ev">
                        <div class="m">{{ $e['status'] ?: '—' }}</div>
                        <div class="s">{{ $e['time'] }}@if(!empty($e['location'])) · {{ $e['location'] }}@endif</div>
                    </div>
                @empty
                    <div class="trk-empty" style="padding:28px 0">
                        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                        <b>No scans yet</b>
                        Updates usually appear a few hours after pickup.
                    </div>
                @endforelse
                @if(count($tracking['events']) > 6)
                    <button type="button" class="trk-more" onclick="document.getElementById('trkTl').classList.add('all');this.remove()">Show all {{ count($tracking['events']) }} updates</button>
                @endif
            </div>
        </div>
    @endif

    {{-- Recent shipments --}}
    @if(!$tracking)
        <div class="trk-panel" style="margin-top:18px">
            <div class="trk-sec">Recent shipments</div>
            @if($recent->isEmpty())
                <div class="trk-empty">
                    <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg></div>
                    <b>No shipments yet</b>
                    Once you ship an order, it will appear here for quick tracking.
                </div>
            @else
                <div style="overflow-x:auto">
                    <table class="trk-table">
                        <thead><tr><th>Order</th><th>AWB</th><th class="hide-sm">Courier</th><th>Status</th><th class="hide-sm">Updated</th><th></th></tr></thead>
                        <tbody>
                        @foreach($recent as $r)
                            <tr>
                                <td class="mono">{{ $r->order_number }}</td>
                                <td class="mono">{{ $r->awb_number }}</td>
                                <td class="hide-sm">{{ $courierName($r) }}</td>
                                <td><span class="pill {{ $pill($r->shipping_status) }}">{{ $label($r->shipping_status) }}</span></td>
                                <td class="hide-sm">{{ optional($r->updated_at)->diffForHumans() }}</td>
                                <td style="text-align:right"><a class="trk-link" href="?awb={{ urlencode($r->awb_number) }}">Track →</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
</div>
@endsection
