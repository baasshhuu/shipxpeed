// Every courier adapter implements CourierAdapter. Order management never
// imports a specific courier, so adding one = one new file + one registry line.

export type PaymentMode = 'Prepaid' | 'COD';

export interface Address {
  name: string; phone: string; email?: string;
  line1: string; city: string; state: string; pincode: string; country?: string;
}

export interface ParcelInput {
  orderId: string; paymentMode: PaymentMode; codAmount?: number; declaredValue: number;
  weightGrams: number; lengthCm: number; breadthCm: number; heightCm: number;
  productDescription: string; quantity: number;
}

export interface ServiceabilityResult {
  serviceable: boolean; cod: boolean; prepaid: boolean; reverse: boolean; pickup: boolean; raw?: unknown;
}

// COURIER COST only. The pricing engine adds platform margin to get the seller
// price; never send this object to the seller UI.
export interface RateResult {
  courier: string; freight: number; codCharge: number; gst: number; total: number; raw?: unknown;
  // Set when the adapter fronts several carriers (e.g. Bigship): which one this quote is for.
  courierId?: string; serviceName?: string; tatDays?: number;
}

export interface ShipmentResult { awb: string; courierRef?: string; raw: unknown }
export interface LabelResult { awb: string; url?: string; raw: unknown }

export type NormalizedStatus =
  | 'created' | 'pickup_scheduled' | 'picked_up' | 'in_transit' | 'out_for_delivery'
  | 'delivered' | 'ndr' | 'rto_initiated' | 'rto_delivered' | 'cancelled' | 'lost' | 'damaged';

export interface TrackingEvent {
  status: NormalizedStatus; rawStatus: string; location?: string; remarks?: string; at: string;
}
export interface TrackingResult { awb: string; status: NormalizedStatus; events: TrackingEvent[]; raw?: unknown }

export class UnsupportedError extends Error {
  constructor(courier: string, op: string) { super(`${courier} does not support ${op} via API`); }
}

export interface CourierAdapter {
  readonly code: string;
  serviceability(pincode: string): Promise<ServiceabilityResult>;
  calculateRate(fromPin: string, toPin: string, weightGrams: number, mode: PaymentMode, codAmount?: number, dims?: { l: number; b: number; h: number }): Promise<RateResult>;
  // Optional: couriers that return several carrier quotes at once.
  calculateRates?(fromPin: string, toPin: string, weightGrams: number, mode: PaymentMode, codAmount?: number, dims?: { l: number; b: number; h: number }): Promise<RateResult[]>;
  // opts.courierId picks a specific carrier on aggregator couriers; otherwise the cheapest is used.
  createShipment(to: Address, parcel: ParcelInput, opts?: { courierId?: string }): Promise<ShipmentResult>;
  // courierRef is ShipmentResult.courierRef, saved at creation. Delhivery needs only the AWB;
  // Bigship needs its order id (CustomGlobalOrderId) for cancel and documents.
  cancelShipment(awb: string, courierRef?: string): Promise<void>;
  generateLabel(awb: string, size?: 'A4' | '4R', courierRef?: string): Promise<LabelResult>;
  generateManifest(awbs: string[]): Promise<never>;
  trackShipment(awb: string): Promise<TrackingResult>;
  createPickup(date: string, time: string, packageCount: number): Promise<unknown>;
  getNDR(awb: string): Promise<TrackingEvent[]>;
  getCOD(awb: string): Promise<never>;
}
