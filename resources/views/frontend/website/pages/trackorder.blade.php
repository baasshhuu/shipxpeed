@extends('frontend.website.layout.index')

@section('main_contant')
<style>
    .trk-wrap { max-width: 900px; margin: 0 auto; }
    .trk-card { background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); overflow: hidden; border: 1px solid #eef0f4; }
    .trk-head { padding: 20px 24px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; border-bottom: 1px solid #eef0f4; }
    .trk-head h5 { margin: 0; font-weight: 700; color: #0f172a; }
    .trk-courier { font-size: .8rem; font-weight: 600; padding: 4px 12px; border-radius: 999px; background: #eef2ff; color: #4338ca; text-transform: uppercase; letter-spacing: .4px; }
    .trk-status { font-weight: 700; padding: 6px 14px; border-radius: 999px; font-size: .9rem; }
    .st-delivered { background: #dcfce7; color: #166534; }
    .st-transit, .st-picked { background: #dbeafe; color: #1e40af; }
    .st-ofd { background: #fef3c7; color: #92400e; }
    .st-ndr, .st-rto, .st-cancelled, .st-lost { background: #fee2e2; color: #991b1b; }
    .st-default { background: #f1f5f9; color: #334155; }
    .trk-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; padding: 20px 24px; }
    .trk-item { background: #f8fafc; border-radius: 10px; padding: 12px 14px; }
    .trk-label { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: #64748b; font-weight: 600; margin-bottom: 4px; }
    .trk-value { color: #0f172a; font-weight: 600; word-break: break-all; }
    .trk-steps { display: flex; justify-content: space-between; padding: 8px 24px 22px; gap: 6px; }
    .trk-step { flex: 1; text-align: center; font-size: .75rem; color: #94a3b8; position: relative; }
    .trk-step .dot { width: 14px; height: 14px; border-radius: 50%; background: #e2e8f0; margin: 0 auto 6px; position: relative; z-index: 1; }
    .trk-step::before { content: ''; position: absolute; top: 6px; left: -50%; width: 100%; height: 2px; background: #e2e8f0; }
    .trk-step:first-child::before { display: none; }
    .trk-step.done { color: #0f172a; font-weight: 600; }
    .trk-step.done .dot, .trk-step.done::before { background: #22c55e; }
    .trk-tl { padding: 0 24px 24px; }
    .trk-tl h6 { font-weight: 700; color: #0f172a; margin: 6px 0 14px; }
    .trk-ev { position: relative; padding: 0 0 18px 26px; border-left: 2px solid #e2e8f0; margin-left: 6px; }
    .trk-ev:last-child { border-left-color: transparent; padding-bottom: 0; }
    .trk-ev::before { content: ''; position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #cbd5e1; border: 2px solid #fff; }
    .trk-ev:first-child::before { background: #22c55e; }
    .trk-ev .t { font-size: .8rem; color: #6366f1; font-weight: 600; }
    .trk-ev .m { color: #0f172a; }
    .trk-ev .l { font-size: .82rem; color: #64748b; }
    .trk-note { padding: 16px 24px; color: #475569; background: #fff7ed; border-top: 1px solid #fde68a; }
    @media (max-width: 576px) { .trk-step { font-size: .62rem; } .trk-head, .trk-grid, .trk-tl { padding-left: 16px; padding-right: 16px; } }
</style>

<main class="main">
    <!-- Hero Section -->
    <div class="track-hero" style="background: url('{{ asset('assets/website/img/herosection.PNG') }}') no-repeat center center; background-size: cover;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 hero-text">
                    <h1 style="color:white;">Track Your Order Anytime &amp; Anywhere</h1>
                    <p style="color:white;">Just enter your AWB Number or Order ID and track on the go!</p>
                </div>
                <div class="col-md-6 position-relative hero-image">
                    <img src="{{ asset('assets/website/img/track.png') }}" alt="Package Delivery" class="img-fluid fade-in">
                    <div class="circle one"></div>
                    <div class="circle two"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tracking Form -->
    <div class="tracking-form mt-4">
        <div class="container d-flex justify-content-center align-items-center">
            <form action="{{ route('order.details') }}" method="GET" class="input-group w-75 w-md-50">
                <input type="text" name="awb" class="form-control" placeholder="Enter AWB Number or Order ID"
                       value="{{ $query ?? old('awb', request('awb')) }}" required>
                <button type="submit" class="btn track-btn">Track</button>
            </form>
        </div>

        <div class="container mt-3 trk-wrap">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if(!empty($tracking))
        @php
            $st = $tracking['status'] ?? '';
            $cls = match ($st) {
                'delivered' => 'st-delivered',
                'transit' => 'st-transit',
                'picked up' => 'st-picked',
                'out for delivery' => 'st-ofd',
                'NDR' => 'st-ndr',
                'rto', 'rto delivered' => 'st-rto',
                'cancelled' => 'st-cancelled',
                'lost' => 'st-lost',
                default => 'st-default',
            };
            $steps = ['Booked', 'Picked Up', 'In Transit', 'Out For Delivery', 'Delivered'];
            $reached = match ($st) {
                'courier Assigned' => 0,
                'picked up' => 1,
                'transit', 'NDR' => 2,
                'out for delivery' => 3,
                'delivered' => 4,
                default => 0,
            };
        @endphp

        <div class="container mt-5 mb-5 trk-wrap">
            <div class="trk-card">
                <div class="trk-head">
                    <div>
                        <h5>Shipment Tracking</h5>
                        <span class="trk-courier">{{ $tracking['courier'] }}</span>
                    </div>
                    <span class="trk-status {{ $cls }}">{{ $tracking['status_label'] ?: 'Pending' }}</span>
                </div>

                <div class="trk-grid">
                    <div class="trk-item"><span class="trk-label">AWB Number</span><span class="trk-value">{{ $tracking['awb'] ?: '—' }}</span></div>
                    <div class="trk-item"><span class="trk-label">Order Number</span><span class="trk-value">{{ $tracking['order_number'] ?: '—' }}</span></div>
                    @if(!empty($tracking['destination']))
                        <div class="trk-item"><span class="trk-label">Destination</span><span class="trk-value">{{ $tracking['destination'] }}</span></div>
                    @endif
                    @if(!empty($tracking['edd']))
                        <div class="trk-item"><span class="trk-label">Expected Delivery</span><span class="trk-value">{{ \Illuminate\Support\Str::limit($tracking['edd'], 20, '') }}</span></div>
                    @endif
                    @if(!empty($tracking['location']))
                        <div class="trk-item"><span class="trk-label">Current Location</span><span class="trk-value">{{ $tracking['location'] }}</span></div>
                    @endif
                    @if(!empty($tracking['booked_on']))
                        <div class="trk-item"><span class="trk-label">Booked On</span><span class="trk-value">{{ $tracking['booked_on'] }}</span></div>
                    @endif
                </div>

                @if(!in_array($st, ['rto', 'rto delivered', 'cancelled', 'lost']))
                    <div class="trk-steps">
                        @foreach($steps as $i => $label)
                            <div class="trk-step {{ $tracking['ok'] && $i <= $reached ? 'done' : '' }}">
                                <div class="dot"></div>{{ $label }}
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(!empty($tracking['events']))
                    <div class="trk-tl">
                        <h6>Tracking History</h6>
                        @foreach($tracking['events'] as $e)
                            <div class="trk-ev">
                                <div class="t">{{ $e['time'] }}</div>
                                <div class="m">{{ $e['status'] }}</div>
                                @if(!empty($e['location']))
                                    <div class="l"><i class="bi bi-geo-alt"></i> {{ $e['location'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(!empty($tracking['error']))
                    <div class="trk-note">{{ $tracking['error'] }}</div>
                @elseif(empty($tracking['events']))
                    <div class="trk-note">No scan history from {{ $tracking['courier'] }} yet. It usually appears a few hours after pickup.</div>
                @endif
            </div>
        </div>
    @endif
</main>
@endsection
