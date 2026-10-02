import { request, CourierError } from './http';
import {
  UnsupportedError,
  type Address, type CourierAdapter, type LabelResult, type NormalizedStatus, type ParcelInput,
  type PaymentMode, type RateResult, type ServiceabilityResult, type ShipmentResult,
  type TrackingEvent, type TrackingResult,
} from './types';

// Built from "Bigship Unified Outbound API" v1.5. Production only (no sandbox in the docs),
// so DRY_RUN defaults to true in the registry. Lines marked VERIFY are places where the
// PDF is ambiguous; confirm with a read-only call or with support@bigship.in.
const BASE = 'https://api.bigship.direct/api/outbound/';
const PAYMENT = { Prepaid: 1, COD: 2 } as const; // 3 = ToPay (not used for B2C)

export interface BigshipConfig {
  username: string; password: string; accessKey: string;
  pickupWarehouseId: number;     // from listWarehouses() / Bigship dashboard
  returnWarehouseId?: number;    // defaults to pickup warehouse
  pickupPincode: string;         // used for serviceability and default rate checks
  categoryId?: string;           // product category id required on B2C orders
  riskTypeId?: number;           // 1 third party, 2 owner risk (default), 3 carrier risk
  invoiceType?: string;          // VERIFY: doc sample shows "uploaded"
  dryRun?: boolean;
}

interface Resp<T> { status: boolean; message?: string; status_code?: number; data: T }

export function normalizeBigship(tag: string): NormalizedStatus {
  const s = tag.toLowerCase();
  if (s.includes('cancel')) return 'cancelled';
  if (s.includes('lost')) return 'lost';
  if (s.includes('damage')) return 'damaged';
  if (s.includes('rto')) return s.includes('deliver') ? 'rto_delivered' : 'rto_initiated';
  if (s.includes('out for delivery')) return 'out_for_delivery';
  if (s.includes('delivered')) return 'delivered';
  if (s.includes('ndr') || s.includes('undelivered') || s.includes('attempt')) return 'ndr';
  if (s.includes('picked')) return 'picked_up';
  if (s.includes('manifest') || s.includes('created') || s.includes('placed')) return 'created';
  if (s.includes('pickup') || s.includes('assigned')) return 'pickup_scheduled';
  return 'in_transit';
}

const n = (v: unknown) => Number(v ?? 0) || 0;
const utcNow = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

export class BigshipAdapter implements CourierAdapter {
  readonly code = 'bigship';
  private token?: { value: string; exp: number };

  constructor(private cfg: BigshipConfig) {
    if (!cfg.username || !cfg.password || !cfg.accessKey) throw new Error('Bigship credentials are missing');
  }

  // Bearer token from /login, cached until shortly before it expires, refreshed on a 401.
  private async getToken(force = false): Promise<string> {
    if (!force && this.token && this.token.exp > Date.now() + 60_000) return this.token.value;
    const res = await request(BASE + 'login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, retries: 1,
      body: JSON.stringify({ username: this.cfg.username, password: this.cfg.password, access_key: this.cfg.accessKey }),
    });
    const d = (await res.json()) as Resp<any>;
    if (!d?.status || !d?.data?.token) throw new CourierError(401, d?.message ?? 'Bigship login failed', false);
    this.token = { value: d.data.token, exp: Date.parse(d.data.tokenExpiringAt) || Date.now() + 3_600_000 };
    return this.token.value;
  }

  private async call<T>(path: string, o: { method?: string; query?: Record<string, string>; json?: unknown; form?: FormData; retries?: number } = {}): Promise<T> {
    for (let pass = 0; pass < 2; pass++) {
      const headers: Record<string, string> = { Authorization: `Bearer ${await this.getToken(pass === 1)}`, Accept: 'application/json' };
      if (o.json) headers['Content-Type'] = 'application/json';
      // VERIFY: the PDF lists a JSON body for some GETs, but fetch() cannot send a GET body.
      // Query parameters are used instead; if Bigship ignores them, send these via node:https.
      const url = BASE + path + (o.query ? '?' + new URLSearchParams(o.query) : '');
      try {
        const res = await request(url, { method: o.method ?? 'GET', headers, body: o.json ? JSON.stringify(o.json) : o.form, retries: o.retries });
        const d = (await res.json()) as Resp<T>;
        if (d.status === false) throw new CourierError(d.status_code ?? 422, d.message ?? 'Bigship rejected the request', false, d);
        return d.data;
      } catch (e) {
        if (e instanceof CourierError && e.status === 401 && pass === 0) continue;
        throw e;
      }
    }
    throw new CourierError(401, 'Bigship authentication failed', false);
  }

  // Helpers for setup: wallet balance and the warehouse ids needed in config.
  getProfile() { return this.call<any>('profile'); }
  listWarehouses(segment: 'local' | 'hyperlocal' = 'local') {
    // VERIFY: segment value for domestic pickups; perPage must be 10 to 25 in multiples of 5.
    return this.call<any>('get-warehouse-list', { query: { page: '1', perPage: '25', segment_type: segment } });
  }

  // Bigship has no serviceability endpoint; a route is serviceable if the rate calculator returns carriers.
  async serviceability(pincode: string): Promise<ServiceabilityResult> {
    const [pre, cod] = await Promise.all([
      this.calculateRates(this.cfg.pickupPincode, pincode, 500, 'Prepaid').catch(() => []),
      this.calculateRates(this.cfg.pickupPincode, pincode, 500, 'COD', 500).catch(() => []),
    ]);
    return { serviceable: pre.length > 0 || cod.length > 0, prepaid: pre.length > 0, cod: cod.length > 0, reverse: false, pickup: true, raw: { prepaid: pre.length, cod: cod.length } };
  }

  async calculateRates(fromPin: string, toPin: string, weightGrams: number, mode: PaymentMode, codAmount = 0, dims = { l: 10, b: 10, h: 10 }): Promise<RateResult[]> {
    const invoiceValue = Math.max(500, codAmount + 1); // codAmount must be below the invoice value
    const rows = await this.call<any[]>('user-rate-calculator', {
      method: 'POST', retries: 2,
      json: {
        segment_type: 'domestic_b2c', sourcePincode: fromPin, destPincode: toPin, invoiceValue,
        paymentModeId: PAYMENT[mode], ...(mode === 'COD' ? { codAmount } : {}), riskTypeId: this.cfg.riskTypeId ?? 2,
        // VERIFY: box_dead_weight is an integer in the PDF; assumed kg, rounded up.
        boxes: [{ no_of_box: 1, box_length: dims.l, box_width: dims.b, box_height: dims.h, box_dead_weight: Math.max(1, Math.ceil(weightGrams / 1000)) }],
      },
    });
    return (rows ?? []).map(r => {
      const parts = ['courierCharge', 'codCharges', 'riskType', 'lrCost', 'handlingCharge', 'greenTax', 'toPay', 'oda', 'warai_Charge', 'state_Tax', 'pickup_Charge',
        'whatsappNotificationCharge', 'emailNotificationCharge', 'smsNotificationCharge'].reduce((s, k) => s + n(r[k]), 0);
      const total = n(r.totalCharge);
      // VERIFY: whether totalCharge already includes GST; gst here is only any amount above the listed charges.
      return {
        courier: this.code, courierId: String(r.courier_partner_id), serviceName: r.courierName, tatDays: n(r.tat),
        freight: n(r.courierCharge), codCharge: n(r.codCharges), gst: Math.max(0, total - parts), total, raw: r,
      };
    }).sort((a, b) => a.total - b.total);
  }

  async calculateRate(fromPin: string, toPin: string, weightGrams: number, mode: PaymentMode, codAmount = 0, dims?: { l: number; b: number; h: number }): Promise<RateResult> {
    const rates = await this.calculateRates(fromPin, toPin, weightGrams, mode, codAmount, dims);
    if (!rates.length) throw new CourierError(404, 'No Bigship courier serves this route', false);
    return rates[0];
  }

  // Bigship is three calls: draft order -> carrier quotes -> place order (assigns the AWB).
  async createShipment(to: Address, p: ParcelInput, opts: { courierId?: string } = {}): Promise<ShipmentResult> {
    const cod = p.paymentMode === 'COD';
    const draft = {
      segment_type: 'domestic_b2c',
      MasterOrderPickUpLocation: this.cfg.pickupWarehouseId,
      MasterOrderReturnLocation: this.cfg.returnWarehouseId ?? this.cfg.pickupWarehouseId,
      MasterOrderDate: utcNow(), MasterOrderPaymentMode: PAYMENT[p.paymentMode],
      OrderInvoiceNo: p.orderId, MasterOrderInvoiceAmount: p.declaredValue,
      MasterOrderShippingName: to.name, MasterOrderShippingMobileNo: to.phone, MasterOrderShippingEmail: to.email,
      MasterOrderShippingAddress: to.line1, MasterOrderShippingZipCode: to.pincode,
      MasterOrderShippingCity: to.city, MasterOrderShippingState: to.state, MasterOrderShippingCountry: to.country ?? 'India',
      totalNumOfBoxes: 1,
      boxes: [{
        weight_unit: 'kg', dimension_unit: 'cm', noOfBoxes: 1,
        dimensions: [{ length: p.lengthCm, breadth: p.breadthCm, height: p.heightCm, weight: Math.max(1, Math.ceil(p.weightGrams / 1000)) }],
        products: [{
          productName: p.productDescription, qty: p.quantity, amount: Math.round(p.declaredValue / p.quantity),
          totalAmount: p.declaredValue, collectableAmount: cod ? p.codAmount ?? p.declaredValue : 0,
          categoryId: this.cfg.categoryId ?? '1', // VERIFY category ids with Bigship
        }],
      }],
    };
    if (this.cfg.dryRun) return { awb: `DRYRUN-${p.orderId}`, courierRef: `DRYRUN-${p.orderId}`, raw: { dryRun: true, draft } };

    const created = await this.call<{ CustomGlobalOrderId: string }>('create-order', { method: 'POST', json: draft, retries: 0 });
    const orderId = String(created.CustomGlobalOrderId);
    try {
      const quotes = await this.call<{ calculatedRates: any[] }>('courier-wise-shipment-cost', { method: 'POST', json: { MasterCustomOrderId: orderId }, retries: 1 });
      const list = quotes.calculatedRates ?? [];
      const pick = opts.courierId ? list.find(r => String(r.courierId) === String(opts.courierId)) : [...list].sort((a, b) => n(a.total) - n(b.total))[0];
      if (!pick) throw new CourierError(422, 'Selected courier is not available for this order', false, { quotes: list.length });
      const form = new FormData(); // domestic place-order must be multipart/form-data
      form.set('MasterCustomOrderId', orderId); form.set('courierId', String(pick.courierId));
      form.set('invoiceType', this.cfg.invoiceType ?? 'uploaded'); form.set('riskTypeId', String(this.cfg.riskTypeId ?? 2));
      const placed = await this.call<{ reference_number: string; awb_assigned: string }>('place-order', { method: 'POST', form, retries: 0 });
      return { awb: String(placed.awb_assigned), courierRef: orderId, raw: { placed, picked: pick } };
    } catch (e) {
      // The draft exists at Bigship; surface its id so the job queue can resume or cancel it.
      if (e instanceof CourierError) throw new CourierError(e.status, e.message, e.retryable, { draftOrderId: orderId, detail: e.body });
      throw e;
    }
  }

  async cancelShipment(awb: string, courierRef?: string): Promise<void> {
    if (!courierRef) throw new Error('Bigship cancel needs courierRef (the CustomGlobalOrderId saved at creation)');
    if (this.cfg.dryRun) return;
    // The PDF names this key two ways (MasterCustomOrderId / CustomGlobalOrderId); send both.
    await this.call('cancel-order', { method: 'POST', json: { CustomGlobalOrderId: courierRef, MasterCustomOrderId: courierRef }, retries: 0 });
  }

  async generateLabel(awb: string, _size?: 'A4' | '4R', courierRef?: string): Promise<LabelResult> {
    if (!courierRef) throw new Error('Bigship labels need courierRef (the CustomGlobalOrderId saved at creation)');
    const d = await this.call<{ AttachmentData?: string }>('download-shipment-documents', { query: { CustomGlobalOrderId: courierRef, document_type: 'label' } });
    return { awb, url: d?.AttachmentData, raw: d };
  }

  async generateManifest(): Promise<never> {
    throw new UnsupportedError(this.code, 'bulk manifests (Shipxpeed generates its own)');
  }

  async trackShipment(awb: string): Promise<TrackingResult> {
    const d = await this.call<any>('track-order', { query: { TrackingNumber: awb } });
    const cps: any[] = d?.checkpoints ?? d?.tracking_histories ?? [];
    const events: TrackingEvent[] = cps.map(c => ({
      status: normalizeBigship(`${c.tag ?? ''} ${c.order_status ?? ''}`), rawStatus: c.order_status ?? c.tag ?? '',
      location: typeof c.location === 'string' ? c.location : undefined, remarks: c.message, at: c.checkpoint_time,
    }));
    return { awb, status: normalizeBigship(`${d?.tag ?? ''} ${d?.order_status ?? ''}`), events, raw: d };
  }

  // Pickup is scheduled automatically when the order is placed; there is no pickup endpoint.
  async createPickup(): Promise<never> {
    throw new UnsupportedError(this.code, 'separate pickup booking (placed orders are picked up automatically)');
  }

  async getNDR(awb: string): Promise<TrackingEvent[]> {
    return (await this.trackShipment(awb)).events.filter(e => e.status === 'ndr');
  }

  async getCOD(): Promise<never> {
    throw new UnsupportedError(this.code, 'COD remittance (reconcile from Bigship wallet and reports)');
  }
}
