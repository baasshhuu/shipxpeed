import { request, CourierError } from './http';
import {
  UnsupportedError,
  type Address, type CourierAdapter, type LabelResult, type NormalizedStatus, type ParcelInput,
  type PaymentMode, type RateResult, type ServiceabilityResult, type ShipmentResult,
  type TrackingEvent, type TrackingResult,
} from './types';

// Endpoints follow Delhivery's Express API. Lines marked VERIFY must be checked
// against the request/response samples in your Delhivery One developer portal
// before going live; response field names are read defensively.
const BASES = { staging: 'https://staging-express.delhivery.com', production: 'https://track.delhivery.com' };

export interface DelhiveryConfig {
  token: string;
  env?: 'staging' | 'production';
  pickupLocation: string; // must match your registered Delhivery warehouse name exactly (case-sensitive)
  sellerGstTin?: string;  // mandatory on shipment creation per Delhivery's order-creation docs
  hsnCode?: string;       // mandatory on shipment creation per Delhivery's order-creation docs
  dryRun?: boolean;       // true = never create/cancel/book anything, only read
  baseUrl?: string;       // override for tests only (a local fake Delhivery server)
}

export function normalizeStatus(status: string, type = ''): NormalizedStatus {
  const s = status.toLowerCase();
  if (s.includes('cancel')) return 'cancelled';
  if (s.includes('lost')) return 'lost';
  if (s.includes('damage')) return 'damaged';
  if (type === 'RT' || s.includes('rto') || s.includes('return')) return s.includes('deliver') ? 'rto_delivered' : 'rto_initiated';
  if (s.includes('out for delivery') || s === 'dispatched') return 'out_for_delivery';
  if (type === 'DL' || s.includes('delivered')) return 'delivered';
  if (s.includes('pending') || s.includes('undelivered') || s.includes('not delivered')) return 'ndr';
  if (s.includes('picked up') || s.includes('pickup done')) return 'picked_up';
  if (s.includes('manifest')) return 'created';
  if (s.includes('pickup')) return 'pickup_scheduled';
  return 'in_transit';
}

export class DelhiveryAdapter implements CourierAdapter {
  readonly code = 'delhivery';
  constructor(private cfg: DelhiveryConfig) {
    if (!cfg.token) throw new Error('DELHIVERY_API_TOKEN is missing');
  }

  private get base() { return this.cfg.baseUrl ?? BASES[this.cfg.env ?? 'staging']; }
  get isDryRun() { return !!this.cfg.dryRun; }

  private async call<T>(path: string, init: Parameters<typeof request>[1] = {}): Promise<T> {
    const headers = { Authorization: `Token ${this.cfg.token}`, Accept: 'application/json', ...(init.headers as object) };
    const res = await request(this.base + path, { ...init, headers });
    return (await res.json()) as T;
  }

  async serviceability(pincode: string): Promise<ServiceabilityResult> {
    const d = await this.call<any>(`/c/api/pin-codes/json/?filter_codes=${encodeURIComponent(pincode)}`);
    const p = d?.delivery_codes?.[0]?.postal_code;
    const y = (v: unknown) => v === 'Y';
    if (!p) return { serviceable: false, cod: false, prepaid: false, reverse: false, pickup: false, raw: d };
    // VERIFY: field names pre_paid / cod / pickup / repl
    return { serviceable: y(p.pre_paid) || y(p.cod), cod: y(p.cod), prepaid: y(p.pre_paid), reverse: y(p.repl), pickup: y(p.pickup), raw: d };
  }

  async calculateRate(fromPin: string, toPin: string, weightGrams: number, mode: PaymentMode, codAmount = 0): Promise<RateResult> {
    const q = new URLSearchParams({
      md: 'S', ss: 'Delivered', o_pin: fromPin, d_pin: toPin, cgm: String(weightGrams),
      pt: mode === 'COD' ? 'COD' : 'Pre-paid', cod: String(mode === 'COD' ? codAmount : 0),
    });
    // VERIFY: charges endpoint and response fields
    const d = await this.call<any>(`/api/kinko/v1/invoice/charges/.json?${q}`);
    const r = Array.isArray(d) ? d[0] : d;
    const freight = Number(r?.charge_DL ?? r?.gross_amount ?? 0);
    const codCharge = Number(r?.charge_COD ?? 0);
    const total = Number(r?.total_amount ?? freight + codCharge);
    return { courier: this.code, freight, codCharge, gst: Math.max(0, total - freight - codCharge), total, raw: d };
  }

  async createShipment(to: Address, p: ParcelInput): Promise<ShipmentResult> {
    if (!this.cfg.dryRun && (!this.cfg.sellerGstTin || !this.cfg.hsnCode || !this.cfg.pickupLocation)) {
      throw new CourierError(400, 'Delhivery needs DELHIVERY_SELLER_GST_TIN, DELHIVERY_HSN_CODE and DELHIVERY_PICKUP_LOCATION to create shipments', false);
    }
    const shipment = {
      name: to.name, add: to.line1, pin: to.pincode, city: to.city, state: to.state, country: to.country ?? 'India',
      phone: to.phone,
      // Public docs name the field order_id; older integrations use order. VERIFY against the portal sample, then keep one.
      order: p.orderId, order_id: p.orderId,
      seller_gst_tin: this.cfg.sellerGstTin, hsn_code: this.cfg.hsnCode, payment_mode: p.paymentMode,
      cod_amount: p.paymentMode === 'COD' ? p.codAmount ?? p.declaredValue : 0,
      total_amount: p.declaredValue, products_desc: p.productDescription, quantity: p.quantity,
      weight: p.weightGrams, shipment_length: p.lengthCm, shipment_width: p.breadthCm, shipment_height: p.heightCm,
    };
    if (this.cfg.dryRun) return { awb: `DRYRUN-${p.orderId}`, raw: { dryRun: true, shipment } };
    const body = new URLSearchParams({
      format: 'json',
      data: JSON.stringify({ shipments: [shipment], pickup_location: { name: this.cfg.pickupLocation } }),
    });
    const d = await this.call<any>('/api/cmu/create.json', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, retries: 0,
    });
    const pkg = d?.packages?.[0];
    if (!d?.success || !pkg?.waybill) {
      const why = Array.isArray(pkg?.remarks) ? pkg.remarks.join(' ') : d?.rmk ?? 'Delhivery rejected the shipment';
      throw new CourierError(422, why, false, d);
    }
    return { awb: pkg.waybill, courierRef: pkg.refnum, raw: d };
  }

  async cancelShipment(awb: string): Promise<void> {
    if (this.cfg.dryRun) return;
    await this.call('/api/p/edit', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ waybill: awb, cancellation: 'true' }), retries: 0,
    });
  }

  async generateLabel(awb: string, size: 'A4' | '4R' = 'A4'): Promise<LabelResult> {
    // VERIFY: packing slip response shape. Shipxpeed-branded labels are rendered
    // by our own PDF generator from the AWB and order data.
    const d = await this.call<any>(`/api/p/packing_slip?wbns=${encodeURIComponent(awb)}&pdf=true&pdf_size=${size}`);
    return { awb, url: d?.packages?.[0]?.pdf_download_link ?? d?.pdf_download_link, raw: d };
  }

  async generateManifest(): Promise<never> {
    throw new UnsupportedError(this.code, 'manifest generation (Shipxpeed generates manifests itself)');
  }

  async trackShipment(awb: string): Promise<TrackingResult> {
    const d = await this.call<any>(`/api/v1/packages/json/?waybill=${encodeURIComponent(awb)}`);
    const sh = d?.ShipmentData?.[0]?.Shipment;
    if (!sh) throw new CourierError(404, `No tracking data for ${awb}`, false, d);
    const events: TrackingEvent[] = (sh.Scans ?? []).map((s: any) => {
      const x = s.ScanDetail ?? {};
      return {
        status: normalizeStatus(`${x.Scan ?? ''} ${x.Instructions ?? ''}`, x.ScanType),
        rawStatus: x.Scan ?? '', location: x.ScannedLocation, remarks: x.Instructions, at: x.ScanDateTime,
      };
    });
    return { awb, status: normalizeStatus(sh.Status?.Status ?? '', sh.Status?.StatusType), events, raw: d };
  }

  async createPickup(date: string, time: string, packageCount: number) {
    if (this.cfg.dryRun) return { dryRun: true, date, time, packageCount };
    return this.call('/fm/request/new/', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, retries: 0,
      body: JSON.stringify({ pickup_location: this.cfg.pickupLocation, pickup_date: date, pickup_time: time, expected_package_count: packageCount }),
    });
  }

  // NDR events come from tracking scans. For live NDR alerts use Delhivery's
  // tracking webhook (next step), not polling.
  async getNDR(awb: string): Promise<TrackingEvent[]> {
    return (await this.trackShipment(awb)).events.filter(e => e.status === 'ndr');
  }

  async getCOD(): Promise<never> {
    throw new UnsupportedError(this.code, 'COD remittance (reconcile from Delhivery finance reports)');
  }
}
