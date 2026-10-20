@extends('layouts.app')

@section('css')
<style>
    .rsm-note{background:#eef6ff;border:1px solid #cfe4ff;color:#1b4b7a;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px}
    .rsm-courier{border:1px solid #e3e6ef;border-radius:10px;margin-bottom:16px;overflow:hidden;background:#fff}
    .rsm-courier-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:#f7f9fc;border-bottom:1px solid #eef0f6}
    .rsm-courier-title{font-weight:700;font-size:15px;color:#2b2f3a;word-break:break-all}
    .rsm-grid{width:100%;border-collapse:collapse}
    .rsm-grid th,.rsm-grid td{border:1px solid #eef0f6;padding:6px 8px;text-align:center;font-size:13px}
    .rsm-grid th{background:#fbfcfe;color:#6b7280;font-weight:600;white-space:nowrap}
    .rsm-grid td.zone{font-weight:700;background:#fafbfe}
    .rsm-grid input{width:92px;padding:6px 8px;border:1px solid #dfe3ec;border-radius:6px;text-align:right}
    .rsm-grid input:focus{outline:none;border-color:#2b7fff;box-shadow:0 0 0 2px rgba(43,127,255,.15)}
    /* toggle */
    .rsm-switch{position:relative;display:inline-block;width:52px;height:28px}
    .rsm-switch input{display:none}
    .rsm-slider{position:absolute;cursor:pointer;inset:0;background:#cbd2dd;border-radius:999px;transition:.2s}
    .rsm-slider:before{content:"";position:absolute;height:22px;width:22px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
    .rsm-switch input:checked + .rsm-slider{background:#16a34a}
    .rsm-switch input:checked + .rsm-slider:before{transform:translateX(24px)}
    .rsm-status{font-size:12px;font-weight:700;min-width:62px;text-align:left}
    .rsm-status.on{color:#16a34a}.rsm-status.off{color:#9aa1ad}
    .rsm-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:10px 0 18px}
    .rsm-search{padding:9px 12px;border:1px solid #dfe3ec;border-radius:8px;min-width:220px}
    .rsm-save{background:#2b7fff;color:#fff;border:none;padding:11px 22px;border-radius:8px;font-weight:700;cursor:pointer}
    .rsm-save:disabled{opacity:.5;cursor:not-allowed}
    .rsm-muted{color:#9aa1ad;font-size:13px}
    .rsm-scroll{overflow-x:auto}
</style>
@endsection

@section('content')
<div class="card mb-3">
    <div class="card-header">
        <h4 class="mb-0">Rate Sync Manager</h4>
        <small class="rsm-muted">Set zone-wise rates &amp; active couriers for a client. Saves go live to the rate card, rate calculator and order shipping.</small>
    </div>
    <div class="card-body">

        <div class="rsm-note">
            <strong>How this works:</strong> whatever you save here writes directly to the live rate tables
            (<code>zone_price_Settings</code> + <code>zone_price</code>). It appears instantly in the client panel,
            the rate card, the rate calculator and while shipping an order &mdash; no separate sync needed.
        </div>

        {{-- Step 1: pick client --}}
        <div class="form-group">
            <label><strong>Step 1 &middot; Select Client (Seller)</strong></label>
            <select id="rsm-seller" class="form-control" style="max-width:420px">
                <option value="">-- Select Client --</option>
                @foreach($SellerList as $seller)
                    <option value="{{ $seller->id }}">
                        {{ $seller->name ?? $seller->seller_name ?? ('Seller #'.$seller->id) }}
                        @if(!empty($seller->email)) ({{ $seller->email }}) @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div id="rsm-body" style="display:none">
            <div class="rsm-toolbar">
                <input type="text" id="rsm-search" class="rsm-search" placeholder="Filter couriers…">
                <div>
                    <span id="rsm-count" class="rsm-muted"></span>
                    <button id="rsm-save" class="rsm-save" type="button">Save &amp; Sync Live</button>
                </div>
            </div>
            <div id="rsm-couriers"></div>
            <div class="rsm-toolbar" style="justify-content:flex-end">
                <button id="rsm-save-2" class="rsm-save" type="button">Save &amp; Sync Live</button>
            </div>
        </div>

        <div id="rsm-empty" class="rsm-muted" style="padding:30px 0">Select a client above to load and edit their rates.</div>

    </div>
</div>
@endsection

@section('js')
<script>
(function(){
    const RATES_URL = "{{ route('rate.sync.manager.rates') }}";
    const SAVE_URL  = "{{ route('rate.sync.manager.save') }}";
    const CSRF      = "{{ csrf_token() }}";
    const FIELDS = [
        {k:'prepaid_price',      label:'Prepaid ₹'},
        {k:'prepaid_fix_price',  label:'Prepaid Fixed'},
        {k:'cod_price',          label:'COD ₹'},
        {k:'cod_fix_price',      label:'COD Fixed'},
        {k:'cod_charge_parsent', label:'COD %'},
        {k:'rto_credit',         label:'RTO Credit'},
    ];
    let ZONES = @json($zones);

    function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function val(courier, zone, key){
        if(courier.zones && courier.zones[zone] && courier.zones[zone][key] != null) return courier.zones[zone][key];
        return '';
    }

    function courierCard(c){
        let head = ''
          + '<div class="rsm-courier-head">'
          +   '<span class="rsm-courier-title">'+esc(c.code)+'</span>'
          +   '<label style="display:flex;align-items:center;gap:10px;margin:0;">'
          +     '<span class="rsm-status '+(c.active?'on':'off')+'">'+(c.active?'ACTIVE':'INACTIVE')+'</span>'
          +     '<span class="rsm-switch"><input type="checkbox" class="rsm-active" '+(c.active?'checked':'')+'><span class="rsm-slider"></span></span>'
          +   '</label>'
          + '</div>';

        let header = '<tr><th>Zone</th>' + FIELDS.map(f=>'<th>'+f.label+'</th>').join('') + '</tr>';
        let rows = ZONES.map(function(z){
            let tds = FIELDS.map(function(f){
                let v = val(c, z, f.k);
                return '<td><input type="number" step="0.01" min="0" data-zone="'+z+'" data-key="'+f.k+'" value="'+esc(v)+'" placeholder="0"></td>';
            }).join('');
            return '<tr><td class="zone">'+z+'</td>'+tds+'</tr>';
        }).join('');

        return '<div class="rsm-courier" data-code="'+esc(c.code)+'">'
             + head
             + '<div class="rsm-scroll"><table class="rsm-grid">'+header+rows+'</table></div>'
             + '</div>';
    }

    function bindToggles(){
        document.querySelectorAll('.rsm-active').forEach(function(cb){
            cb.addEventListener('change', function(){
                const s = cb.closest('.rsm-courier-head').querySelector('.rsm-status');
                s.textContent = cb.checked ? 'ACTIVE' : 'INACTIVE';
                s.className = 'rsm-status ' + (cb.checked ? 'on' : 'off');
            });
        });
    }

    function load(sellerId){
        document.getElementById('rsm-empty').textContent = 'Loading…';
        document.getElementById('rsm-body').style.display = 'none';
        fetch(RATES_URL + '?seller_id=' + encodeURIComponent(sellerId), {headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
          .then(r=>r.json())
          .then(function(res){
              if(!res.success){ throw new Error('load failed'); }
              ZONES = res.zones || ZONES;
              document.getElementById('rsm-couriers').innerHTML = res.couriers.map(courierCard).join('');
              document.getElementById('rsm-count').textContent = res.couriers.length + ' couriers';
              document.getElementById('rsm-empty').style.display = 'none';
              document.getElementById('rsm-body').style.display = 'block';
              bindToggles();
          })
          .catch(function(){
              document.getElementById('rsm-empty').textContent = 'Could not load rates. Please try again.';
              document.getElementById('rsm-empty').style.display = 'block';
          });
    }

    function collect(){
        const sellerId = document.getElementById('rsm-seller').value;
        const couriers = {};
        document.querySelectorAll('.rsm-courier').forEach(function(card){
            const code = card.getAttribute('data-code');
            const active = card.querySelector('.rsm-active').checked ? 1 : 0;
            const zones = {};
            card.querySelectorAll('input[type=number]').forEach(function(inp){
                const z = inp.getAttribute('data-zone');
                const k = inp.getAttribute('data-key');
                if(!zones[z]) zones[z] = {};
                zones[z][k] = inp.value;
            });
            couriers[code] = {active:active, zones:zones};
        });
        return {seller_id:sellerId, couriers:couriers};
    }

    function save(){
        const payload = collect();
        if(!payload.seller_id){ return; }
        document.querySelectorAll('.rsm-save').forEach(b=>{b.disabled=true;b.textContent='Saving…';});
        fetch(SAVE_URL, {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
            credentials:'same-origin',
            body: JSON.stringify(payload)
        })
        .then(r=>r.json())
        .then(function(res){
            if(typeof toastr !== 'undefined'){ res.success ? toastr.success(res.message) : toastr.error(res.message||'Save failed'); }
            else { alert(res.message || (res.success?'Saved':'Save failed')); }
        })
        .catch(function(){ if(typeof toastr!=='undefined'){toastr.error('Save failed');} else {alert('Save failed');} })
        .finally(function(){ document.querySelectorAll('.rsm-save').forEach(b=>{b.disabled=false;b.textContent='Save & Sync Live';}); });
    }

    document.getElementById('rsm-seller').addEventListener('change', function(){
        const v = this.value;
        if(v){ load(v); }
        else { document.getElementById('rsm-body').style.display='none'; document.getElementById('rsm-empty').style.display='block'; document.getElementById('rsm-empty').textContent='Select a client above to load and edit their rates.'; }
    });

    document.getElementById('rsm-save').addEventListener('click', save);
    document.getElementById('rsm-save-2').addEventListener('click', save);

    document.getElementById('rsm-search').addEventListener('input', function(){
        const q = this.value.toLowerCase();
        document.querySelectorAll('.rsm-courier').forEach(function(card){
            const code = card.getAttribute('data-code').toLowerCase();
            card.style.display = code.indexOf(q) > -1 ? '' : 'none';
        });
    });
})();
</script>
@endsection
